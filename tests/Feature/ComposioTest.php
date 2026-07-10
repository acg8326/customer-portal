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
