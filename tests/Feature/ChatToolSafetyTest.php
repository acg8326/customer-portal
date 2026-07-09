<?php

use App\Http\Controllers\ChatController;
use App\Models\User;

/**
 * Invoke the private buildSystemPrompt() to assert the destructive-action
 * guardrail is appended only when the user actually has tools connected.
 */
function buildSystemPromptFor(User $user): string
{
    $conversation = $user->conversations()->create([
        'title' => 'T',
        'model' => 'claude-opus-4-8',
    ]);

    $method = new ReflectionMethod(ChatController::class, 'buildSystemPrompt');
    $method->setAccessible(true);

    return (string) $method->invoke(app(ChatController::class), $conversation->fresh());
}

test('the tool-safety guardrail is added only when tools are connected', function () {
    $user = User::factory()->create();

    expect(buildSystemPromptFor($user))->not->toContain('Using connected tools safely');

    $user->mcpServers()->create([
        'name' => 'X', 'url' => 'https://8.8.8.8/mcp', 'enabled' => true,
    ]);

    expect(buildSystemPromptFor($user))->toContain('Using connected tools safely');
});

test('a disabled MCP server does not trigger the guardrail', function () {
    $user = User::factory()->create();
    $user->mcpServers()->create([
        'name' => 'X', 'url' => 'https://8.8.8.8/mcp', 'enabled' => false,
    ]);

    expect(buildSystemPromptFor($user))->not->toContain('Using connected tools safely');
});

test('the tool-safety guardrail can be disabled via config', function () {
    config(['services.anthropic.tool_safety' => false]);

    $user = User::factory()->create();
    $user->mcpServers()->create([
        'name' => 'X', 'url' => 'https://8.8.8.8/mcp', 'enabled' => true,
    ]);

    expect(buildSystemPromptFor($user))->not->toContain('Using connected tools safely');
});
