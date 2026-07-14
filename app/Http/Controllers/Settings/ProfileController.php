<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Memory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'memoryEnabled' => $request->user()->memory_enabled,
            'memories' => $request->user()->memories()
                ->orderBy('id')
                ->get(['id', 'content'])
                ->map(fn (Memory $m): array => ['id' => $m->id, 'content' => $m->content])
                ->all(),
        ]);
    }

    /**
     * Toggle automatic memory for this user. Turning it off stops new
     * extraction and stops injecting existing memories (they are kept,
     * not wiped, so re-enabling restores them).
     */
    public function updateMemorySettings(Request $request): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        $request->user()->forceFill(['memory_enabled' => (bool) $validated['enabled']])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Memory settings updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Edit one memory's text.
     */
    public function updateMemory(Request $request, Memory $memory): RedirectResponse
    {
        abort_unless($memory->user_id === $request->user()->id, 404);

        $validated = $request->validate(['content' => ['required', 'string', 'max:500']]);

        $memory->content = trim($validated['content']);
        $memory->save();

        return to_route('profile.edit');
    }

    /**
     * Delete one memory.
     */
    public function destroyMemory(Request $request, Memory $memory): RedirectResponse
    {
        abort_unless($memory->user_id === $request->user()->id, 404);

        $memory->delete();

        return to_route('profile.edit');
    }

    /**
     * Forget everything at once.
     */
    public function clearMemories(Request $request): RedirectResponse
    {
        $request->user()->memories()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Memory cleared.')]);

        return to_route('profile.edit');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Update the user's standing chat preferences ("always answer in Tagalog",
     * "be terse", …) — appended to the assistant's system prompt as a
     * "## User preferences" section. Tone/format only; a guard line in the
     * prompt keeps them from overriding the safety rules.
     */
    public function updateChatPreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'chat_preferences' => ['nullable', 'string', 'max:2000'],
        ]);

        $request->user()->forceFill([
            'chat_preferences' => trim((string) ($validated['chat_preferences'] ?? '')) ?: null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Chat preferences updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
