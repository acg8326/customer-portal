<?php

use App\Models\User;
use App\Services\ComposioService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.composio.api_key', 'test-key');
    config()->set('services.composio.base_url', 'https://backend.composio.dev');
    config()->set('services.composio.toolkits.slack', [
        'name' => 'Slack',
        'auth_config_id' => 'ac_test',
        'mcp_server_id' => 'srv_test',
    ]);
});

test('composio is disabled without an api key', function () {
    config()->set('services.composio.api_key', null);

    expect(app(ComposioService::class)->enabled())->toBeFalse();
});

test('only fully-configured toolkits are offered', function () {
    config()->set('services.composio.toolkits.github', [
        'name' => 'GitHub',
        'auth_config_id' => null,   // missing → excluded
        'mcp_server_id' => 'srv_x',
    ]);

    $toolkits = app(ComposioService::class)->toolkits();

    expect($toolkits)->toHaveKey('slack')
        ->and($toolkits)->not->toHaveKey('github');
});

test('connect redirects the user to the composio consent url', function () {
    Http::fake([
        'backend.composio.dev/*' => Http::response([
            'redirect_url' => 'https://slack.com/oauth/allow?state=abc',
            'connected_account_id' => 'ca_123',
        ], 200),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/integrations/composio/slack/connect')
        ->assertRedirect('https://slack.com/oauth/allow?state=abc');

    $this->assertDatabaseHas('composio_connections', [
        'user_id' => $user->id,
        'toolkit' => 'slack',
        'status' => 'initiated',
        'connected_account_id' => 'ca_123',
    ]);
});

test('callback marks active only when Composio reports the grant ACTIVE', function () {
    Http::fake([
        'backend.composio.dev/api/v3/connected_accounts*' => Http::response([
            'items' => [['id' => 'ca_1', 'status' => 'ACTIVE']],
        ], 200),
    ]);

    $user = User::factory()->create();
    $user->composioConnections()->create(['toolkit' => 'slack', 'status' => 'initiated']);

    $this->actingAs($user)
        ->get('/integrations/composio/slack/callback')
        ->assertRedirect(route('integrations'));

    expect(app(ComposioService::class)->isConnected($user->fresh(), 'slack'))->toBeTrue();
});

test('callback does NOT mark active when the grant is not ACTIVE', function () {
    Http::fake([
        'backend.composio.dev/api/v3/connected_accounts*' => Http::response([
            'items' => [['id' => 'ca_1', 'status' => 'EXPIRED']],
        ], 200),
    ]);

    $user = User::factory()->create();
    $user->composioConnections()->create(['toolkit' => 'slack', 'status' => 'initiated']);

    $this->actingAs($user)->get('/integrations/composio/slack/callback');

    expect(app(ComposioService::class)->isConnected($user->fresh(), 'slack'))->toBeFalse();
});

test('toolSchemas maps Composio tools into Anthropic tool format', function () {
    Http::fake([
        'backend.composio.dev/api/v3/tools*' => Http::response([
            'items' => [
                [
                    'slug' => 'SLACK_LIST_ALL_CHANNELS',
                    'description' => 'List channels',
                    'input_parameters' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]],
                ],
                // A slug too long for an Anthropic tool name is skipped.
                ['slug' => str_repeat('X', 70), 'description' => 'nope', 'input_parameters' => []],
            ],
        ], 200),
    ]);

    $tools = app(ComposioService::class)->toolSchemas(['slack']);

    expect($tools)->toHaveCount(1)
        ->and($tools[0]['name'])->toBe('SLACK_LIST_ALL_CHANNELS')
        ->and($tools[0]['input_schema'])->toHaveKey('properties');

    // Regression: a toolkit version must be pinned, or some toolkits (NetSuite)
    // return zero tools.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'toolkit_versions=latest'));
});

test('toolSchemas merges the curated + general tool lists and de-dupes', function () {
    Http::fake([
        // Pass 1: important=true → curated (Slack's send, which is late-alphabet).
        'backend.composio.dev/api/v3/tools?*important=true*' => Http::response([
            'items' => [['slug' => 'SLACK_SEND_MESSAGE', 'description' => 'Send']],
        ], 200),
        // Pass 2: general → breadth, including a duplicate of the curated tool.
        'backend.composio.dev/api/v3/tools*' => Http::response([
            'items' => [
                ['slug' => 'SLACK_SEND_MESSAGE', 'description' => 'Send (dupe)'],
                ['slug' => 'SLACK_LIST_ALL_CHANNELS', 'description' => 'List'],
            ],
        ], 200),
    ]);

    $names = array_column(app(ComposioService::class)->toolSchemas(['slack']), 'name');

    expect($names)->toEqualCanonicalizing([
        'SLACK_SEND_MESSAGE',
        'SLACK_LIST_ALL_CHANNELS',
    ]);
});

