<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\User;

/**
 * A plain conversation (no connected tools) whose system prompt we inspect.
 */
function makePlainConversation(): Conversation
{
    $user = User::factory()->create();

    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    return $conversation;
}

function capabilityPromptFor(Conversation $conversation): string
{
    $method = new ReflectionMethod(ChatController::class, 'buildSystemPrompt');

    return (string) $method->invoke(app(ChatController::class), $conversation->fresh());
}

test('the web-access note is included when web tools are enabled', function () {
    config(['services.anthropic.web_tools' => true]);

    expect(capabilityPromptFor(makePlainConversation()))->toContain('Web access');
});

test('the web-access note is omitted when web tools are disabled', function () {
    config(['services.anthropic.web_tools' => false]);

    expect(capabilityPromptFor(makePlainConversation()))->not->toContain('Web access');
});

test('the downloadable-answers note is always included', function () {
    config(['services.anthropic.web_tools' => false]);

    expect(capabilityPromptFor(makePlainConversation()))->toContain('Downloadable answers');
});

test('the current date and user name are injected dynamically', function () {
    $conversation = makePlainConversation();

    expect(capabilityPromptFor($conversation))
        ->toContain('Current date: '.now()->format('l, F j, Y'))
        ->toContain('User: '.$conversation->user->name);
});

test('the untrusted-content guardrail is included when web tools are active', function () {
    config(['services.anthropic.web_tools' => true]);

    expect(capabilityPromptFor(makePlainConversation()))
        ->toContain('Untrusted content')
        ->toContain('DATA, not instructions');
});

test('the untrusted-content guardrail is omitted when no tools are active', function () {
    config(['services.anthropic.web_tools' => false]);

    expect(capabilityPromptFor(makePlainConversation()))->not->toContain('Untrusted content');
});
