<?php

use App\Models\Conversation;
use App\Models\User;

function makeConversation(User $user, string $title, array $messages = []): Conversation
{
    $c = new Conversation;
    $c->user_id = $user->id;
    $c->title = $title;
    $c->model = 'claude-opus-4-8';
    $c->save();

    foreach ($messages as [$role, $content]) {
        $c->messages()->create(['role' => $role, 'content' => $content]);
    }

    return $c;
}

test('search matches conversation titles', function () {
    $user = User::factory()->create();
    makeConversation($user, 'Invoice questions');
    makeConversation($user, 'Holiday plans');

    $this->actingAs($user)
        ->getJson('/chat/search?q=invoice')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.title', 'Invoice questions');
});

test('search matches message content with a snippet', function () {
    $user = User::factory()->create();
    makeConversation($user, 'Untitled', [
        ['user', 'Can you explain the warranty terms for the RMA process?'],
        ['assistant', 'Sure, here are the details.'],
    ]);

    $res = $this->actingAs($user)->getJson('/chat/search?q=warranty')->assertOk();

    expect($res->json('results'))->toHaveCount(1)
        ->and($res->json('results.0.snippet'))->toContain('warranty');
});

test('search only returns the current user\'s conversations', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    makeConversation($me, 'Shared keyword here');
    makeConversation($other, 'Shared keyword here');

    $this->actingAs($me)
        ->getJson('/chat/search?q=keyword')
        ->assertOk()
        ->assertJsonCount(1, 'results');
});

test('an empty query returns no results', function () {
    $user = User::factory()->create();
    makeConversation($user, 'Something');

    $this->actingAs($user)
        ->getJson('/chat/search?q=')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});
