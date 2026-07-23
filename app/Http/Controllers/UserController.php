<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-only user management. There is no public registration — administrators
 * add members here. Gated by the `admin` middleware on the routes.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'created_at'])
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'created_at' => $u->created_at?->toDateString(),
                'is_self' => $u->id === $request->user()->id,
            ])
            ->all();

        return Inertia::render('Users', ['users' => $users]);
    }

    public function store(Request $request): RedirectResponse
    {
        // The member never types this themselves — the UI generates a random
        // password client-side and lets the admin copy it before submitting;
        // the member changes it at Settings → Security after logging in.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
        ]);

        // forceFill so email_verified_at (not a fillable field) is set too;
        // the password 'hashed' cast still applies. Admin-created accounts are
        // pre-verified so they can sign in immediately, but must change the
        // password an admin just saw before they can use the app.
        (new User)->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'password' => $validated['password'],
            'email_verified_at' => now(),
            'must_change_password' => true,
        ])->save();

        return back()->with('success', "Added {$validated['name']}.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        // Only the super admin can edit a super admin account.
        if ($user->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            return back()->with('error', 'Only the super admin can edit this account.');
        }

        // A super admin's role isn't changed here, and you can't change your
        // own role (avoids locking yourself out). Name/email stay editable.
        $lockRole = $user->isSuperAdmin() || $user->id === $request->user()->id;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ];

        if (! $lockRole) {
            $rules['role'] = ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])];
        }

        $validated = $request->validate($rules);

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if (! $lockRole) {
            $user->role = $validated['role'];
        }

        $user->save();

        return back()->with('success', "Updated {$validated['name']}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', "You can't remove your own account.");
        }

        // Only the super admin can remove a super admin account.
        if ($user->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            return back()->with('error', 'Only the super admin can remove this account.');
        }

        $user->delete();

        return back()->with('success', 'User removed.');
    }
}
