<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GeneralController extends Controller
{
    /**
     * General settings — theme, language, message font size.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/General', [
            'preferredLanguage' => $request->user()->preferred_language,
            'languages' => self::languages(),
        ]);
    }

    /**
     * Set the user's preferred language (null/'auto' = match whatever
     * language the user writes in). Injected into AiMe's system prompt.
     */
    public function updateLanguage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'language' => ['nullable', 'string', Rule::in([...self::languages(), 'auto'])],
        ]);

        $language = $validated['language'] ?? null;

        $request->user()->forceFill([
            'preferred_language' => ($language === 'auto' || $language === null) ? null : $language,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Language updated.')]);

        return to_route('general.edit');
    }

    /**
     * @return array<int, string>
     */
    private static function languages(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.anthropic.languages', 'English')),
        )));
    }
}
