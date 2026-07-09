<?php

use App\Models\User;
use App\Services\N8nDispatcher;
use Illuminate\Support\Facades\Http;

test('the integrations page loads with live providers and connections', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/integrations')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Integrations')
                ->where('live', fn ($live) => collect($live)->contains('n8n'))
                ->has('connections')
        );
});

test('a user can connect n8n with a webhook url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/integrations/webhook/n8n', [
            'webhook_url' => 'https://8.8.8.8/webhook/abc',
            'secret' => 's3cret',
        ])
        ->assertRedirect();

    $integration = $user->integrations()->where('provider', 'n8n')->first();

    expect($integration)->not->toBeNull()
        ->and($integration->config['webhook_url'])->toBe('https://8.8.8.8/webhook/abc')
        ->and($integration->config['secret'])->toBe('s3cret')
        ->and($integration->connected_at)->not->toBeNull();
});

test('connecting n8n requires a valid url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/integrations/webhook/n8n', ['webhook_url' => 'not-a-url'])
        ->assertRedirect()
        ->assertSessionHasErrors('webhook_url');

    expect($user->integrations()->count())->toBe(0);
});

test('connecting n8n rejects private and reserved addresses (SSRF guard)', function () {
    $user = User::factory()->create();

    $blocked = [
        'http://169.254.169.254/latest/meta-data/',  // cloud metadata
        'http://127.0.0.1/webhook',                    // loopback
        'http://10.0.0.5/webhook',                     // private
        'http://192.168.1.10/webhook',                 // private
        'ftp://8.8.8.8/webhook',                       // wrong scheme
    ];

    foreach ($blocked as $url) {
        $this->actingAs($user)
            ->post('/integrations/webhook/n8n', ['webhook_url' => $url])
            ->assertSessionHasErrors('webhook_url');
    }

    expect($user->integrations()->count())->toBe(0);
});

test('a user can connect a generic webhook provider (zapier)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/integrations/webhook/zapier', ['webhook_url' => 'https://8.8.8.8/hooks/xyz'])
        ->assertRedirect();

    expect($user->integrations()->where('provider', 'zapier')->first())->not->toBeNull();
});

test('a user can connect Make.com as a webhook provider', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/integrations/webhook/make', ['webhook_url' => 'https://8.8.8.8/hooks/make'])
        ->assertRedirect();

    expect($user->integrations()->where('provider', 'make')->first())->not->toBeNull();
});

test('an unknown webhook provider 404s', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/integrations/webhook/bogus', ['webhook_url' => 'https://8.8.8.8/x'])
        ->assertNotFound();

    expect($user->integrations()->count())->toBe(0);
});

test('the integrations page exposes the app catalog and webhook providers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/integrations')
        ->assertInertia(fn ($page) => $page
            ->has('mcpCatalog')
            ->where('webhookProviders', fn ($p) => collect($p)->contains('zapier'))
        );
});

test('connecting again updates the existing n8n row', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/integrations/webhook/n8n', [
        'webhook_url' => 'https://8.8.8.8/webhook/one',
    ]);
    $this->actingAs($user)->post('/integrations/webhook/n8n', [
        'webhook_url' => 'https://8.8.8.8/webhook/two',
    ]);

    expect($user->integrations()->where('provider', 'n8n')->count())->toBe(1)
        ->and($user->integrations()->where('provider', 'n8n')->first()->config['webhook_url'])
        ->toBe('https://8.8.8.8/webhook/two');
});

test('the test endpoint posts to the webhook and reports success', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $user = User::factory()->create();
    $user->integrations()->create([
        'provider' => 'n8n',
        'config' => ['webhook_url' => 'https://8.8.8.8/webhook/abc', 'secret' => ''],
        'connected_at' => now(),
    ]);

    $this->actingAs($user)
        ->post('/integrations/webhook/n8n/test')
        ->assertRedirect()
        ->assertSessionHas('success');

    Http::assertSent(fn ($request) => $request->url() === 'https://8.8.8.8/webhook/abc'
        && $request['event'] === 'test.ping');
});

test('the test endpoint reports an error on a non-2xx response', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $user = User::factory()->create();
    $user->integrations()->create([
        'provider' => 'n8n',
        'config' => ['webhook_url' => 'https://8.8.8.8/webhook/abc'],
        'connected_at' => now(),
    ]);

    $this->actingAs($user)
        ->post('/integrations/webhook/n8n/test')
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('the test endpoint asks the user to connect first when not connected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/integrations/webhook/n8n/test')
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('a user can disconnect n8n', function () {
    $user = User::factory()->create();
    $user->integrations()->create([
        'provider' => 'n8n',
        'config' => ['webhook_url' => 'https://8.8.8.8/webhook/abc'],
        'connected_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete('/integrations/n8n')
        ->assertRedirect();

    expect($user->integrations()->count())->toBe(0);
});

test('the dispatcher posts an event with a secret header when connected', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $user = User::factory()->create();
    $user->integrations()->create([
        'provider' => 'n8n',
        'config' => ['webhook_url' => 'https://8.8.8.8/webhook/abc', 'secret' => 'shh'],
        'connected_at' => now(),
    ]);

    app(N8nDispatcher::class)->dispatch($user, 'chat.completed', ['conversation_id' => 1]);

    Http::assertSent(fn ($request) => $request->url() === 'https://8.8.8.8/webhook/abc'
        && $request['event'] === 'chat.completed'
        && $request->hasHeader('X-AiMe-Secret', 'shh'));
});

test('the dispatcher does nothing when the user has no n8n connection', function () {
    Http::fake();

    $user = User::factory()->create();

    app(N8nDispatcher::class)->dispatch($user, 'chat.completed', ['conversation_id' => 1]);

    Http::assertNothingSent();
});

test('integration endpoints require authentication', function () {
    $this->get('/integrations')->assertRedirect('/login');
    $this->post('/integrations/webhook/n8n', ['webhook_url' => 'https://x.test/y'])->assertRedirect('/login');
});

test('a user cannot see another user\'s connection', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $other->integrations()->create([
        'provider' => 'n8n',
        'config' => ['webhook_url' => 'https://8.8.8.8/webhook/secret'],
        'connected_at' => now(),
    ]);

    $this->actingAs($me)
        ->get('/integrations')
        ->assertInertia(fn ($page) => $page->where('connections', []));
});
