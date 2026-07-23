<?php

use App\Models\User;
use App\Services\TokenBudget;
use Illuminate\Support\Carbon;

test('recording tokens accumulates usage across period, session, and weekly simultaneously', function () {
    $user = User::factory()->create();
    $budget = app(TokenBudget::class);

    $budget->record($user, 500);

    $snapshot = $budget->snapshot($user->fresh());

    expect($snapshot['period']['used'])->toBe(500)
        ->and($snapshot['session']['used'])->toBe(500)
        ->and($snapshot['weekly']['used'])->toBe(500);
});

test('a user is blocked when only the session tier is exhausted', function () {
    config(['usage.enabled' => true, 'usage.token_limit' => 0, 'usage.weekly_token_limit' => 0]);

    $user = User::factory()->create(['session_token_limit' => 100]);
    $budget = app(TokenBudget::class);

    $budget->record($user, 100);

    expect($budget->exceeded($user->fresh()))->toBeTrue()
        ->and($budget->firstExceededTier($user->fresh()))->toBe('session');
});

test('a user is blocked when only the weekly tier is exhausted', function () {
    config(['usage.enabled' => true, 'usage.token_limit' => 0, 'usage.session_token_limit' => 0]);

    $user = User::factory()->create(['weekly_token_limit' => 100]);
    $budget = app(TokenBudget::class);

    $budget->record($user, 100);

    expect($budget->exceeded($user->fresh()))->toBeTrue()
        ->and($budget->firstExceededTier($user->fresh()))->toBe('weekly');
});

test('firstExceededTier reports session before weekly before period', function () {
    $user = User::factory()->create([
        'token_limit' => 100,
        'session_token_limit' => 100,
        'weekly_token_limit' => 100,
    ]);
    $budget = app(TokenBudget::class);

    $budget->record($user, 100);

    // All three are exhausted at once — session wins the priority order.
    expect($budget->firstExceededTier($user->fresh()))->toBe('session');
});

test('firstExceededTier returns null when usage.enabled is false', function () {
    config(['usage.enabled' => false]);

    $user = User::factory()->create(['session_token_limit' => 10]);
    $budget = app(TokenBudget::class);

    $budget->record($user, 10);

    expect($budget->firstExceededTier($user->fresh()))->toBeNull();
});

test('the session window resets after usage.session_hours elapses', function () {
    config(['usage.session_hours' => 5]);

    $user = User::factory()->create(['session_token_limit' => 100]);
    $budget = app(TokenBudget::class);

    $budget->record($user, 100);
    expect($budget->exceeded($user->fresh()))->toBeTrue();

    // Backdate the session window past its 5-hour duration.
    $user->fresh()->forceFill(['session_budget_started_at' => Carbon::now()->subHours(6)])->save();

    $budget->refresh($user->fresh());

    expect($user->fresh()->session_budget_used)->toBe(0)
        ->and($budget->exceeded($user->fresh()))->toBeFalse();
});

test('the weekly window resets after usage.weekly_days elapses', function () {
    config(['usage.weekly_days' => 7]);

    $user = User::factory()->create(['weekly_token_limit' => 100]);
    $budget = app(TokenBudget::class);

    $budget->record($user, 100);
    expect($budget->exceeded($user->fresh()))->toBeTrue();

    $user->fresh()->forceFill(['weekly_budget_started_at' => Carbon::now()->subDays(8)])->save();

    $budget->refresh($user->fresh());

    expect($user->fresh()->weekly_budget_used)->toBe(0)
        ->and($budget->exceeded($user->fresh()))->toBeFalse();
});

test('snapshot exposes period, session, and weekly alongside the legacy flat fields', function () {
    $user = User::factory()->create();
    $budget = app(TokenBudget::class);

    $snapshot = $budget->snapshot($user);

    expect($snapshot)->toHaveKeys(['enabled', 'used', 'limit', 'remaining', 'percent', 'resets_at', 'period_days'])
        ->and($snapshot['period'])->toHaveKeys(['enabled', 'used', 'limit', 'remaining', 'percent', 'resets_at', 'period_days'])
        ->and($snapshot['session'])->toHaveKeys(['enabled', 'used', 'limit', 'remaining', 'percent', 'resets_at', 'session_hours'])
        ->and($snapshot['weekly'])->toHaveKeys(['enabled', 'used', 'limit', 'remaining', 'percent', 'resets_at', 'weekly_days']);
});

test('session_token_limit of 0 is unlimited for that user while weekly/period still enforce', function () {
    config(['usage.enabled' => true, 'usage.weekly_token_limit' => 50]);

    $user = User::factory()->create(['session_token_limit' => 0]);
    $budget = app(TokenBudget::class);

    $budget->record($user, 1000);

    // Weekly caps at 50 and is exhausted; session (unlimited) never blocks on
    // its own, but the user is still blocked overall by the weekly tier.
    expect($budget->firstExceededTier($user->fresh()))->toBe('weekly');
});

test('weekly_token_limit of 0 is unlimited for that user while session/period still enforce', function () {
    config(['usage.enabled' => true, 'usage.session_token_limit' => 50]);

    $user = User::factory()->create(['weekly_token_limit' => 0]);
    $budget = app(TokenBudget::class);

    $budget->record($user, 1000);

    expect($budget->firstExceededTier($user->fresh()))->toBe('session');
});

test('disabling usage.enabled bypasses all three tiers', function () {
    config(['usage.enabled' => false]);

    $user = User::factory()->create([
        'token_limit' => 10,
        'session_token_limit' => 10,
        'weekly_token_limit' => 10,
    ]);
    $budget = app(TokenBudget::class);

    $budget->record($user, 1000);

    expect($budget->exceeded($user->fresh()))->toBeFalse();
});

test('the chat 429 names the session window when that tier is the one exhausted', function () {
    config(['services.anthropic.key' => 'test-key']);

    $user = User::factory()->create(['session_token_limit' => 10]);
    app(TokenBudget::class)->record($user, 10);

    $this->actingAs($user)
        ->postJson('/chat/message', [
            'content' => 'Hello',
            'model' => array_key_first(config('services.anthropic.models')),
        ])
        ->assertStatus(429)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'session'));
});

test('the chat 429 names the week when the weekly tier is the one exhausted', function () {
    config(['services.anthropic.key' => 'test-key']);

    $user = User::factory()->create(['weekly_token_limit' => 10]);
    app(TokenBudget::class)->record($user, 10);

    $this->actingAs($user)
        ->postJson('/chat/message', [
            'content' => 'Hello',
            'model' => array_key_first(config('services.anthropic.models')),
        ])
        ->assertStatus(429)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'week'));
});
