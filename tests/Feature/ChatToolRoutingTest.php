<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\User;

/**
 * Keyword routing and the connected-sources prompt block are both private —
 * and both decide, invisibly, whether the assistant checks a real data source
 * or answers from memory. Asserting on them directly is the only way to pin
 * that behaviour down without a live Claude call.
 */
function invokeRouting(string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod(ChatController::class, $method))
        ->invoke(app(ChatController::class), ...$args);
}

function routingConversation(User $user, string ...$userTurns): Conversation
{
    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Routing';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    foreach ($userTurns as $text) {
        $conversation->messages()->create(['role' => 'user', 'content' => $text]);
    }

    return $conversation;
}

// --- keyword matching ---------------------------------------------------------

test('keywords match whole words, not substrings', function () {
    // Every one of these silently mis-routed a turn before: the matched source
    // shipped and the source the user actually meant was dropped.
    expect(invokeRouting('matchesAny', 'can you check my admin settings', ['dm']))->toBeFalse()
        ->and(invokeRouting('matchesAny', 'please approve the request', ['pr']))->toBeFalse()
        ->and(invokeRouting('matchesAny', 'show me tomorrow figures', ['row']))->toBeFalse()
        ->and(invokeRouting('matchesAny', 'what is in the database', ['tab']))->toBeFalse()
        ->and(invokeRouting('matchesAny', 'that was excellent work', ['cell']))->toBeFalse()
        ->and(invokeRouting('matchesAny', 'narrow it down', ['row']))->toBeFalse();
});

test('the words themselves still match, with punctuation and case', function () {
    expect(invokeRouting('matchesAny', 'send a DM to Ana', ['dm']))->toBeTrue()
        ->and(invokeRouting('matchesAny', 'add a row, please', ['row']))->toBeTrue()
        ->and(invokeRouting('matchesAny', 'which column?', ['column']))->toBeTrue()
        ->and(invokeRouting('matchesAny', 'open the sheet.', ['sheet']))->toBeTrue();
});

test('multi-word keywords stay phrase matches', function () {
    // No token boundary to compare, so these keep substring semantics.
    expect(invokeRouting('matchesAny', 'find the sales order for acme', ['sales order']))->toBeTrue()
        ->and(invokeRouting('matchesAny', 'check my google sheet', ['google sheet']))->toBeTrue()
        ->and(invokeRouting('matchesAny', 'order the sales report', ['sales order']))->toBeFalse();
});

// --- routing outcome ----------------------------------------------------------

test('routing is skipped entirely with fewer than two sources', function () {
    $user = User::factory()->create();
    $conversation = routingConversation($user, 'hello there');

    // One source: nothing to choose between, so it always ships. This is why
    // routing only started affecting accounts once a second source connected.
    expect(invokeRouting('routeToolkits', ['googlesheets'], false, $conversation))
        ->toBe([['googlesheets'], false]);
});

test('an unmatched turn keeps every source rather than silently dropping all', function () {
    $user = User::factory()->create();
    $conversation = routingConversation($user, 'what can you do for me?');

    expect(invokeRouting('routeToolkits', ['googlesheets'], true, $conversation))
        ->toBe([['googlesheets'], true]);
});

test('a spreadsheet question no longer drops NetSuite by accident', function () {
    $user = User::factory()->create();

    // "growth" contains "row"; before word-boundary matching this routed to
    // Sheets alone and NetSuite never got a chance to answer.
    $conversation = routingConversation($user, 'what was our growth last quarter?');

    expect(invokeRouting('routeToolkits', ['googlesheets'], true, $conversation))
        ->toBe([['googlesheets'], true]);

    // A genuine spreadsheet question still narrows to Sheets.
    $sheets = routingConversation($user, 'add a row to the budget spreadsheet');
    expect(invokeRouting('routeToolkits', ['googlesheets'], true, $sheets))
        ->toBe([['googlesheets'], false]);

    // ...and a genuine NetSuite question narrows to NetSuite.
    $netsuite = routingConversation($user, 'pull the open invoices for that customer');
    expect(invokeRouting('routeToolkits', ['googlesheets'], true, $netsuite))
        ->toBe([[], true]);
});

// --- the connected-sources prompt block ---------------------------------------

test('the prompt names only the sources that actually shipped', function () {
    $controller = app(ChatController::class);
    $ref = new ReflectionClass($controller);

    $ref->getProperty('activeToolkits')->setValue($controller, ['googlesheets']);
    $ref->getProperty('netsuiteActive')->setValue($controller, false);

    $block = (string) (new ReflectionMethod(ChatController::class, 'connectedToolsBlock'))
        ->invoke($controller);

    // Sheets shipped, NetSuite was routed away — advertising NetSuite here
    // would invite the model to claim it checked something it cannot reach.
    expect($block)->toContain('Google Sheets')
        ->and($block)->not->toContain('NetSuite')
        ->and($block)->toContain('never from general');
});

test('the block is empty when there is nothing to report', function () {
    expect((string) invokeRouting('connectedToolsBlock'))->toBe('');
});

test('suppressed MCP servers are disclosed, not hidden', function () {
    $controller = app(ChatController::class);
    $ref = new ReflectionClass($controller);

    $ref->getProperty('activeToolkits')->setValue($controller, ['googlesheets']);
    $ref->getProperty('suppressedMcp')->setValue($controller, ['Notion']);

    $block = (string) (new ReflectionMethod(ChatController::class, 'connectedToolsBlock'))
        ->invoke($controller);

    expect($block)->toContain('MCP servers paused this turn')
        ->and($block)->toContain('Not available right now: Notion');
});

test('the connected-sources block can be switched off', function () {
    config(['services.anthropic.connected_tools_prompt' => false]);

    $user = User::factory()->create();
    $user->composioConnections()->create(['toolkit' => 'googlesheets', 'status' => 'active']);
    $conversation = routingConversation($user, 'check my spreadsheet');

    $controller = app(ChatController::class);
    (new ReflectionMethod(ChatController::class, 'resolveToolSources'))
        ->invoke($controller, $user, $conversation, false);

    $prompt = (string) (new ReflectionMethod(ChatController::class, 'buildSystemPrompt'))
        ->invoke($controller, $conversation);

    expect($prompt)->not->toContain("The user's connected data");
});

test('resolveToolSources records what shipped for the prompt to use', function () {
    $user = User::factory()->create();
    $user->composioConnections()->create(['toolkit' => 'googlesheets', 'status' => 'active']);
    $conversation = routingConversation($user, 'read my spreadsheet');

    $controller = app(ChatController::class);
    [$keys, $conn] = (new ReflectionMethod(ChatController::class, 'resolveToolSources'))
        ->invoke($controller, $user, $conversation, false);

    $prompt = (string) (new ReflectionMethod(ChatController::class, 'buildSystemPrompt'))
        ->invoke($controller, $conversation);

    expect($keys)->toBe(['googlesheets'])
        ->and($conn)->toBeNull()
        ->and($prompt)->toContain('Google Sheets');
});

test('a private turn advertises no connected data at all', function () {
    $user = User::factory()->create();
    $user->composioConnections()->create(['toolkit' => 'googlesheets', 'status' => 'active']);
    $conversation = routingConversation($user, 'read my spreadsheet');

    $controller = app(ChatController::class);
    [$keys, $conn] = (new ReflectionMethod(ChatController::class, 'resolveToolSources'))
        ->invoke($controller, $user, $conversation, true);

    expect($keys)->toBe([])
        ->and($conn)->toBeNull()
        ->and((string) (new ReflectionMethod(ChatController::class, 'connectedToolsBlock'))
            ->invoke($controller))->toBe('');
});
