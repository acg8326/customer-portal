<?php

use App\Models\McpServer;
use App\Models\User;
use App\Services\Mcp\McpOAuthService;
use Illuminate\Support\Facades\Http;

/**
 * Fake the discovery + registration + token endpoints for a server hosted at
 * the public IP literal 8.8.8.8 (a literal skips DNS, so the SSRF guard passes
 * without a network call).
 */
function fakeOAuthServer(): void
{
    Http::fake([
        // The endpoint validator probes the server URL itself (POST initialize);
        // 401 = "it's an MCP server that needs auth" → accepted.
        'https://8.8.8.8/mcp' => Http::response('', 401),
        'https://8.8.8.8/.well-known/oauth-protected-resource*' => Http::response([
            'resource' => 'https://8.8.8.8/mcp',
            'authorization_servers' => ['https://8.8.8.8'],
        ], 200),
        'https://8.8.8.8/.well-known/oauth-authorization-server' => Http::response([
            'issuer' => 'https://8.8.8.8',
            'authorization_endpoint' => 'https://8.8.8.8/authorize',
            'token_endpoint' => 'https://8.8.8.8/token',
            'registration_endpoint' => 'https://8.8.8.8/register',
            'scopes_supported' => ['read', 'write'],
        ], 200),
        'https://8.8.8.8/register' => Http::response([
            'client_id' => 'test-client',
        ], 200),
        'https://8.8.8.8/token' => Http::response([
            'access_token' => 'access-abc',
            'refresh_token' => 'refresh-xyz',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
    ]);
}

test('a user can add an OAuth MCP server without pasting a token', function () {
    Http::fake(['https://8.8.8.8/mcp' => Http::response('', 401)]); // valid MCP (needs auth)

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/integrations/mcp', [
            'name' => 'GitHub',
            'url' => 'https://8.8.8.8/mcp',
            'auth_type' => 'oauth',
        ])
        ->assertRedirect();

    $server = $user->mcpServers()->first();

    expect($server->auth_type)->toBe('oauth')
        ->and($server->auth_token)->toBeNull()
        ->and($server->oauthConnected())->toBeFalse();
});

test('beginAuthorization discovers, registers a client, and builds a PKCE authorize URL', function () {
    fakeOAuthServer();

    $user = User::factory()->create();
    $server = $user->mcpServers()->create([
        'name' => 'GitHub', 'url' => 'https://8.8.8.8/mcp', 'auth_type' => 'oauth', 'enabled' => true,
    ]);

    $flow = app(McpOAuthService::class)->beginAuthorization($server, 'https://app.test/callback');

    expect($flow['url'])->toContain('https://8.8.8.8/authorize')
        ->and($flow['url'])->toContain('code_challenge=')
        ->and($flow['url'])->toContain('code_challenge_method=S256')
        ->and($flow['url'])->toContain('client_id=test-client')
        ->and($flow['state'])->not->toBe('')
        ->and($flow['verifier'])->not->toBe('');

    $server->refresh();
    expect($server->oauth_client_id)->toBe('test-client')
        ->and($server->oauthMetadata()['token_endpoint'])->toBe('https://8.8.8.8/token');
});

test('the OAuth callback exchanges the code and stores tokens', function () {
    fakeOAuthServer();

    $user = User::factory()->create();
    $server = $user->mcpServers()->create([
        'name' => 'GitHub', 'url' => 'https://8.8.8.8/mcp', 'auth_type' => 'oauth', 'enabled' => true,
    ]);
    $server->oauth_client_id = 'test-client';
    $server->oauth_metadata = ['token_endpoint' => 'https://8.8.8.8/token', 'resource' => null];
    $server->save();

    $this->actingAs($user)
        ->withSession(['mcp_oauth' => ['server_id' => $server->id, 'state' => 'st4te', 'verifier' => 'ver1fier']])
        ->get('/integrations/mcp/oauth/callback?code=thecode&state=st4te')
        ->assertRedirect(route('integrations'));

    $server->refresh();
    expect($server->oauth_access_token)->toBe('access-abc')
        ->and($server->oauth_refresh_token)->toBe('refresh-xyz')
        ->and($server->oauthConnected())->toBeTrue();
});

test('the OAuth callback rejects a mismatched state (CSRF)', function () {
    $user = User::factory()->create();
    $server = $user->mcpServers()->create([
        'name' => 'GitHub', 'url' => 'https://8.8.8.8/mcp', 'auth_type' => 'oauth', 'enabled' => true,
    ]);

    $this->actingAs($user)
        ->withSession(['mcp_oauth' => ['server_id' => $server->id, 'state' => 'expected', 'verifier' => 'v']])
        ->get('/integrations/mcp/oauth/callback?code=c&state=WRONG')
        ->assertRedirect(route('integrations'))
        ->assertSessionHas('error');

    expect($server->fresh()->oauthConnected())->toBeFalse();
});

