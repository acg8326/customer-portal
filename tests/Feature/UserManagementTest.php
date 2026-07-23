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

test('adding a user requires a password', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/users', [
        'name' => 'No Password', 'email' => 'no.password@cwglobalpeople.com', 'role' => 'user',
    ])->assertSessionHasErrors('password');

    expect(User::where('email', 'no.password@cwglobalpeople.com')->exists())->toBeFalse();
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

test('an admin can edit a user name, email, and role', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Old', 'role' => 'user']);

    $this->actingAs($admin)
        ->patch("/users/{$target->id}", [
            'name' => 'New Name',
            'email' => 'new.email@cwglobalpeople.com',
            'role' => 'admin',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $target->refresh();
    expect($target->name)->toBe('New Name')
        ->and($target->email)->toBe('new.email@cwglobalpeople.com')
        ->and($target->role)->toBe('admin');
});

test('editing keeps the same email valid and enforces uniqueness against others', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'keep@cwglobalpeople.com']);
    $other = User::factory()->create(['email' => 'taken@cwglobalpeople.com']);

    // Same email as before → allowed (ignore self).
    $this->actingAs($admin)
        ->patch("/users/{$target->id}", ['name' => 'Same', 'email' => $target->email, 'role' => 'user'])
        ->assertSessionHasNoErrors();

    // Another user's email → rejected.
    $this->actingAs($admin)
        ->patch("/users/{$target->id}", ['name' => 'Clash', 'email' => $other->email, 'role' => 'user'])
        ->assertSessionHasErrors('email');
});

test('a plain admin cannot edit a super admin', function () {
    $admin = User::factory()->admin()->create();
    $super = User::factory()->superAdmin()->create(['name' => 'Boss']);

    $this->actingAs($admin)
        ->patch("/users/{$super->id}", ['name' => 'Hacked', 'email' => $super->email])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($super->fresh()->name)->toBe('Boss');
});

test('a super admin role is preserved even if a role is posted', function () {
    $super = User::factory()->superAdmin()->create();
    $target = User::factory()->superAdmin()->create(['name' => 'Other super']);

    // Editing another super admin: name/email change, role stays super_admin.
    $this->actingAs($super)
        ->patch("/users/{$target->id}", [
            'name' => 'Renamed',
            'email' => $target->email,
            'role' => 'user',
        ])
        ->assertSessionHasNoErrors();

    $target->refresh();
    expect($target->name)->toBe('Renamed')
        ->and($target->role)->toBe('super_admin');
});

test('you cannot change your own role', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch("/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'user',
        ])
        ->assertSessionHasNoErrors();

    expect($admin->fresh()->role)->toBe('admin');
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
