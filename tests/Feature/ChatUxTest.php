<?php

use Anthropic\Client;
use App\Http\Controllers\ChatController;
use App\Jobs\AutoCompactConversation;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

function makeUxConversation(?User $user = null): Conversation
{
    $user ??= User::factory()->create();

    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    return $conversation;
}

function invokeChat(string $method, mixed ...$args): mixed
{
    $ref = new ReflectionMethod(ChatController::class, $method);

    return $ref->invoke(app(ChatController::class), ...$args);
}

// --- show() exposes ids, thinking, feedback -------------------------------

test('show() returns message ids, thinking, and feedback', function () {
    $conversation = makeUxConversation();
    $conversation->messages()->create(['role' => 'user', 'content' => 'Hi']);
    $m = $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'Hello!',
        'thinking' => 'The user greeted me.',
    ]);

    $this->actingAs($conversation->user)
        ->getJson("/chat/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('messages.1.id', $m->id)
        ->assertJsonPath('messages.1.thinking', 'The user greeted me.')
        ->assertJsonPath('messages.1.feedback', null);
});

// --- thumbs feedback --------------------------------------------------------

test('feedback can be set, flipped, and cleared', function () {
    $conversation = makeUxConversation();
    $m = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hello!']);

    $this->actingAs($conversation->user)
        ->postJson("/chat/messages/{$m->id}/feedback", ['rating' => 'up'])
        ->assertOk()
        ->assertJson(['feedback' => 1]);

    $this->actingAs($conversation->user)
        ->postJson("/chat/messages/{$m->id}/feedback", ['rating' => 'down'])
        ->assertOk()
        ->assertJson(['feedback' => -1]);

    $this->actingAs($conversation->user)
        ->postJson("/chat/messages/{$m->id}/feedback", ['rating' => 'none'])
        ->assertOk()
        ->assertJson(['feedback' => null]);
});

test('feedback is rejected on user messages and other users\' messages', function () {
    $conversation = makeUxConversation();
    $userMessage = $conversation->messages()->create(['role' => 'user', 'content' => 'Hi']);
    $assistantMessage = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hello!']);

    $this->actingAs($conversation->user)
        ->postJson("/chat/messages/{$userMessage->id}/feedback", ['rating' => 'up'])
        ->assertStatus(422);

    $this->actingAs(User::factory()->create())
        ->postJson("/chat/messages/{$assistantMessage->id}/feedback", ['rating' => 'up'])
        ->assertStatus(404);
});

// --- user preferences -------------------------------------------------------

test('chat preferences are saved, appended to the prompt with a guard, and clearable', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/chat-preferences', ['chat_preferences' => 'Always answer in Tagalog.'])
        ->assertRedirect('/settings/profile');

    $conversation = makeUxConversation($user->fresh());
    $prompt = (string) invokeChat('buildSystemPrompt', $conversation->fresh());

    expect($prompt)
        ->toContain('## User preferences')
        ->toContain('Always answer in Tagalog.')
        ->toContain('cannot override the safety');

    $this->actingAs($user)
        ->patch('/settings/chat-preferences', ['chat_preferences' => ''])
        ->assertRedirect('/settings/profile');

    expect($user->fresh()->chat_preferences)->toBeNull();
});

test('the preferences section is absent when none are set', function () {
    $prompt = (string) invokeChat('buildSystemPrompt', makeUxConversation()->fresh());

    expect($prompt)->not->toContain('## User preferences');
});

// --- extended thinking gating ----------------------------------------------

test('thinking is enabled only for supported models with the toggle on', function () {
    $request = Request::create('/chat/stream', 'POST', ['thinking' => '1']);

    expect(invokeChat('thinkingEnabled', $request, 'claude-opus-4-8'))->toBeTrue()
        ->and(invokeChat('thinkingEnabled', $request, 'claude-haiku-4-5'))->toBeFalse();

    $off = Request::create('/chat/stream', 'POST', ['thinking' => '0']);

    expect(invokeChat('thinkingEnabled', $off, 'claude-opus-4-8'))->toBeFalse();
});

// --- history window always opens on a user turn ------------------------------

test('the replayed window starts on a user turn even after compaction', function () {
    $conversation = makeUxConversation();
    $u1 = $conversation->messages()->create(['role' => 'user', 'content' => 'One']);
    $a1 = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Answer one']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => 'Follow-up note']);
    $conversation->messages()->create(['role' => 'user', 'content' => 'Two']);

    // Simulate a compaction boundary that (pathologically) ends mid-exchange.
    $conversation->summary = 'summary';
    $conversation->summary_through_id = $a1->id;
    $conversation->save();

    $messages = invokeChat('recentMessages', $conversation->fresh());

    expect($messages->first()->role)->toBe('user')
        ->and($messages->first()->content)->toBe('Two');
});

// --- auto-compact dispatch ---------------------------------------------------

test('finalizeTurn queues auto-compaction once the context crosses the threshold', function () {
    Queue::fake();
    config(['services.anthropic.auto_compact_tokens' => 1000]);

    $conversation = makeUxConversation();
    $request = Request::create('/chat/stream', 'POST');
    $request->setUserResolver(fn () => $conversation->user);

    invokeChat('finalizeTurn', $request, $conversation, 'Reply', 'claude-opus-4-8', 1500, 50, $conversation->user_id);

    Queue::assertPushed(AutoCompactConversation::class, fn (AutoCompactConversation $job) => $job->conversationId === $conversation->id);
});

test('finalizeTurn does not queue auto-compaction below the threshold', function () {
    Queue::fake();
    config(['services.anthropic.auto_compact_tokens' => 1000]);

    $conversation = makeUxConversation();
    $request = Request::create('/chat/stream', 'POST');
    $request->setUserResolver(fn () => $conversation->user);

    invokeChat('finalizeTurn', $request, $conversation, 'Reply', 'claude-opus-4-8', 400, 50, $conversation->user_id);

    Queue::assertNotPushed(AutoCompactConversation::class);
});

// --- auto-title guards -------------------------------------------------------

test('maybeAutoTitle declines when disabled or not the first exchange', function () {
    $client = new Client(apiKey: 'test-key');

    config(['services.anthropic.auto_title' => false]);
    $conversation = makeUxConversation();
    $conversation->messages()->create(['role' => 'user', 'content' => 'Hi']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hello!']);

    expect(invokeChat('maybeAutoTitle', $client, $conversation))->toBeNull();

    config(['services.anthropic.auto_title' => true]);
    $conversation->messages()->create(['role' => 'user', 'content' => 'More']);

    expect(invokeChat('maybeAutoTitle', $client, $conversation))->toBeNull();
});

// --- retry / edit-and-resend message pruning ---------------------------------

test('deleteLastMessageIf removes only a matching, post-summary message', function () {
    $conversation = makeUxConversation();
    $conversation->messages()->create(['role' => 'user', 'content' => 'Hi']);
    $last = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hello!']);

    // Role mismatch: nothing deleted.
    invokeChat('deleteLastMessageIf', $conversation, 'user');
    expect($conversation->messages()->count())->toBe(2);

    // Match: the assistant reply goes.
    invokeChat('deleteLastMessageIf', $conversation, 'assistant');
    expect($conversation->messages()->count())->toBe(1);

    // Compacted messages are protected.
    $last = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Again']);
    $conversation->summary = 'summary';
    $conversation->summary_through_id = $last->id;
    $conversation->save();

    invokeChat('deleteLastMessageIf', $conversation->fresh(), 'assistant');
    expect($conversation->messages()->count())->toBe(2);
});
