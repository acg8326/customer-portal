<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;

function makeChat(User $user, string $title, ?string $updatedAt = null): Conversation
{
    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = $title;
    $conversation->model = 'claude-opus-4-8';

    if ($updatedAt !== null) {
        $conversation->timestamps = false;
        $conversation->created_at = $updatedAt;
        $conversation->updated_at = $updatedAt;
    }

    $conversation->save();

    return $conversation;
}

// --- starred chats -------------------------------------------------------------

test('starring toggles without bumping recency, and unstars on repeat', function () {
    $user = User::factory()->create();
    $conversation = makeChat($user, 'Pin me', '2026-07-01 09:00:00');

    $this->actingAs($user)
        ->postJson("/chat/conversations/{$conversation->id}/star")
        ->assertOk()
        ->assertJson(['starred' => true]);

    $fresh = $conversation->fresh();

    expect($fresh->starred)->toBeTrue()
        // Starring is not activity — recency order must not change.
        ->and($fresh->updated_at->toDateTimeString())->toBe('2026-07-01 09:00:00');

    $this->actingAs($user)
        ->postJson("/chat/conversations/{$conversation->id}/star")
        ->assertOk()
        ->assertJson(['starred' => false]);
});

test('another user cannot star someone else\'s chat', function () {
    $conversation = makeChat(User::factory()->create(), 'Private');

    $this->actingAs(User::factory()->create())
        ->postJson("/chat/conversations/{$conversation->id}/star")
        ->assertStatus(404);
});

test('the sidebar list pins starred chats first, newest within each group', function () {
    $user = User::factory()->create();

    makeChat($user, 'Old plain', '2026-07-01 09:00:00');
    makeChat($user, 'New plain', '2026-07-03 09:00:00');
    $starred = makeChat($user, 'Old but starred', '2026-06-01 09:00:00');

    $starred->starred = true;
    $starred->timestamps = false;
    $starred->save();

    $this->actingAs($user)
        ->get('/chat')
        ->assertInertia(fn ($page) => $page
            ->where('conversations.0.title', 'Old but starred')
            ->where('conversations.0.starred', true)
            ->where('conversations.1.title', 'New plain')
            ->where('conversations.2.title', 'Old plain'));
});

// --- web search toggle -----------------------------------------------------------

test('web=0 drops the web tools for the turn; absent keeps them', function () {
    config(['services.anthropic.web_tools' => true]);

    $user = User::factory()->create();
    $controller = app(ChatController::class);
    $defs = new ReflectionMethod(ChatController::class, 'webToolDefs');

    // Absent → web tools ship.
    expect($defs->invoke($controller))->not->toBe([]);

    $request = Request::create('/chat/stream', 'POST', [
        'content' => 'hi',
        'model' => 'claude-opus-4-8',
        'web' => '0',
    ]);
    $request->setUserResolver(fn () => $user);

    (new ReflectionMethod(ChatController::class, 'startTurn'))->invoke($controller, $request, false);

    // Explicit opt-out → no web tools (and with them, no web-style prompt).
    expect($defs->invoke($controller))->toBe([]);
});

test('the chat page exposes whether web tools are configured', function () {
    config(['services.anthropic.web_tools' => true]);

    $this->actingAs(User::factory()->create())
        ->get('/chat')
        ->assertInertia(fn ($page) => $page->where('webEnabled', true));

    config(['services.anthropic.web_tools' => false]);

    $this->actingAs(User::factory()->create())
        ->get('/chat')
        ->assertInertia(fn ($page) => $page->where('webEnabled', false));
});

// --- dashboard feedback ----------------------------------------------------------

test('only the super admin sees the feedback card, org-wide', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $memberChat = makeChat($member, 'Member chat');
    $memberChat->messages()->create(['role' => 'assistant', 'content' => 'Good answer'])
        ->forceFill(['feedback' => 1])->save();

    $otherChat = makeChat(User::factory()->create(), 'Other chat');
    $otherChat->messages()->create(['role' => 'assistant', 'content' => 'Bad answer'])
        ->forceFill(['feedback' => -1])->save();

    $this->actingAs($superAdmin)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('feedback.up', 1)
            ->where('feedback.down', 1)
            ->count('feedback.recent', 2)
            ->where('feedback.recent.0.user', fn ($name) => filled($name)));

    // Regular admins and members get no card at all.
    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('feedback', null));

    $this->actingAs($member)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('feedback', null));
});

// --- super admin role -------------------------------------------------------------

test('a super admin is an admin too, with the extra flag', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    expect($superAdmin->isAdmin())->toBeTrue()
        ->and($superAdmin->isSuperAdmin())->toBeTrue()
        ->and($admin->isAdmin())->toBeTrue()
        ->and($admin->isSuperAdmin())->toBeFalse()
        ->and($member->isAdmin())->toBeFalse();

    // Admin pages stay open to super admins.
    $this->actingAs($superAdmin)->get('/users')->assertOk();
});

test('an admin cannot remove the super admin; the super admin can remove admins', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete("/users/{$superAdmin->id}")
        ->assertRedirect();

    expect($superAdmin->fresh())->not->toBeNull();

    $this->actingAs($superAdmin)
        ->delete("/users/{$admin->id}")
        ->assertRedirect();

    expect(User::find($admin->id))->toBeNull();
});
