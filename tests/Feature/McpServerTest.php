<?php

use App\Models\User;

test('a user can connect an MCP server', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/integrations/mcp', [
            'name' => 'GitHub',
            'url' => 'https://8.8.8.8/mcp',
            'auth_token' => 'secret-token',
        ])
        ->assertRedirect();

    $server = $user->mcpServers()->first();

    expect($server)->not->toBeNull()
        ->and($server->name)->toBe('GitHub')
        ->and($server->url)->toBe('https://8.8.8.8/mcp')
        ->and($server->auth_token)->toBe('secret-token')
        ->and($server->enabled)->toBeTrue();
});

test('the MCP token is encrypted at rest', function () {
    $user = User::factory()->create();
    $server = $user->mcpServers()->create([
        'name' => 'X',
        'url' => 'https://8.8.8.8/mcp',
        'auth_token' => 'plaintext-secret',
        'enabled' => true,
    ]);

    $raw = DB::table('mcp_servers')->where('id', $server->id)->value('auth_token');

    expect($raw)->not->toBe('plaintext-secret')
        ->and($server->fresh()->auth_token)->toBe('plaintext-secret');
});

test('connecting an MCP server rejects non-public URLs (SSRF guard)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/integrations/mcp', [
            'name' => 'Evil',
            'url' => 'http://169.254.169.254/latest/meta-data/',
        ])
        ->assertSessionHasErrors('url');

    expect($user->mcpServers()->count())->toBe(0);
});

test('a user can enable/disable and delete an MCP server', function () {
    $user = User::factory()->create();
    $server = $user->mcpServers()->create([
        'name' => 'X', 'url' => 'https://8.8.8.8/mcp', 'enabled' => true,
    ]);

    $this->actingAs($user)
        ->patch("/integrations/mcp/{$server->id}", ['enabled' => false])
        ->assertRedirect();
    expect($server->fresh()->enabled)->toBeFalse();

    $this->actingAs($user)
        ->delete("/integrations/mcp/{$server->id}")
        ->assertRedirect();
    expect($user->mcpServers()->count())->toBe(0);
});

test('a user cannot touch another user\'s MCP server', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $server = $other->mcpServers()->create([
        'name' => 'Theirs', 'url' => 'https://8.8.8.8/mcp', 'enabled' => true,
    ]);

    $this->actingAs($me)->patch("/integrations/mcp/{$server->id}", ['enabled' => false])->assertNotFound();
    $this->actingAs($me)->delete("/integrations/mcp/{$server->id}")->assertNotFound();

    expect($server->fresh())->not->toBeNull();
});

test('the integrations page lists the user\'s MCP servers without leaking tokens', function () {
    $user = User::factory()->create();
    $user->mcpServers()->create([
        'name' => 'GitHub', 'url' => 'https://8.8.8.8/mcp', 'auth_token' => 'tok', 'enabled' => true,
    ]);

    $this->actingAs($user)
        ->get('/integrations')
        ->assertInertia(
            fn ($page) => $page
                ->has('mcpServers', 1)
                ->where('mcpServers.0.name', 'GitHub')
                ->where('mcpServers.0.has_token', true)
                ->missing('mcpServers.0.auth_token')
        );
});

test('the chat page reports whether MCP is enabled', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/chat')
        ->assertInertia(fn ($page) => $page->where('mcpEnabled', false));

    $user->mcpServers()->create([
        'name' => 'X', 'url' => 'https://8.8.8.8/mcp', 'enabled' => true,
    ]);

    $this->actingAs($user)->get('/chat')
        ->assertInertia(fn ($page) => $page->where('mcpEnabled', true));
});
