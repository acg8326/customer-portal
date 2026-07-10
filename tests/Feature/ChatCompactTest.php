<?php

use App\Models\Conversation;
use App\Models\User;

/**
 * Build a conversation owned by $user with a given number of messages.
 */
function makeCompactConversation(User $user, int $messages = 2): Conversation
{
    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Test chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    for ($i = 0; $i < $messages; $i++) {
        $conversation->messages()->create([
            'role' => $i % 2 === 0 ? 'user' : 'assistant',
            'content' => 'Message '.$i,
        ]);
    }

    return $conversation;
}

test('compacting requires authentication', function () {
    $user = User::factory()->create();
    $conversation = makeCompactConversation($user);

    $this->postJson("/chat/conversations/{$conversation->id}/compact")
        ->assertStatus(401);
});

test('a user cannot compact someone else\'s conversation', function () {
    config(['services.anthropic.key' => 'test-key']);

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $conversation = makeCompactConversation($owner);

    $this->actingAs($other)
        ->postJson("/chat/conversations/{$conversation->id}/compact")
        ->assertStatus(404);
});

test('compacting reports when the chat is not configured', function () {
    config(['services.anthropic.key' => null]);

    $user = User::factory()->create();
    $conversation = makeCompactConversation($user);

    $this->actingAs($user)
        ->postJson("/chat/conversations/{$conversation->id}/compact")
        ->assertStatus(503);
});

test('a conversation that is too short cannot be compacted', function () {
    config(['services.anthropic.key' => 'test-key']);

    $user = User::factory()->create();
    $conversation = makeCompactConversation($user, 1);

    $this->actingAs($user)
        ->postJson("/chat/conversations/{$conversation->id}/compact")
        ->assertStatus(422);
});

test('a compacted conversation replays only messages after the summary', function () {
    $user = User::factory()->create();
    $conversation = makeCompactConversation($user, 4);

    // Pretend the first two messages have been folded into a summary.
    $secondId = $conversation->messages()->orderBy('id')->skip(1)->first()->id;
    $conversation->summary = 'The user asked about X; the assistant answered Y.';
    $conversation->summary_through_id = $secondId;
    $conversation->save();

    // show() flags the conversation as compacted for the UI.
    $this->actingAs($user)
        ->getJson("/chat/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJson(['compacted' => true])
        ->assertJsonCount(4, 'messages');
});
