<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\User;

function shareChat(User $user, string $title = 'Sharable'): Conversation
{
    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = $title;
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    $conversation->messages()->create(['role' => 'user', 'content' => 'What is our Q2 total?']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => 'Q2 total is **$1.2M**.']);

    return $conversation;
}

// --- sharing --------------------------------------------------------------------

test('the owner can share, teammates can view read-only, and unshare kills the link', function () {
    $owner = User::factory()->create();
    $conversation = shareChat($owner);

    $res = $this->actingAs($owner)
        ->postJson("/chat/conversations/{$conversation->id}/share")
        ->assertOk()
        ->assertJsonPath('shared', true);

    $url = $res->json('url');
    expect($url)->toContain('/chat/shared/');

    // Any logged-in member can view the read-only page.
    $this->actingAs(User::factory()->create())
        ->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/Shared')
            ->where('title', 'Sharable')
            ->where('owner', $owner->name)
            ->count('messages', 2)
            ->where('messages.1.content', 'Q2 total is **$1.2M**.'));

    // Logged-out visitors are sent to login — sharing is portal-only.
    auth()->logout();
    $this->get($url)->assertRedirect('/login');

    // Unshare invalidates the link.
    $this->actingAs($owner)
        ->postJson("/chat/conversations/{$conversation->id}/share")
        ->assertOk()
        ->assertJsonPath('shared', false);

    $this->actingAs($owner)->get($url)->assertStatus(404);
});

test('only the owner can toggle sharing', function () {
    $conversation = shareChat(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->postJson("/chat/conversations/{$conversation->id}/share")
        ->assertStatus(404);
});

// --- retention ------------------------------------------------------------------

test('chat:prune deletes conversations past the retention window, keeps recent ones', function () {
    config(['retention.chat_days' => 30]);

    $user = User::factory()->create();
    $old = shareChat($user, 'Ancient');
    $old->timestamps = false;
    $old->updated_at = now()->subDays(40);
    $old->save();

    $recent = shareChat($user, 'Fresh');

    $this->artisan('chat:prune')->assertSuccessful();

    expect(Conversation::withTrashed()->find($old->id))->toBeNull()
        ->and(Conversation::find($recent->id))->not->toBeNull();
});

test('retention off by default: nothing is pruned, but old trash is purged', function () {
    config(['retention.chat_days' => 0, 'retention.trash_days' => 30]);

    $user = User::factory()->create();

    $active = shareChat($user, 'Active but ancient');
    $active->timestamps = false;
    $active->updated_at = now()->subDays(400);
    $active->save();

    $trashed = shareChat($user, 'Old trash');
    $trashed->delete();
    Conversation::withTrashed()->whereKey($trashed->id)
        ->update(['deleted_at' => now()->subDays(45)]);

    $this->artisan('chat:prune')->assertSuccessful();

    expect(Conversation::find($active->id))->not->toBeNull()
        ->and(Conversation::withTrashed()->find($trashed->id))->toBeNull();
});

// --- reply language ---------------------------------------------------------------

test('the reply-language setting persists and reaches the system prompt', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/language', ['language' => 'Tagalog'])
        ->assertRedirect();

    expect($user->fresh()->preferred_language)->toBe('Tagalog');

    $conversation = shareChat($user->fresh());
    $system = (new ReflectionMethod(ChatController::class, 'buildSystemPrompt'))
        ->invoke(app(ChatController::class), $conversation);

    expect($system)->toContain('Always respond in Tagalog');

    // Back to auto → line disappears; unknown languages are rejected.
    $this->actingAs($user)
        ->patch('/settings/language', ['language' => 'auto'])
        ->assertRedirect();
    expect($user->fresh()->preferred_language)->toBeNull();

    // Unknown languages are rejected (Inertia turns the validation failure
    // into a redirect-back) — the invalid value must never persist.
    $this->actingAs($user)->patch('/settings/language', ['language' => 'Klingon']);
    expect($user->fresh()->preferred_language)->toBeNull();
});
