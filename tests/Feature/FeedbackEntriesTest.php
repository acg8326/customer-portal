<?php

use App\Models\FeedbackEntry;
use App\Models\User;

test('any member can submit written feedback or a suggestion from the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post('/feedback', ['type' => 'suggestion', 'message' => 'AiMe should read our email inbox.'])
        ->assertRedirect(route('dashboard'));

    $entry = FeedbackEntry::sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->type)->toBe('suggestion')
        ->and($entry->message)->toBe('AiMe should read our email inbox.');
});

test('feedback submissions are validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/dashboard')
        ->post('/feedback', ['type' => 'complaint', 'message' => 'hi'])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('type');

    $this->actingAs($user)
        ->from('/dashboard')
        ->post('/feedback', ['type' => 'feedback', 'message' => ''])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('message');

    expect(FeedbackEntry::count())->toBe(0);
});

test('written entries appear on the super admin dashboard card only', function () {
    $member = User::factory()->create(['name' => 'Maria']);
    $member->feedbackEntries()->create(['type' => 'feedback', 'message' => 'The exports are great.']);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->count('feedback.entries', 1)
            ->where('feedback.entries.0.message', 'The exports are great.')
            ->where('feedback.entries.0.user', 'Maria')
            ->where('feedback.entries.0.type', 'feedback'));

    // Admins and members get no feedback card at all.
    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('feedback', null));
});
