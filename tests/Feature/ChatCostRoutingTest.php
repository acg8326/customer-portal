<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\User;

function makeRoutedConversation(string ...$userMessages): Conversation
{
    $user = User::factory()->create();

    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    foreach ($userMessages as $content) {
        $conversation->messages()->create(['role' => 'user', 'content' => $content]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'ok']);
    }

    return $conversation;
}

function routeFor(array $toolkits, bool $netsuite, Conversation $conversation): array
{
    $ref = new ReflectionMethod(ChatController::class, 'routeToolkits');

    return $ref->invoke(app(ChatController::class), $toolkits, $netsuite, $conversation);
}

test('a NetSuite question drops the other toolkits', function () {
    $conversation = makeRoutedConversation('Show me the latest NetSuite invoices for Acme');

    [$toolkits, $netsuite] = routeFor(['slack', 'github'], true, $conversation);

    expect($toolkits)->toBe([])
        ->and($netsuite)->toBeTrue();
});

test('a Slack question drops NetSuite', function () {
    $conversation = makeRoutedConversation('Post a message to the #general channel');

    [$toolkits, $netsuite] = routeFor(['slack', 'github'], true, $conversation);

    expect($toolkits)->toBe(['slack'])
        ->and($netsuite)->toBeFalse();
});

test('no keyword match keeps every connected source', function () {
    $conversation = makeRoutedConversation('What can you help me with today?');

    [$toolkits, $netsuite] = routeFor(['slack', 'github'], true, $conversation);

    expect($toolkits)->toBe(['slack', 'github'])
        ->and($netsuite)->toBeTrue();
});

test('a single connected source bypasses routing entirely', function () {
    $conversation = makeRoutedConversation('Anything at all');

    [$toolkits, $netsuite] = routeFor(['slack'], false, $conversation);

    expect($toolkits)->toBe(['slack'])
        ->and($netsuite)->toBeFalse();
});

test('routing can be disabled by config', function () {
    config(['services.composio.toolkit_routing' => false]);

    $conversation = makeRoutedConversation('Show me NetSuite invoices');

    [$toolkits, $netsuite] = routeFor(['slack', 'github'], true, $conversation);

    expect($toolkits)->toBe(['slack', 'github'])
        ->and($netsuite)->toBeTrue();
});

test('keywords from earlier turns in the window still count', function () {
    $conversation = makeRoutedConversation(
        'List my Slack channels',
        'Now send that summary to the team',
    );

    [$toolkits, $netsuite] = routeFor(['slack', 'hubspot'], true, $conversation);

    expect($toolkits)->toBe(['slack'])
        ->and($netsuite)->toBeFalse();
});

test('oversized tool results are truncated with a narrowing note', function () {
    config(['services.anthropic.tool_result_max_chars' => 100]);

    $ref = new ReflectionMethod(ChatController::class, 'truncateToolResult');
    $long = str_repeat('x', 500);
    $result = (string) $ref->invoke(app(ChatController::class), $long);

    expect($result)
        ->toContain('[Result truncated — showing the first 100 of 500 characters.')
        ->and(str_starts_with($result, str_repeat('x', 100)))->toBeTrue();

    $short = str_repeat('x', 50);

    expect((string) $ref->invoke(app(ChatController::class), $short))->toBe($short);
});
