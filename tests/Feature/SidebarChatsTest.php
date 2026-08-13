<?php

use App\Models\User;

/**
 * The `model` column is NOT NULL, so every chat here needs one. `starred` and
 * `updated_at` aren't fillable — passing them to create() drops them silently,
 * and timestamps would overwrite `updated_at` regardless — so they go on
 * afterwards with the timestamp hook switched off.
 */
function railChat(User $user, string $title, array $extra = []): void
{
    $chat = $user->conversations()->create([
        'title' => $title,
        'model' => 'claude-opus-4-8',
        'project_id' => $extra['project_id'] ?? null,
    ]);

    unset($extra['project_id']);

    if ($extra !== []) {
        $chat->timestamps = false;
        $chat->forceFill($extra)->save();
    }
}

/**
 * `recentChats` is shared on every page so the app rail keeps its shape as you
 * navigate. Being shared is exactly what makes it worth testing: a mistake here
 * leaks one user's chat titles into another's sidebar on *every* route, not
 * just the chat page.
 */
test('the sidebar list is shared on every page, not just chat', function () {
    $user = User::factory()->create();
    railChat($user, 'Sheets sync');

    foreach (['/dashboard', '/chat', '/projects'] as $path) {
        $this->actingAs($user)->get($path)
            ->assertInertia(fn ($page) => $page->has('recentChats', 1)
                ->where('recentChats.0.title', 'Sheets sync'));
    }
});

test('it only carries the signed-in user\'s chats', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    railChat($mine, 'Mine');
    railChat($theirs, 'Theirs');

    $this->actingAs($mine)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->has('recentChats', 1)
            ->where('recentChats.0.title', 'Mine'));
});

test('project chats stay out of the global rail', function () {
    $user = User::factory()->create();
    $project = $user->projects()->create(['name' => 'Ops']);

    railChat($user, 'Loose chat');
    railChat($user, 'Project chat', ['project_id' => $project->id]);

    // A project's chats belong to that project's own list — surfacing them in
    // the global nav would mix two scopes that mean different things.
    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->has('recentChats', 1)
            ->where('recentChats.0.title', 'Loose chat'));
});

test('starred chats sort above the rest, then newest first', function () {
    $user = User::factory()->create();

    railChat($user, 'Older', ['updated_at' => now()->subDay()]);
    railChat($user, 'Newer', ['updated_at' => now()]);
    railChat($user, 'Pinned', ['starred' => true, 'updated_at' => now()->subWeek()]);

    $this->actingAs($user)->get('/chat')
        ->assertInertia(fn ($page) => $page
            ->where('recentChats.0.title', 'Pinned')
            ->where('recentChats.1.title', 'Newer')
            ->where('recentChats.2.title', 'Older'));
});

test('the list is capped so a heavy user does not ship hundreds of rows on every page', function () {
    $user = User::factory()->create();
    for ($i = 0; $i < 35; $i++) {
        railChat($user, "Chat {$i}");
    }

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->has('recentChats', 30));
});

test('it carries only what the rail draws', function () {
    $user = User::factory()->create();
    railChat($user, 'Anything');

    // No transcript, no summary, no model — a shared prop on every request is
    // the wrong place to widen.
    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->has(
            'recentChats.0',
            fn ($chat) => $chat->hasAll(['id', 'title', 'starred'])->etc(),
        ));
});

test('a guest page renders without a user to read chats for', function () {
    $this->get('/login')->assertOk();
});
