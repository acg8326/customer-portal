<?php

use App\Models\RequestLog;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\TokenBudget;

// --- Authorization ----------------------------------------------------------------

test('guests are redirected to the login page', function () {
    $this->get('/analytics')->assertRedirect(route('login'));
});

test('a plain user cannot view analytics', function () {
    $this->actingAs(User::factory()->create())
        ->get('/analytics')
        ->assertStatus(403);
});

test('a plain admin cannot view analytics (stricter than the admin-only Users page)', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/analytics')
        ->assertStatus(403);
});

test('the super admin can view analytics', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get('/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Analytics')
            ->has('teamUsage')
            ->has('costEfficiency')
            ->has('rateLimits')
            ->has('logs'));
});

// --- Team usage ---------------------------------------------------------------------

test('analytics shows per-user usage and the org total', function () {
    $superAdmin = User::factory()->superAdmin()->create(['name' => 'Root']);
    $heavy = User::factory()->create(['name' => 'Heavy User']);
    $light = User::factory()->create(['name' => 'Light User']);

    $budget = app(TokenBudget::class);
    $budget->record($heavy, 9000);
    $budget->record($light, 100);

    $this->actingAs($superAdmin)
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page
            ->where('teamUsage.total', 9100)
            ->count('teamUsage.users', 3)
            // Heaviest first.
            ->where('teamUsage.users.0.name', 'Heavy User')
            ->where('teamUsage.users.0.used', 9000));
});

test('the team usage card exposes each user\'s model and limit', function () {
    $superAdmin = User::factory()->superAdmin()->create(['name' => 'Root']);
    User::factory()->create([
        'name' => 'Pinned Dev',
        'assigned_model' => 'claude-haiku-4-5',
        'token_limit' => 300000,
    ]);

    $this->actingAs($superAdmin)
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page
            ->has('teamUsage.users', 2)
            ->where('teamUsage.users', fn ($users) => collect($users)->contains(
                fn ($u) => $u['name'] === 'Pinned Dev'
                    && $u['assigned_model'] === 'claude-haiku-4-5'
                    && $u['token_limit'] === 300000
                    && $u['effective_limit'] === 300000
            )));
});

// --- Workspace-wide usage settings --------------------------------------------------

// The full, always-required usage-settings payload (all three tiers).
function usageSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'token_limit' => 2000000,
        'period_days' => 14,
        'session_token_limit' => 0,
        'session_hours' => 5,
        'weekly_token_limit' => 0,
        'weekly_days' => 7,
    ], $overrides);
}

test('only the super admin can change usage settings', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->patch('/analytics/usage-settings', usageSettingsPayload())
        ->assertRedirect();

    expect(app(AppSettings::class)->get('usage.token_limit'))->toBe('2000000')
        ->and(app(AppSettings::class)->get('usage.period_days'))->toBe('14');

    $this->actingAs(User::factory()->admin()->create())
        ->patch('/analytics/usage-settings', usageSettingsPayload(['token_limit' => 1, 'period_days' => 1]))
        ->assertStatus(403);

    $this->actingAs(User::factory()->create())
        ->patch('/analytics/usage-settings', usageSettingsPayload(['token_limit' => 1, 'period_days' => 1]))
        ->assertStatus(403);
});

// --- Per-user model + limit ----------------------------------------------------------

test('the super admin can set a user model and limit; others cannot', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    $this->actingAs($superAdmin)
        ->patch("/analytics/users/{$target->id}/limits", [
            'assigned_model' => 'claude-haiku-4-5',
            'token_limit' => 250000,
        ])
        ->assertRedirect();

    $target->refresh();
    expect($target->assigned_model)->toBe('claude-haiku-4-5')
        ->and($target->token_limit)->toBe(250000);

    // 'default' + blank limit clears both overrides.
    $this->actingAs($superAdmin)
        ->patch("/analytics/users/{$target->id}/limits", ['assigned_model' => 'default'])
        ->assertRedirect();

    $target->refresh();
    expect($target->assigned_model)->toBeNull()
        ->and($target->token_limit)->toBeNull();

    $this->actingAs(User::factory()->admin()->create())
        ->patch("/analytics/users/{$target->id}/limits", ['assigned_model' => 'claude-opus-4-8'])
        ->assertStatus(403);
});

// --- Logs ---------------------------------------------------------------------------

test('the logs section filters by surface', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $other = User::factory()->create();

    RequestLog::create(['user_id' => $other->id, 'surface' => 'chat', 'status' => 200]);
    RequestLog::create(['user_id' => $other->id, 'surface' => 'gateway', 'status' => 200]);

    $this->actingAs($superAdmin)
        ->get('/analytics?log_surface=gateway')
        ->assertInertia(fn ($page) => $page
            ->count('logs.data', 1)
            ->where('logs.data.0.surface', 'gateway'));
});

test('the logs section filters by status bucket', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $other = User::factory()->create();

    RequestLog::create(['user_id' => $other->id, 'surface' => 'chat', 'status' => 200]);
    RequestLog::create(['user_id' => $other->id, 'surface' => 'chat', 'status' => 429]);
    RequestLog::create(['user_id' => $other->id, 'surface' => 'chat', 'status' => 502]);

    $this->actingAs($superAdmin)
        ->get('/analytics?log_status=4xx')
        ->assertInertia(fn ($page) => $page
            ->count('logs.data', 1)
            ->where('logs.data.0.status', 429));
});

test('the logs section filters by member', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $target = User::factory()->create(['name' => 'Target']);
    $other = User::factory()->create(['name' => 'Other']);

    RequestLog::create(['user_id' => $target->id, 'surface' => 'chat', 'status' => 200]);
    RequestLog::create(['user_id' => $other->id, 'surface' => 'chat', 'status' => 200]);

    $this->actingAs($superAdmin)
        ->get("/analytics?log_user={$target->id}")
        ->assertInertia(fn ($page) => $page
            ->count('logs.data', 1)
            ->where('logs.data.0.user', 'Target'));
});

test('the logs section paginates past 25 rows', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $other = User::factory()->create();

    foreach (range(1, 30) as $i) {
        RequestLog::create(['user_id' => $other->id, 'surface' => 'chat', 'status' => 200]);
    }

    $this->actingAs($superAdmin)
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page
            ->count('logs.data', 25)
            ->where('logs.total', 30)
            ->where('logs.last_page', 2));
});