test('accessToken refreshes an expiring token', function () {
    fakeOAuthServer();

    $user = User::factory()->create();
    $server = $user->mcpServers()->create([
        'name' => 'GitHub', 'url' => 'https://8.8.8.8/mcp', 'auth_type' => 'oauth', 'enabled' => true,
    ]);
    $server->oauth_client_id = 'test-client';
    $server->oauth_access_token = 'stale';
    $server->oauth_refresh_token = 'refresh-xyz';
    $server->oauth_expires_at = now()->subMinute();
    $server->oauth_metadata = ['token_endpoint' => 'https://8.8.8.8/token'];
    $server->save();

    expect(app(McpOAuthService::class)->accessToken($server))->toBe('access-abc');
});

test('accessToken is null for an unconnected OAuth server (so chat skips it)', function () {
    $user = User::factory()->create();
    $server = $user->mcpServers()->create([
        'name' => 'GitHub', 'url' => 'https://8.8.8.8/mcp', 'auth_type' => 'oauth', 'enabled' => true,
    ]);

    expect(app(McpOAuthService::class)->accessToken($server))->toBeNull();
});

test('OAuth discovery refuses a non-public server URL (SSRF guard)', function () {
    $user = User::factory()->create();
    // Created directly (bypassing the store() URL validation) to prove the
    // service itself refuses to contact a private address.
    $server = $user->mcpServers()->create([
        'name' => 'Evil', 'url' => 'http://169.254.169.254/mcp', 'auth_type' => 'oauth', 'enabled' => true,
    ]);

    expect(fn () => app(McpOAuthService::class)->beginAuthorization($server, 'https://app.test/cb'))
        ->toThrow(RuntimeException::class);
});

test('catalog connect creates the server and redirects to the provider', function () {
    fakeOAuthServer();
    config(['integrations.mcp_catalog' => [[
        'key' => 'testapp',
        'name' => 'Test App',
        'url' => 'https://8.8.8.8/mcp',
        'icon' => 'plug',
        'category' => 'Apps',
        'description' => '',
    ]]]);

    $user = User::factory()->create();

    $res = $this->actingAs($user)->get('/integrations/mcp/catalog/testapp/connect');
    $res->assertRedirect();

    $server = $user->mcpServers()->where('catalog_key', 'testapp')->first();
    expect($server)->not->toBeNull()
        ->and($server->usesOAuth())->toBeTrue()
        ->and($server->url)->toBe('https://8.8.8.8/mcp');

    expect($res->headers->get('Location'))->toContain('https://8.8.8.8/authorize');
});

test('catalog connect 404s for an unknown key', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/integrations/mcp/catalog/does-not-exist/connect')
        ->assertNotFound();
});

test('validateEndpoint accepts an auth challenge but rejects a web page', function () {
    Http::fake([
        'https://8.8.8.8/mcp' => Http::response('', 401),
        'https://8.8.8.8/page' => Http::response('<!doctype html><html></html>', 200, ['Content-Type' => 'text/html']),
        'https://8.8.8.8/missing' => Http::response('', 404),
    ]);

    $svc = app(McpOAuthService::class);

    expect($svc->validateEndpoint('https://8.8.8.8/mcp')['ok'])->toBeTrue()
        ->and($svc->validateEndpoint('https://8.8.8.8/page')['ok'])->toBeFalse()
        ->and($svc->validateEndpoint('https://8.8.8.8/missing')['ok'])->toBeFalse()
        ->and($svc->validateEndpoint('http://127.0.0.1/mcp')['ok'])->toBeFalse(); // non-public
});

test('adding an MCP server rejects a URL that serves a web page', function () {
    Http::fake(['*' => Http::response('<!doctype html><html><body>n8n</body></html>', 200, ['Content-Type' => 'text/html'])]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/integrations/mcp', [
            'name' => 'n8n',
            'url' => 'https://8.8.8.8/home/workflows',
            'auth_type' => 'oauth',
        ])
        ->assertSessionHasErrors('url');

    expect($user->mcpServers()->count())->toBe(0);
});

test('tokenExpired reflects presence and expiry of the access token', function () {
    $server = new McpServer;
    expect($server->tokenExpired())->toBeTrue(); // no token yet

    $server->oauth_access_token = 'x';
    $server->oauth_expires_at = null;
    expect($server->tokenExpired())->toBeFalse(); // no expiry => long-lived

    $server->oauth_expires_at = now()->addHour();
    expect($server->tokenExpired(60))->toBeFalse();

    $server->oauth_expires_at = now()->addSeconds(30);
    expect($server->tokenExpired(60))->toBeTrue(); // within the refresh leeway
});
