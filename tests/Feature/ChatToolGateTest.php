<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function makeGateConversation(bool $autoApprove = false): Conversation
{
    $user = User::factory()->create();
    $user->composioConnections()->create(['toolkit' => 'slack', 'status' => 'active']);

    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Gate chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->auto_approve = $autoApprove;
    $conversation->save();

    return $conversation;
}

function invokeGate(string $method, mixed ...$args): mixed
{
    $ref = new ReflectionMethod(ChatController::class, $method);

    return $ref->invoke(app(ChatController::class), ...$args);
}

// --- destructive classification ----------------------------------------------

test('write-verb tools are classified destructive; reads are not', function () {
    expect(invokeGate('isDestructiveTool', 'SLACK_SEND_MESSAGE'))->toBeTrue()
        ->and(invokeGate('isDestructiveTool', 'GITHUB_CREATE_ISSUE'))->toBeTrue()
        ->and(invokeGate('isDestructiveTool', 'HUBSPOT_UPDATE_DEAL'))->toBeTrue()
        ->and(invokeGate('isDestructiveTool', 'GITHUB_CLOSE_ISSUE'))->toBeTrue()
        ->and(invokeGate('isDestructiveTool', 'netsuite_suiteql'))->toBeFalse()
        ->and(invokeGate('isDestructiveTool', 'netsuite_get_record'))->toBeFalse()
        ->and(invokeGate('isDestructiveTool', 'SLACK_LIST_CHANNELS'))->toBeFalse()
        ->and(invokeGate('isDestructiveTool', 'SLACK_SEARCH_MESSAGES'))->toBeFalse();
});

test('verb matching is token-based, not substring', function () {
    // "ADDRESS" contains "add" as a substring but not as a token.
    expect(invokeGate('isDestructiveTool', 'SLACK_GET_ADDRESS'))->toBeFalse();
});

// --- gate activation ----------------------------------------------------------

test('the gate is active by default and bypassed by auto-approve or config', function () {
    expect(invokeGate('gateActive', makeGateConversation(false)))->toBeTrue()
        ->and(invokeGate('gateActive', makeGateConversation(true)))->toBeFalse();

    config(['services.anthropic.tool_hard_gate' => false]);
    expect(invokeGate('gateActive', makeGateConversation(false)))->toBeFalse();

    config(['services.anthropic.tool_hard_gate' => true, 'services.anthropic.tool_safety' => false]);
    expect(invokeGate('gateActive', makeGateConversation(false)))->toBeFalse();
});

// --- paused state round-trip --------------------------------------------------

test('the paused loop state survives an encrypted round-trip and rebuilds params', function () {
    $conversation = makeGateConversation();

    $conversation->pending_tool_state = [
        'model' => 'claude-opus-4-8',
        'toolkits' => ['slack'],
        'netsuite' => false,
        'reply' => 'Let me post that…',
        'input_tokens' => 1200,
        'output_tokens' => 80,
        'citations' => [],
        'messages' => [
            ['role' => 'user', 'content' => 'Post hello to #general'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'Let me post that…'],
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'SLACK_SEND_MESSAGE', 'input' => ['channel' => '#general', 'text' => 'hello']],
            ]],
        ],
        'pending' => [
            ['id' => 'tu_1', 'name' => 'SLACK_SEND_MESSAGE', 'input' => ['channel' => '#general', 'text' => 'hello']],
        ],
    ];
    $conversation->save();

    $fresh = $conversation->fresh();

    expect($fresh->pending_tool_state['pending'][0]['name'])->toBe('SLACK_SEND_MESSAGE');

    // The DB column is ciphertext, not plaintext.
    $raw = (string) DB::table('conversations')
        ->where('id', $conversation->id)->value('pending_tool_state');
    expect($raw)->not->toContain('SLACK_SEND_MESSAGE');

    // The mirror rebuilds into SDK params without loss.
    $rebuilt = invokeGate('betaMessagesFromPlain', $fresh->pending_tool_state['messages']);
    expect($rebuilt)->toHaveCount(2);
});

// --- show() exposes the pending card ------------------------------------------

test('show() returns the pending approval calls', function () {
    $conversation = makeGateConversation();
    $conversation->pending_tool_state = [
        'pending' => [['id' => 'tu_1', 'name' => 'SLACK_SEND_MESSAGE', 'input' => ['text' => 'hi']]],
    ];
    $conversation->save();

    $this->actingAs($conversation->user)
        ->getJson("/chat/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('pending_approval.0.name', 'SLACK_SEND_MESSAGE');
});

// --- decision endpoint ---------------------------------------------------------

test('a decision on a conversation with nothing pending 404s', function () {
    $conversation = makeGateConversation();

    $this->actingAs($conversation->user)
        ->postJson("/chat/conversations/{$conversation->id}/tools/decision", ['approve' => true])
        ->assertStatus(404);
});

test('another user cannot decide someone else\'s pending tools', function () {
    $conversation = makeGateConversation();
    $conversation->pending_tool_state = ['pending' => [['id' => 't', 'name' => 'SLACK_SEND_MESSAGE', 'input' => []]]];
    $conversation->save();

    $this->actingAs(User::factory()->create())
        ->postJson("/chat/conversations/{$conversation->id}/tools/decision", ['approve' => true])
        ->assertStatus(404);
});

test('cancelling persists the note, charges tokens, and consumes the state once', function () {
    config(['services.anthropic.key' => 'test-key']);

    $conversation = makeGateConversation();
    $conversation->pending_tool_state = [
        'model' => 'claude-opus-4-8',
        'toolkits' => ['slack'],
        'netsuite' => false,
        'reply' => 'Let me post that…',
        'input_tokens' => 1200,
        'output_tokens' => 80,
        'messages' => [],
        'pending' => [['id' => 'tu_1', 'name' => 'SLACK_SEND_MESSAGE', 'input' => ['text' => 'hi']]],
    ];
    $conversation->save();

    $res = $this->actingAs($conversation->user)
        ->post("/chat/conversations/{$conversation->id}/tools/decision", ['approve' => false])
        ->assertOk();

    $streamed = $res->streamedContent();

    expect($streamed)->toContain('Action cancelled')
        ->and($streamed)->toContain('SLACK_SEND_MESSAGE');

    $fresh = $conversation->fresh();
    $last = $fresh->messages()->orderByDesc('id')->first();

    expect($fresh->pending_tool_state)->toBeNull()
        ->and($last->role)->toBe('assistant')
        ->and($last->content)->toContain('Action cancelled — I did not run: SLACK_SEND_MESSAGE')
        ->and($fresh->prompt_tokens)->toBe(1200)
        ->and($fresh->completion_tokens)->toBe(80);

    // Second decision on the consumed state 404s — no double execution.
    $this->actingAs($conversation->user)
        ->postJson("/chat/conversations/{$conversation->id}/tools/decision", ['approve' => false])
        ->assertStatus(404);
});

test('a new user message clears any paused approval', function () {
    $conversation = makeGateConversation();
    $conversation->pending_tool_state = ['pending' => [['id' => 't', 'name' => 'SLACK_SEND_MESSAGE', 'input' => []]]];
    $conversation->save();

    $request = Request::create('/chat/stream', 'POST', [
        'conversation_id' => $conversation->id,
        'content' => 'never mind, something else',
        'model' => 'claude-opus-4-8',
    ]);
    $request->setUserResolver(fn () => $conversation->user);

    invokeGate('startTurn', $request, false);

    expect($conversation->fresh()->pending_tool_state)->toBeNull();
});
