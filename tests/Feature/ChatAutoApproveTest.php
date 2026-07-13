<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\User;

/**
 * A conversation whose owner has a connected tool (so the tool-safety guardrail
 * is relevant), with the auto-approve flag set as given.
 */
function makeToolConversation(bool $autoApprove): Conversation
{
    $user = User::factory()->create();
    $user->composioConnections()->create(['toolkit' => 'slack', 'status' => 'active']);

    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Tools chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->auto_approve = $autoApprove;
    $conversation->save();

    return $conversation;
}

function systemPromptFor(Conversation $conversation): string
{
    $method = new ReflectionMethod(ChatController::class, 'buildSystemPrompt');

    return (string) $method->invoke(app(ChatController::class), $conversation);
}

test('the ask-in-text guardrail applies to client tools when the hard gate is off', function () {
    config(['services.anthropic.tool_safety' => true, 'services.anthropic.tool_hard_gate' => false]);

    $prompt = systemPromptFor(makeToolConversation(false)->fresh());

    expect($prompt)->toContain('Using connected tools safely');
});

test('the hard gate replaces the ask-in-text guardrail for client tools', function () {
    config(['services.anthropic.tool_safety' => true, 'services.anthropic.tool_hard_gate' => true]);

    $prompt = systemPromptFor(makeToolConversation(false)->fresh());

    // The UI approval card gates destructive calls instead — no double prompt.
    expect($prompt)->not->toContain('Using connected tools safely');
});

test('MCP servers keep the ask-in-text guardrail even with the hard gate on', function () {
    config(['services.anthropic.tool_safety' => true, 'services.anthropic.tool_hard_gate' => true]);

    $user = User::factory()->create();
    $user->mcpServers()->create([
        'name' => 'Test MCP',
        'url' => 'https://mcp.example.com',
        'enabled' => true,
    ]);

    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'MCP chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    // MCP tools execute at Anthropic and can't be gated client-side.
    expect(systemPromptFor($conversation->fresh()))->toContain('Using connected tools safely');
});

test('auto-approve omits the tool-safety guardrail', function () {
    config(['services.anthropic.tool_safety' => true]);

    $prompt = systemPromptFor(makeToolConversation(true)->fresh());

    expect($prompt)->not->toContain('Using connected tools safely');
});

test('auto-approve does NOT omit the untrusted-content guardrail', function () {
    config(['services.anthropic.tool_safety' => true]);

    $prompt = systemPromptFor(makeToolConversation(true)->fresh());

    // Prompt-injection defense is not a convenience setting — it stays on.
    expect($prompt)->toContain('Untrusted content');
});

test('show() reports the conversation auto_approve flag', function () {
    $conversation = makeToolConversation(true);

    $this->actingAs($conversation->user)
        ->getJson("/chat/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJson(['auto_approve' => true]);
});
