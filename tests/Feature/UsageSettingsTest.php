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

test('only the super admin can change usage settings', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->patch('/dashboard/usage-settings', ['token_limit' => 2000000, 'period_days' => 14])
        ->assertRedirect();

    expect(app(AppSettings::class)->get('usage.token_limit'))->toBe('2000000')
        ->and(app(AppSettings::class)->get('usage.period_days'))->toBe('14');

    $this->actingAs(User::factory()->admin()->create())
        ->patch('/dashboard/usage-settings', ['token_limit' => 1, 'period_days' => 1])
        ->assertStatus(403);

    $this->actingAs(User::factory()->create())
        ->patch('/dashboard/usage-settings', ['token_limit' => 1, 'period_days' => 1])
        ->assertStatus(403);
});

test('the dashboard shows per-user usage and the org total to the super admin only', function () {
    $superAdmin = User::factory()->superAdmin()->create(['name' => 'Root']);
    $heavy = User::factory()->create(['name' => 'Heavy User']);
    $light = User::factory()->create(['name' => 'Light User']);

    $budget = app(TokenBudget::class);
    $budget->record($heavy, 9000);
    $budget->record($light, 100);

    $this->actingAs($superAdmin)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('teamUsage.total', 9100)
            ->count('teamUsage.users', 3)
            // Heaviest first.
            ->where('teamUsage.users.0.name', 'Heavy User')
            ->where('teamUsage.users.0.used', 9000));

    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('teamUsage', null));
});
