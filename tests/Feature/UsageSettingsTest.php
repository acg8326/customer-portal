<?php

use App\Models\User;
use App\Services\AppSettings;
use App\Services\TokenBudget;

test('UI-set usage settings override the env defaults, clearing falls back', function () {
    config(['usage.token_limit' => 1000, 'usage.period_days' => 30]);

    $user = User::factory()->create();
    $budget = app(TokenBudget::class);

    expect($budget->snapshot($user)['limit'])->toBe(1000);

    app(AppSettings::class)->set('usage.token_limit', '500');
    app(AppSettings::class)->set('usage.period_days', '7');

    $snapshot = $budget->snapshot($user->fresh());

    expect($snapshot['limit'])->toBe(500)
        ->and($snapshot['period_days'])->toBe(7);

    // Clearing the override returns to the .env/config default.
    app(AppSettings::class)->set('usage.token_limit', null);
    expect($budget->snapshot($user->fresh())['limit'])->toBe(1000);
});

test('the stored limit is actually enforced on sends', function () {
    config(['usage.enabled' => true, 'usage.token_limit' => 0]); // env says unlimited

    $user = User::factory()->create();
    $budget = app(TokenBudget::class);

    $budget->record($user, 800);
    expect($budget->exceeded($user->fresh()))->toBeFalse();

    app(AppSettings::class)->set('usage.token_limit', '700'); // UI caps it

    expect($budget->exceeded($user->fresh()))->toBeTrue();
});

// Route-level coverage (usage-settings endpoint authorization, teamUsage
// shape) lives in tests/Feature/AnalyticsTest.php — the settings form and
// team-usage card moved to the Analytics page.
