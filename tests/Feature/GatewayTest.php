<?php

use App\Models\GatewayToken;
use App\Models\User;
use App\Services\TokenBudget;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.anthropic.gateway.enabled' => true,
        'services.anthropic.gateway.token_prefix' => 'aime',
        'services.anthropic.key' => 'central-key',
        'services.anthropic.base_url' => 'https://api.anthropic.com',
        'usage.enabled' => true,
        'usage.token_limit' => 1_000_000,
    ]);
});

/** Issue a token for a user and return its plaintext. */
function issueToken(User $user): string
{
    [, $plaintext] = GatewayToken::issue($user, 'test');

    return $plaintext;
}

$messageBody = [
    'id' => 'msg_1',
    'type' => 'message',
    'role' => 'assistant',
    'content' => [['type' => 'text', 'text' => 'hi']],
    'model' => 'claude-opus-4-8',
    'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
];

// --- Auth -----------------------------------------------------------------------

test('the gateway 404s when disabled', function () {
    config(['services.anthropic.gateway.enabled' => false]);

    $this->postJson('/llm/v1/messages', [])->assertNotFound();
});

test('a missing or bad token is rejected with an Anthropic-style error', function () {
    $this->postJson('/llm/v1/messages', [])
        ->assertStatus(401)
        ->assertJsonPath('error.type', 'authentication_error');

    $this->withToken('aime_not-a-real-token')
        ->postJson('/llm/v1/messages', [])
        ->assertStatus(401);
});

test('a revoked token stops working', function () {
    $user = User::factory()->create();
    $plaintext = issueToken($user);
    GatewayToken::findActive($plaintext)->forceFill(['revoked_at' => now()])->save();

    $this->withToken($plaintext)
        ->postJson('/llm/v1/messages', ['model' => 'claude-opus-4-8', 'messages' => []])
        ->assertStatus(401);
});

// --- Forwarding + model pin -----------------------------------------------------

test('it forwards to Anthropic with the central key and records usage', function () use ($messageBody) {
    Http::fake(['*/v1/messages' => Http::response($messageBody, 200)]);

    $user = User::factory()->create();
    $plaintext = issueToken($user);

    $this->withToken($plaintext)
        ->postJson('/llm/v1/messages', [
            'model' => 'claude-opus-4-8',
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ])
        ->assertOk()
        ->assertJsonPath('id', 'msg_1');

    Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'central-key')
        && str_contains($request->url(), 'api.anthropic.com/v1/messages'));

    // input(100) + output(40) charged to the user's budget.
    expect($user->fresh()->token_budget_used)->toBe(140);
});

test('a pinned user is forced onto their assigned model', function () use ($messageBody) {
    Http::fake(['*/v1/messages' => Http::response($messageBody, 200)]);

    $user = User::factory()->create(['assigned_model' => 'claude-haiku-4-5']);
    $plaintext = issueToken($user);

    $this->withToken($plaintext)
        ->postJson('/llm/v1/messages', [
            'model' => 'claude-opus-4-8', // client asked for Opus...
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ])
        ->assertOk();

    // ...but the gateway rewrote it to their pinned model.
    Http::assertSent(fn ($request) => $request['model'] === 'claude-haiku-4-5');
});

test('an over-budget user is blocked with a 429 before any upstream call', function () {
    Http::fake();

    $user = User::factory()->create(['token_limit' => 100]);
    app(TokenBudget::class)->record($user, 100);
    $plaintext = issueToken($user);

    $this->withToken($plaintext)
        ->postJson('/llm/v1/messages', ['model' => 'claude-opus-4-8', 'messages' => []])
        ->assertStatus(429)
        ->assertJsonPath('error.type', 'rate_limit_error');

    Http::assertNothingSent();
});

test('count_tokens forwards but does not touch the budget', function () {
    Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 25], 200)]);

    $user = User::factory()->create();
    $plaintext = issueToken($user);

    $this->withToken($plaintext)
        ->postJson('/llm/v1/messages/count_tokens', [
            'model' => 'claude-opus-4-8',
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ])
        ->assertOk()
        ->assertJsonPath('input_tokens', 25);

    expect($user->fresh()->token_budget_used)->toBe(0);
});

// --- Token management (settings) ------------------------------------------------

test('a user can create and revoke their own gateway tokens', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/developer-access/tokens', ['name' => 'Laptop'])
        ->assertRedirect()
        ->assertSessionHas('gatewayToken');

    $token = $user->gatewayTokens()->first();
    expect($token)->not->toBeNull()
        ->and($token->name)->toBe('Laptop')
        ->and($token->last_four)->not->toBeNull();

    // Only a hash is stored — never the plaintext.
    expect($token->token_hash)->toHaveLength(64);

    $this->actingAs($user)
        ->delete("/settings/developer-access/tokens/{$token->id}")
        ->assertRedirect();

    expect($token->fresh()->revoked_at)->not->toBeNull();
});

test('a user cannot revoke someone else\'s token', function () {
    $owner = User::factory()->create();
    [$token] = GatewayToken::issue($owner, 'theirs');

    $this->actingAs(User::factory()->create())
        ->delete("/settings/developer-access/tokens/{$token->id}")
        ->assertStatus(403);

    expect($token->fresh()->revoked_at)->toBeNull();
});

test('the developer-access page 404s when the gateway is disabled', function () {
    config(['services.anthropic.gateway.enabled' => false]);

    $this->actingAs(User::factory()->create())
        ->get('/settings/developer-access')
        ->assertNotFound();
});

test('the developer-access page shows the base url and tokens when enabled', function () {
    config(['app.url' => 'https://aime.cwglobal.ai']);

    $user = User::factory()->create(['assigned_model' => 'claude-haiku-4-5']);
    GatewayToken::issue($user, 'Laptop');

    $this->actingAs($user)
        ->get('/settings/developer-access')
        ->assertInertia(fn ($page) => $page
            ->component('settings/DeveloperAccess')
            ->where('baseUrl', 'https://aime.cwglobal.ai/llm')
            ->where('assignedModel', 'claude-haiku-4-5')
            ->has('tokens', 1));
});