test('execute posts to the Composio tool endpoint and returns the output', function () {
    Http::fake([
        'backend.composio.dev/api/v3/tools/execute/*' => Http::response([
            'successful' => true,
            'data' => ['channels' => ['general', 'random']],
        ], 200),
    ]);

    $user = User::factory()->create();

    $result = app(ComposioService::class)->execute($user, 'SLACK_LIST_ALL_CHANNELS', []);

    expect($result['ok'])->toBeTrue()
        ->and($result['output'])->toBe(['channels' => ['general', 'random']]);

    // Regression: execute must send a tool version, or versioned toolkits 404.
    Http::assertSent(fn ($request) => ($request['version'] ?? null) === 'latest');
});

test('execute pins to the recorded connected account for the toolkit', function () {
    Http::fake([
        'backend.composio.dev/api/v3/tools/execute/*' => Http::response(['successful' => true, 'data' => []], 200),
    ]);

    $user = User::factory()->create();
    $user->composioConnections()->create([
        'toolkit' => 'netsuite',
        'status' => 'active',
        'connected_account_id' => 'ca_new',
    ]);

    app(ComposioService::class)->execute($user, 'NETSUITE_RUN_SUITEQL_QUERY', ['q' => 'SELECT 1']);

    Http::assertSent(fn ($request) => ($request['connected_account_id'] ?? null) === 'ca_new'
        && ($request['user_id'] ?? null) === (string) $user->id);
});

test('execute reports a not-connected error cleanly', function () {
    Http::fake([
        'backend.composio.dev/api/v3/tools/execute/*' => Http::response([
            'error' => ['message' => 'No connected account found'],
        ], 400),
    ]);

    $user = User::factory()->create();

    $result = app(ComposioService::class)->execute($user, 'SLACK_LIST_ALL_CHANNELS', []);

    expect($result['ok'])->toBeFalse()
        ->and($result['output'])->toBe('No connected account found');
});

test('an unconfigured toolkit cannot be connected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/integrations/composio/notion/connect')
        ->assertRedirect();

    $this->assertDatabaseMissing('composio_connections', [
        'user_id' => $user->id,
        'toolkit' => 'notion',
    ]);
});

test('disconnect removes the connection', function () {
    $user = User::factory()->create();
    $user->composioConnections()->create(['toolkit' => 'slack', 'status' => 'active']);

    $this->actingAs($user)
        ->delete('/integrations/composio/slack')
        ->assertRedirect();

    $this->assertDatabaseMissing('composio_connections', [
        'user_id' => $user->id,
        'toolkit' => 'slack',
    ]);
});

function netsuiteConfig(): void
{
    config()->set('services.composio.toolkits.netsuite', [
        'name' => 'NetSuite',
        'mode' => 'credentials',
        'auth_scheme' => 'OAUTH2',
        'scopes' => 'restlets,rest_webservices',
        'optional_scopes' => ['suite_analytics' => 'SuiteAnalytics Connect'],
        'credentials' => ['client_id' => 'Client ID', 'client_secret' => 'Client Secret'],
        'initiation' => ['subdomain' => 'Account ID'],
    ]);
}

test('credentials toolkit is offered even without a pre-set auth config', function () {
    netsuiteConfig();

    expect(app(ComposioService::class)->toolkits())->toHaveKey('netsuite');
});

test('netsuite connect validates credentials + account id and does not call composio', function () {
    netsuiteConfig();
    Http::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/integrations/composio/netsuite/connect', [
            'client_id' => 'abc',
            // missing client_secret + subdomain
        ])
        ->assertStatus(422);

    Http::assertNothingSent();
    $this->assertDatabaseMissing('composio_connections', [
        'user_id' => $user->id,
        'toolkit' => 'netsuite',
    ]);
});

test('netsuite connect creates an auth config and links with the subdomain', function () {
    netsuiteConfig();

    Http::fake([
        '*/auth_configs' => Http::response(['auth_config' => ['id' => 'ac_new_ns']], 201),
        '*/connected_accounts/link' => Http::response([
            'redirect_url' => 'https://system.netsuite.com/oauth?state=xyz',
            'connected_account_id' => 'ca_ns_1',
        ], 200),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/integrations/composio/netsuite/connect', [
            'client_id' => 'cid',
            'client_secret' => 'secret',
            'subdomain' => '1234567-sb1',
            'scopes' => ['suite_analytics'],
        ])
        ->assertOk()
        ->assertJson(['redirect_url' => 'https://system.netsuite.com/oauth?state=xyz']);

    // Auth config was created from the pasted credentials, with scopes merged.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/auth_configs')) {
            return false;
        }
        $creds = $request['auth_config']['credentials'] ?? [];

        return ($request['toolkit']['slug'] ?? null) === 'netsuite'
            && ($creds['client_id'] ?? null) === 'cid'
            && ($creds['client_secret'] ?? null) === 'secret'
            && str_contains((string) ($creds['scopes'] ?? ''), 'suite_analytics');
    });

    // Link used the freshly-created auth config + the account subdomain.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/connected_accounts/link')
            && ($request['auth_config_id'] ?? null) === 'ac_new_ns'
            && ($request['connection_data']['subdomain'] ?? null) === '1234567-sb1';
    });

    $this->assertDatabaseHas('composio_connections', [
        'user_id' => $user->id,
        'toolkit' => 'netsuite',
        'status' => 'initiated',
    ]);
});
