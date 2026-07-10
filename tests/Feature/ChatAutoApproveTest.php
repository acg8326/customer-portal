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

test('the tool-safety guardrail is included by default when tools are connected', function () {
    config(['services.anthropic.tool_safety' => true]);

    $prompt = systemPromptFor(makeToolConversation(false)->fresh());

    expect($prompt)->toContain('Using connected tools safely');
});

test('auto-approve omits the tool-safety guardrail', function () {
    config(['services.anthropic.tool_safety' => true]);

    $prompt = systemPromptFor(makeToolConversation(true)->fresh());

    expect($prompt)->not->toContain('Using connected tools safely');
});

test('show() reports the conversation auto_approve flag', function () {
    $conversation = makeToolConversation(true);

    $this->actingAs($conversation->user)
        ->getJson("/chat/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJson(['auto_approve' => true]);
});
