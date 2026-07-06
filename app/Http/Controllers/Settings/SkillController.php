<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SkillController extends Controller
{
    /**
     * Show the user's skills plus the starter library.
     */
    public function index(Request $request): Response
    {
        $library = [];

        foreach (Config::array('skills.library') as $item) {
            $library[] = [
                'name' => (string) ($item['name'] ?? 'Skill'),
                'icon' => (string) ($item['icon'] ?? '✨'),
                'description' => (string) ($item['description'] ?? ''),
                'instructions' => (string) ($item['instructions'] ?? ''),
            ];
        }

        return Inertia::render('settings/Skills', [
            'skills' => $request->user()->skills()
                ->latest('updated_at')
                ->get(['id', 'name', 'icon', 'description', 'instructions'])
                ->all(),
            'library' => $library,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $request->user()->skills()->create($validated);

        return back();
    }

    public function update(Request $request, Skill $skill): RedirectResponse
    {
        $this->ensureOwner($request, $skill);

        $skill->update($request->validate($this->rules()));

        return back();
    }

    public function destroy(Request $request, Skill $skill): RedirectResponse
    {
        $this->ensureOwner($request, $skill);

        $skill->delete();

        return back();
    }

    /**
     * Import a skill from an uploaded SKILL.md (or pasted markdown).
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['nullable', 'file', 'mimes:md,markdown,txt', 'max:512'],
            'content' => ['nullable', 'string', 'max:20000'],
        ]);

        $raw = '';

        if ($request->hasFile('file')) {
            $raw = (string) file_get_contents($request->file('file')->getRealPath());
        } elseif (filled($request->input('content'))) {
            $raw = (string) $request->input('content');
        }

        if (trim($raw) === '') {
            return back()->withErrors(['file' => 'Add a SKILL.md file or paste its contents.']);
        }

        $parsed = $this->parseSkillMarkdown($raw);

        $request->user()->skills()->create($parsed);

        return back();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'icon' => ['nullable', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:20000'],
        ];
    }

    /**
     * Parse a SKILL.md-style document: optional `--- name / description ---`
     * front matter followed by the instruction body.
     *
     * @return array{name: string, icon: string|null, description: string|null, instructions: string}
     */
    private function parseSkillMarkdown(string $raw): array
    {
        $name = null;
        $description = null;
        $body = trim($raw);

        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $body, $m) === 1) {
            $front = $m[1];
            $body = trim($m[2]);

            foreach (preg_split('/\r?\n/', $front) ?: [] as $line) {
                if (preg_match('/^\s*name\s*:\s*(.+)$/i', $line, $mm) === 1) {
                    $name = trim($mm[1], " \t\"'");
                } elseif (preg_match('/^\s*description\s*:\s*(.+)$/i', $line, $mm) === 1) {
                    $description = trim($mm[1], " \t\"'");
                }
            }
        }

        // Fall back to the first markdown heading, then a default.
        if ($name === null && preg_match('/^#\s+(.+)$/m', $body, $mm) === 1) {
            $name = trim($mm[1]);
        }

        return [
            'name' => Str::limit($name ?? 'Imported skill', 80, ''),
            'icon' => '📄',
            'description' => $description !== null ? Str::limit($description, 255, '') : null,
            'instructions' => Str::limit($body, 20000, ''),
        ];
    }

    private function ensureOwner(Request $request, Skill $skill): void
    {
        abort_unless($skill->user_id === $request->user()->id, 404);
    }
}
