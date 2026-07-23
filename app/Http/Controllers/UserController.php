<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
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
        // Only the super admin governs per-user model/limit (same policy as the
        // dashboard). Admins still manage membership but see these read-only.
        $canGovern = $request->user()->isSuperAdmin();

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'created_at', 'assigned_model', 'token_limit'])
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'created_at' => $u->created_at?->toDateString(),
                'is_self' => $u->id === $request->user()->id,
                'assigned_model' => $u->assigned_model,
                'token_limit' => $u->token_limit,
            ])
            ->all();

        $models = [];

        foreach (Config::array('services.anthropic.models') as $value => $label) {
            $models[] = ['value' => $value, 'label' => $label];
        }

        return Inertia::render('Users', [
            'users' => $users,
            'canGovern' => $canGovern,
            'models' => $models,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
        ]);

        // forceFill so email_verified_at (not a fillable field) is set too;
        // the password 'hashed' cast still applies. Admin-created accounts are
        // pre-verified so they can sign in immediately.
        (new User)->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'password' => $validated['password'],
            'email_verified_at' => now(),
        ])->save();

        return back()->with('success', "Added {$validated['name']}.");
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
