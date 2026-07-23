<?php

use App\Models\User;

test('an admin-created account must change its password', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/users', [
        'name' => 'New Person',
        'email' => 'new.person@cwglobalpeople.com',
        'password' => 'a-strong-generated-password-1!',
        'role' => 'user',
    ])->assertSessionHasNoErrors();

    $created = User::where('email', 'new.person@cwglobalpeople.com')->first();

    expect($created->must_change_password)->toBeTrue();
});

test('a user who must change their password is redirected away from other pages', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user)->get('/dashboard')->assertRedirect('/settings/security');
    $this->actingAs($user)->get('/chat')->assertRedirect('/settings/security');
});

test('a user who must change their password can still reach the security page', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    // Bypass the separate "confirm your current password" gate on this
    // route — unrelated to the flag under test here.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/Security')->where('mustChangePassword', true));
});

test('updating the password clears the flag and unblocks the app', function () {
    $user = User::factory()->create(['must_change_password' => true, 'password' => 'old-password-123!']);

    $this->actingAs($user)
        ->put('/settings/password', [
            'current_password' => 'old-password-123!',
            'password' => 'a-brand-new-password-1!',
            'password_confirmation' => 'a-brand-new-password-1!',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->must_change_password)->toBeFalse();

    $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
});

test('a user who does not need to change their password is unaffected', function () {
    $user = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($user)->get('/dashboard')->assertOk();
});
