<?php

use App\Models\NetsuiteConnection;
use App\Models\User;
use App\Services\NetsuiteService;
use Illuminate\Support\Facades\Http;

/**
 * A stored NetSuite connection for $user (secrets are encrypted by the model).
 */
function makeNsConnection(User $user): NetsuiteConnection
{
    return $user->netsuiteConnection()->create([
        'account_id' => '1234567_SB1',
        'auth_type' => 'tba',
        'consumer_key' => 'ckey',
        'consumer_secret' => 'csecret',
        'token_id' => 'tid',
        'token_secret' => 'tsecret',
        'status' => 'active',
    ]);
}

$validCreds = [
    'auth_type' => 'tba',
    'account_id' => '1234567_SB1',
    'consumer_key' => 'ckey',
    'consumer_secret' => 'csecret',
    'token_id' => 'tid',
    'token_secret' => 'tsecret',
];

test('connecting requires all five TBA values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/integrations/netsuite/connect', ['auth_type' => 'tba', 'account_id' => '1234567'])
        ->assertStatus(422)
        ->assertJsonStructure([
            'errors' => ['consumer_key', 'consumer_secret', 'token_id', 'token_secret'],
        ]);
});

test('connecting stores the credentials and verifies the token with a signed request', function () use ($validCreds) {
    Http::fake(['*' => Http::response(['items' => [['id' => '1']]], 200)]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/integrations/netsuite/connect', $validCreds)
        ->assertOk()
        ->assertJson(['ok' => true, 'connected' => true]);

    expect($user->fresh()->netsuiteConnection)->not->toBeNull();

    // The request was OAuth 1.0a (TBA) signed, to the right SuiteQL endpoint.
    Http::assertSent(function ($request) {
        return str_starts_with((string) $request->header('Authorization')[0], 'OAuth realm="1234567_SB1"')
            && str_contains((string) $request->header('Authorization')[0], 'oauth_signature_method="HMAC-SHA256"')
            && str_contains($request->url(), '1234567-sb1.suitetalk.api.netsuite.com/services/rest/query/v1/suiteql');
    });
});

test('an INVALID_LOGIN response flags the connection as needing attention', function () use ($validCreds) {
    Http::fake(['*' => Http::response([
        'o:errorDetails' => [['o:errorCode' => 'INVALID_LOGIN', 'detail' => 'Invalid login attempt.']],
    ], 401)]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/integrations/netsuite/connect', $validCreds)
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    expect($user->fresh()->netsuiteConnection->status)->toBe('error');
});

test('the service runs a SuiteQL tool call and returns rows', function () {
    Http::fake(['*' => Http::response(['items' => [['id' => '1', 'companyname' => 'Acme']]], 200)]);

    $user = User::factory()->create();
    makeNsConnection($user);
    $service = app(NetsuiteService::class);

    expect($service->isNetsuiteTool('netsuite_suiteql'))->toBeTrue()
        ->and($service->isNetsuiteTool('SLACK_SEND_MESSAGE'))->toBeFalse();

    $result = $service->execute($user, 'netsuite_suiteql', ['query' => 'SELECT id FROM customer']);

    expect($result['ok'])->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/services/rest/query/v1/suiteql'));
});

test('tool schemas expose suiteql and get_record', function () {
    $names = collect(app(NetsuiteService::class)->toolSchemas())->pluck('name');

    expect($names)->toContain('netsuite_suiteql')
        ->and($names)->toContain('netsuite_get_record');
});

test('disconnecting removes the stored connection', function () {
    $user = User::factory()->create();
    makeNsConnection($user);

    $this->actingAs($user)
        ->delete('/integrations/netsuite')
        ->assertRedirect();

    expect($user->fresh()->netsuiteConnection)->toBeNull();
});

test('the endpoints 404 when the NetSuite feature is disabled', function () use ($validCreds) {
    config(['services.netsuite.enabled' => false]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/integrations/netsuite/connect', $validCreds)
        ->assertNotFound();
});

// --- OAuth 2.0 (Authorization Code Grant) ---------------------------------

test('OAuth2 connect stores a pending connection and returns the consent URL', function () {
    config(['app.url' => 'https://portal.test']);

    $user = User::factory()->create();

    $res = $this->actingAs($user)->postJson('/integrations/netsuite/connect', [
        'auth_type' => 'oauth2',
        'account_id' => '1234567_SB1',
        'client_id' => 'cid',
        'client_secret' => 'csec',
    ])->assertOk();

    $url = $res->json('redirect_url');

    expect($url)->toContain('https://1234567-sb1.app.netsuite.com/app/login/oauth2/authorize.nl')
        ->and($url)->toContain('response_type=code')
        ->and($url)->toContain('client_id=cid')
        ->and($url)->toContain(urlencode('https://portal.test/integrations/netsuite/callback'));

    // Not yet a live connection — awaiting consent.
    $conn = $user->fresh()->netsuiteConnection;
    expect($conn->status)->toBe('pending')
        ->and($conn->auth_type)->toBe('oauth2');
});

test('the OAuth2 callback exchanges the code for tokens and activates the connection', function () {
    Http::fake([
        '*/auth/oauth2/v1/token' => Http::response([
            'access_token' => 'AT-123',
            'refresh_token' => 'RT-123',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
        '*/query/v1/suiteql*' => Http::response(['items' => []], 200),
    ]);

    $user = User::factory()->create();

    // Begin the flow to create the pending row + store the state in session.
    $res = $this->actingAs($user)->postJson('/integrations/netsuite/connect', [
        'auth_type' => 'oauth2',
        'account_id' => '1234567_SB1',
        'client_id' => 'cid',
        'client_secret' => 'csec',
    ])->assertOk();

    parse_str((string) parse_url($res->json('redirect_url'), PHP_URL_QUERY), $q);

    // Fresh user instance for the callback — a real second request would load
    // one from the session, not reuse the connect request's cached relations.
    $this->actingAs($user->fresh())
        ->withSession(['netsuite_oauth_state' => $q['state']])
        ->get('/integrations/netsuite/callback?code=auth-code&state='.$q['state'])
        ->assertRedirect('/integrations');

    $conn = $user->fresh()->netsuiteConnection;
    expect($conn->status)->toBe('active')
        ->and($conn->access_token)->toBe('AT-123')
        ->and($conn->refresh_token)->toBe('RT-123');

    // A subsequent data call authenticates with the Bearer token.
    app(NetsuiteService::class)->suiteql($conn, 'SELECT id FROM customer');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/query/v1/suiteql')
        && $request->header('Authorization')[0] === 'Bearer AT-123');
});

test('the OAuth2 callback rejects a bad state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/integrations/netsuite/connect', [
        'auth_type' => 'oauth2',
        'account_id' => '1234567_SB1',
        'client_id' => 'cid',
        'client_secret' => 'csec',
    ])->assertOk();

    $this->actingAs($user->fresh())
        ->withSession(['netsuite_oauth_state' => 'CORRECT-STATE'])
        ->get('/integrations/netsuite/callback?code=auth-code&state=WRONG')
        ->assertRedirect('/integrations')
        ->assertSessionHas('error');

    // Still pending — not activated by a forged callback.
    expect($user->fresh()->netsuiteConnection->status)->toBe('pending');
});
