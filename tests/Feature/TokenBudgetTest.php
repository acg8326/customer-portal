<?php

use App\Models\User;
use App\Services\TokenBudget;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['usage.enabled' => true, 'usage.token_limit' => 1000, 'usage.period_days' => 30]);
});

test('recording tokens accumulates usage', function () {
    $user = User::factory()->create();
    $budget = app(TokenBudget::class);

    $budget->record($user, 300);
    $budget->record($user, 200);

    expect($user->fresh()->token_budget_used)->toBe(500);
});

test('a user is not over budget until they hit the limit', function () {
    $user = User::factory()->create();
    $budget = app(TokenBudget::class);

    $budget->record($user, 999);
    expect($budget->exceeded($user->fresh()))->toBeFalse();

    $budget->record($user->fresh(), 1);
    expect($budget->exceeded($user->fresh()))->toBeTrue();
});

test('the window resets after the period elapses', function () {
    $user = User::factory()->create();
    $budget = app(TokenBudget::class);

    $budget->record($user, 900);

    // Move the window start back beyond the period.
    $user->token_budget_started_at = Carbon::now()->subDays(31);
    $user->save();

    // Refresh rolls the window forward and zeroes usage.
    $budget->refresh($user);

    expect($user->fresh()->token_budget_used)->toBe(0)
        ->and($budget->exceeded($user->fresh()))->toBeFalse();
});

test('the snapshot reports remaining tokens and a reset date', function () {
    $user = User::factory()->create();
    $budget = app(TokenBudget::class);

    $budget->record($user, 250);
    $snap = $budget->snapshot($user->fresh());

    expect($snap['limit'])->toBe(1000)
        ->and($snap['used'])->toBe(250)
        ->and($snap['remaining'])->toBe(750)
        ->and($snap['percent'])->toBe(25.0)
        ->and($snap['resets_at'])->not->toBeNull();
});

test('disabling the limit never blocks a user', function () {
    config(['usage.enabled' => false]);

    $user = User::factory()->create();
    $budget = app(TokenBudget::class);

    $budget->record($user, 999999);

    expect($budget->exceeded($user->fresh()))->toBeFalse();
});

test('token_limit 0 is unlimited: still tracks usage but never blocks', function () {
    config(['usage.enabled' => true, 'usage.token_limit' => 0]);

    $user = User::factory()->create();
    $budget = app(TokenBudget::class);

    $budget->record($user, 5_000_000);

    expect($budget->exceeded($user->fresh()))->toBeFalse();

    $snap = $budget->snapshot($user->fresh());

    // Usage is still tracked/shown, but the cap is reported inactive so the
    // dashboard renders the "no limit configured" view.
    expect($snap['used'])->toBe(5_000_000)
        ->and($snap['enabled'])->toBeFalse();
});

test('the dashboard shows the token usage snapshot', function () {
    $user = User::factory()->create();
    app(TokenBudget::class)->record($user, 400);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Dashboard')
                ->where('usage.used', 400)
                ->where('usage.limit', 1000)
                ->has('stats')
        );
});

test('the chat blocks a user who is over budget', function () {
    config(['services.anthropic.key' => 'test-key']);

    $user = User::factory()->create();
    $user->token_budget_used = 1000;
    $user->token_budget_started_at = now();
    $user->save();

    $this->actingAs($user)
        ->postJson('/chat/message', [
            'content' => 'Hello',
            'model' => array_key_first(config('services.anthropic.models')),
        ])
        ->assertStatus(429)
        ->assertJsonStructure(['message', 'usage_limit' => ['used', 'limit', 'resets_at']]);
});
