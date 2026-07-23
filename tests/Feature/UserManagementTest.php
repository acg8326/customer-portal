<?php

use App\Models\User;

test('isAdmin reflects the role', function () {
    expect(User::factory()->admin()->create()->isAdmin())->toBeTrue()
        ->and(User::factory()->create()->isAdmin())->toBeFalse();
});

test('an admin can view the users page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Users')->has('users'));
});

test('the super admin gets governance data and a model list on the users page', function () {
    $super = User::factory()->superAdmin()->create();
    User::factory()->create(['assigned_model' => 'claude-haiku-4-5', 'token_limit' => 500000]);

    $this->actingAs($super)
        ->get('/users')
        ->assertInertia(fn ($page) => $page
            ->component('Users')
            ->where('canGovern', true)
            ->has('models')
            ->where('users', fn ($users) => collect($users)->contains(
                fn ($u) => $u['assigned_model'] === 'claude-haiku-4-5' && $u['token_limit'] === 500000,
            )));
});

test('a plain admin cannot govern models/limits on the users page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/users')
        ->assertInertia(fn ($page) => $page->component('Users')->where('canGovern', false));
});

test('a non-admin cannot access user management', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/users')->assertForbidden();
    $this->actingAs($user)->post('/users', [
        'name' => 'X', 'email' => 'x@example.com', 'password' => 'password123', 'role' => 'user',
    ])->assertForbidden();

    expect(User::where('email', 'x@example.com')->exists())->toBeFalse();
});

test('an admin can add a user with a role', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post('/users', [
            'name' => 'New Person',
            'email' => 'new.person@cwglobalpeople.com',
            'password' => 'password123',
            'role' => 'admin',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $created = User::where('email', 'new.person@cwglobalpeople.com')->first();

    expect($created)->not->toBeNull()
        ->and($created->isAdmin())->toBeTrue()
        ->and($created->email_verified_at)->not->toBeNull(); // pre-verified
});

test('adding a user validates email uniqueness and role', function () {
    $admin = User::factory()->admin()->create();
    $existing = User::factory()->create();

    $this->actingAs($admin)->post('/users', [
        'name' => 'Dup', 'email' => $existing->email, 'password' => 'password123', 'role' => 'user',
    ])->assertSessionHasErrors('email');

    $this->actingAs($admin)->post('/users', [
        'name' => 'Bad role', 'email' => 'bad@example.com', 'password' => 'password123', 'role' => 'superuser',
    ])->assertSessionHasErrors('role');
});

test('an admin can remove another user but not themselves', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    $this->actingAs($admin)->delete("/users/{$other->id}")->assertRedirect();
    expect(User::whereKey($other->id)->exists())->toBeFalse();

    $this->actingAs($admin)->delete("/users/{$admin->id}")->assertRedirect()->assertSessionHas('error');
    expect(User::whereKey($admin->id)->exists())->toBeTrue();
});

test('the seeder creates the two admins', function () {
    $this->seed();

    expect(User::where('email', 'alex.gordo@cwglobalpeople.com')->first()?->isAdmin())->toBeTrue()
        ->and(User::where('email', 'dennies.salenga@cwglobalpeople.com')->first()?->isAdmin())->toBeTrue();
});
