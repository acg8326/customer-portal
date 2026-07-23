<?php

use App\Http\Controllers\ChatController;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\TokenBudget;

// --- Per-user token limit -------------------------------------------------------

test('a per-user token limit overrides the workspace limit', function () {
    config(['usage.enabled' => true, 'usage.token_limit' => 1_000_000]);

    $user = User::factory()->create(['token_limit' => 500]);
    $budget = app(TokenBudget::class);

    expect($budget->snapshot($user)['limit'])->toBe(500);

    $budget->record($user, 499);
    expect($budget->exceeded($user->fresh()))->toBeFalse();

    $budget->record($user->fresh(), 1);
    expect($budget->exceeded($user->fresh()))->toBeTrue();
});

test('a null per-user limit inherits the workspace limit', function () {
    config(['usage.token_limit' => 1000]);
    app(AppSettings::class)->set('usage.token_limit', '2000');

    $user = User::factory()->create(['token_limit' => null]);

    expect(app(TokenBudget::class)->snapshot($user)['limit'])->toBe(2000);
});

test('a per-user limit of 0 means unlimited for that user even when the workspace caps', function () {
    config(['usage.enabled' => true, 'usage.token_limit' => 500]);

    $user = User::factory()->create(['token_limit' => 0]);
    $budget = app(TokenBudget::class);

    $budget->record($user, 10_000);

    expect($budget->exceeded($user->fresh()))->toBeFalse()
        ->and($budget->snapshot($user->fresh())['enabled'])->toBeFalse();
});

// --- Per-user assigned model ----------------------------------------------------

test('effectiveModel forces a pinned user onto their model', function () {
    $pinned = User::factory()->create(['assigned_model' => 'claude-haiku-4-5']);
    $free = User::factory()->create(['assigned_model' => null]);

    expect(ChatController::effectiveModel($pinned, 'claude-opus-4-8'))->toBe('claude-haiku-4-5')
        ->and(ChatController::effectiveModel($free, 'claude-opus-4-8'))->toBe('claude-opus-4-8');
});

test('an assigned model that fell out of the allowed list is ignored', function () {
    config(['services.anthropic.models' => ['claude-opus-4-8' => 'Opus 4.8']]);

    $user = User::factory()->create(['assigned_model' => 'retired-model']);

    // Falls back to what was requested rather than stranding the user.
    expect(ChatController::effectiveModel($user, 'claude-opus-4-8'))->toBe('claude-opus-4-8');
});

test('the chat page sends the locked model to a pinned user', function () {
    $user = User::factory()->create(['assigned_model' => 'claude-haiku-4-5']);

    $this->actingAs($user)
        ->get('/chat')
        ->assertInertia(fn ($page) => $page
            ->component('Chat')
            ->where('lockedModel', 'claude-haiku-4-5')
            ->where('defaultModel', 'claude-haiku-4-5'));

    $free = User::factory()->create(['assigned_model' => null]);

    $this->actingAs($free)
        ->get('/chat')
        ->assertInertia(fn ($page) => $page->where('lockedModel', null));
});

// Route-level coverage (updateUserLimits endpoint authorization, teamUsage
// row shape) lives in tests/Feature/AnalyticsTest.php — the per-user editor
// and team-usage card moved to the Analytics page.
