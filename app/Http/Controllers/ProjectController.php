<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * List the current user's projects.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('projects/Index', [
            'projects' => $request->user()->projects()
                ->latest('updated_at')
                ->get(['id', 'name', 'updated_at'])
                ->map(fn (Project $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'updated_at' => $p->updated_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * Create a project and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $project = new Project;
        $project->user_id = $request->user()->id;
        $project->name = $validated['name'];
        $project->save();

        return redirect()->route('projects.show', $project);
    }

    /**
     * Show a project workspace: its settings + its conversations.
     */
    public function show(Request $request, Project $project): Response
    {
        $this->ensureOwner($request, $project);

        $models = [];

        foreach (Config::array('services.anthropic.models') as $value => $label) {
            $models[] = ['value' => $value, 'label' => $label];
        }

        return Inertia::render('projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'instructions' => $project->instructions,
            ],
            'models' => $models,
            'defaultModel' => config('services.anthropic.model'),
            'uploads' => ChatController::uploadsProps(),
            'skills' => ChatController::skillOptions($request),
            'mcpEnabled' => ChatController::mcpEnabled($request),
            'webEnabled' => ChatController::webToolsConfigured(),
            'continuePrompt' => (string) config('services.anthropic.continue_prompt'),
            'conversations' => $project->conversations()
                ->orderByDesc('starred')
                ->latest('updated_at')
                ->get(['id', 'title', 'starred', 'updated_at'])
                ->map(fn (Conversation $c): array => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'starred' => $c->starred,
                    'updated_at' => $c->updated_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * Update a project's name, instructions, or memory.
     */
    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->ensureOwner($request, $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:20000'],
        ]);

        $project->update($validated);

        return back();
    }

    /**
     * Delete a project (and its conversations).
     */
    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->ensureOwner($request, $project);

        $project->delete();

        return redirect()->route('projects.index');
    }

    private function ensureOwner(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 404);
    }
}
