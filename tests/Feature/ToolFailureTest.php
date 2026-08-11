<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ToolFailure;

test('it names the system from the tool slug', function () {
    config(['services.composio.toolkits.googlesheets.name' => 'Google Sheets']);

    expect(ToolFailure::from('GOOGLESHEETS_BATCH_GET', 'x')->source)->toBe('Google Sheets')
        ->and(ToolFailure::from('SLACK_SEND_MESSAGE', 'x')->source)->toBe('Slack')
        ->and(ToolFailure::from('netsuite_suiteql', 'x')->source)->toBe('NetSuite');
});

test('an unknown prefix still produces something readable', function () {
    // Never "undefined" or an empty string in the card header.
    expect(ToolFailure::from('WIDGETCO_DO_THING', 'x')->source)->toBe('Widgetco');
});

test('a missing OAuth scope is called out as a permission to re-grant', function () {
    $failure = ToolFailure::from(
        'SLACK_LIST_CHANNELS',
        '{"error":"missing_scope","needed":"channels:read","provided":"chat:write"}',
    );

    expect($failure->kind)->toBe('Missing permission')
        ->and($failure->fix)->toContain('Reconnect it')
        // The provider's own message survives — that's where "channels:read" is.
        ->and($failure->detail)->toContain('channels:read');
});

test('common provider failures are classified with an actionable fix', function () {
    $cases = [
        ['HTTP 401 Unauthorized', 'Not signed in'],
        ['403 Forbidden: the caller does not have permission', 'Permission denied'],
        ['Requested entity was not found.', 'Not found'],
        ['429 Too Many Requests', 'Rate limited'],
        ['invalid_grant: token expired', 'Connection expired'],
        ['Could not reach the tool: cURL error 28', 'Could not reach it'],
        ['400 Invalid value at range', 'Request rejected'],
    ];

    foreach ($cases as [$raw, $kind]) {
        $failure = ToolFailure::from('GOOGLESHEETS_BATCH_GET', $raw);

        expect($failure->kind)->toBe($kind, "classifying: {$raw}")
            ->and($failure->fix)->not->toBe('');
    }
});

test('the source name is substituted into the fix text', function () {
    config(['services.composio.toolkits.googlesheets.name' => 'Google Sheets']);

    expect(ToolFailure::from('GOOGLESHEETS_CLEAR_VALUES', '403 forbidden')->fix)
        ->toContain('Google Sheets')
        ->not->toContain(':source');
});

test('an unrecognised error still gets a card rather than being swallowed', function () {
    $failure = ToolFailure::from('HUBSPOT_GET_DEAL', 'Something entirely unexpected happened');

    expect($failure->kind)->toBe('Tool error')
        ->and($failure->detail)->toBe('Something entirely unexpected happened')
        ->and($failure->fix)->not->toBe('');
});

test('a long provider message is capped, and an empty one still says something', function () {
    config(['services.anthropic.tool_errors.max_detail_chars' => 50]);

    expect(mb_strlen(ToolFailure::from('SLACK_X', str_repeat('a', 500))->detail))->toBe(51)
        ->and(ToolFailure::from('SLACK_X', '   ')->detail)->toBe('No message returned.');
});

// --- persistence --------------------------------------------------------------

test('tool errors survive a reload and reach the browser', function () {
    $user = User::factory()->create();

    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Errors';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    $conversation->messages()->create(['role' => 'user', 'content' => 'list my channels']);
    $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'I could not read your channels.',
        'tool_errors' => [ToolFailure::from('SLACK_LIST_CHANNELS', 'missing_scope: channels:read')->toArray()],
    ]);

    // The whole point of persisting: the user reloads on their way to fix the
    // permission, and the card telling them WHICH permission is still there.
    $this->actingAs($user)
        ->getJson("/chat/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('messages.1.tool_errors.0.kind', 'Missing permission')
        ->assertJsonPath('messages.1.tool_errors.0.source', 'Slack');
});

test('a shared conversation does not leak tool errors', function () {
    $user = User::factory()->create();

    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Errors';
    $conversation->model = 'claude-opus-4-8';
    $conversation->share_token = 'tok-'.uniqid();
    $conversation->save();

    $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'Failed.',
        'tool_errors' => [ToolFailure::from('SLACK_X', 'account acme-corp is not authorized')->toArray()],
    ]);

    // Error detail can name accounts, ids and internal endpoints. A share link
    // is for the conversation, not the owner's connection troubles — so a
    // colleague opening it must not see any of that.
    $this->actingAs(User::factory()->create())
        ->get("/chat/shared/{$conversation->share_token}")
        ->assertOk()
        ->assertDontSee('acme-corp');
});

test('the feature can be switched off', function () {
    config(['services.anthropic.tool_errors.enabled' => false]);

    $controller = app(ChatController::class);
    $record = new ReflectionMethod($controller, 'recordToolFailure');
    $record->invoke($controller, 'SLACK_X', '403 forbidden');

    $prop = (new ReflectionClass($controller))->getProperty('toolFailures');

    expect($prop->getValue($controller))->toBe([]);
});

test('a repeated identical failure is shown once, not once per retry', function () {
    $controller = app(ChatController::class);
    $record = new ReflectionMethod($controller, 'recordToolFailure');

    $record->invoke($controller, 'SLACK_X', '403 forbidden');
    $record->invoke($controller, 'SLACK_X', '403 forbidden');
    $record->invoke($controller, 'SLACK_X', '404 not found');

    $failures = (new ReflectionClass($controller))->getProperty('toolFailures')
        ->getValue($controller);

    expect($failures)->toHaveCount(2);
});
