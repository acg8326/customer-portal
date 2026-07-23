<?php

use App\Models\User;
use App\Services\AppSettings;

test('new chats resolve the model as workspace default, then env', function () {
    config(['services.anthropic.model' => 'claude-opus-4-8']);

    $user = User::factory()->create();

    // Nothing set → .env default.
    $this->actingAs($user)
        ->get('/chat')
        ->assertInertia(fn ($page) => $page->where('defaultModel', 'claude-opus-4-8'));

    // Super admin sets a workspace default — applies to everyone.
    app(AppSettings::class)->set('chat.default_model', 'claude-sonnet-5');

    $this->actingAs($user)
        ->get('/chat')
        ->assertInertia(fn ($page) => $page->where('defaultModel', 'claude-sonnet-5'));
});

test('a workspace default that left the allowed list is skipped', function () {
    config(['services.anthropic.model' => 'claude-opus-4-8']);

    $user = User::factory()->create();
    app(AppSettings::class)->set('chat.default_model', 'model-gone');

    $this->actingAs($user)
        ->get('/chat')
        ->assertInertia(fn ($page) => $page->where('defaultModel', 'claude-opus-4-8'));
});

test('only the super admin can set the workspace default model', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->patch('/analytics/usage-settings', [
            'token_limit' => 0,
            'period_days' => 30,
            'session_token_limit' => 0,
            'session_hours' => 5,
            'weekly_token_limit' => 0,
            'weekly_days' => 7,
            'default_model' => 'claude-sonnet-5',
        ])
        ->assertRedirect();

    expect(app(AppSettings::class)->get('chat.default_model'))->toBe('claude-sonnet-5');

    // Picking "default" clears the override.
    $this->actingAs($superAdmin)
        ->patch('/analytics/usage-settings', [
            'token_limit' => 0,
            'period_days' => 30,
            'session_token_limit' => 0,
            'session_hours' => 5,
            'weekly_token_limit' => 0,
            'weekly_days' => 7,
            'default_model' => 'default',
        ])
        ->assertRedirect();

    expect(app(AppSettings::class)->get('chat.default_model'))->toBeNull();

    $this->actingAs(User::factory()->admin()->create())
        ->patch('/analytics/usage-settings', [
            'token_limit' => 0,
            'period_days' => 30,
            'session_token_limit' => 0,
            'session_hours' => 5,
            'weekly_token_limit' => 0,
            'weekly_days' => 7,
            'default_model' => 'claude-haiku-4-5',
        ])
        ->assertStatus(403);
});

test('an unlisted workspace default is rejected and the dashboard reports the stored one', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->patch('/analytics/usage-settings', [
            'token_limit' => 0,
            'period_days' => 30,
            'session_token_limit' => 0,
            'session_hours' => 5,
            'weekly_token_limit' => 0,
            'weekly_days' => 7,
            'default_model' => 'gpt-4o',
        ]);

    expect(app(AppSettings::class)->get('chat.default_model'))->toBeNull();

    app(AppSettings::class)->set('chat.default_model', 'claude-sonnet-5');

    $this->actingAs($superAdmin)
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page
            ->where('teamUsage.default_model', 'claude-sonnet-5')
            ->where('teamUsage.env_default_model', config('services.anthropic.model')));
});
