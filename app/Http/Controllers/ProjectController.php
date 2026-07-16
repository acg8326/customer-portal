<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\ModelCatalog;
use App\Services\OfficeTextExtractor;
use App\Services\OpenAiMedia;
use App\Services\UploadScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

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

        return Inertia::render('projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'instructions' => $project->instructions,
            ],
            'files' => $project->files()
                ->orderBy('name')
                ->get(['id', 'name', 'size'])
                ->map(fn (ProjectFile $f): array => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'size' => $f->size,
                ])
                ->all(),
            'fileLimits' => [
                'maxFiles' => (int) config('services.anthropic.uploads.project_max_files', 10),
                'mimes' => (string) config('services.anthropic.uploads.project_mimes', 'docx,xlsx,csv,txt,md'),
            ],
            'providers' => app(ModelCatalog::class)->providers(),
            'defaultModel' => ChatController::workspaceDefaultModel(),
            'uploads' => ChatController::uploadsProps(),
            'skills' => ChatController::skillOptions($request),
            'mcpEnabled' => ChatController::mcpEnabled($request),
            'netsuiteAccounts' => ChatController::netsuiteAccountOptions($request),
            'webEnabled' => ChatController::webToolsConfigured(),
            'imageEnabled' => OpenAiMedia::imageEnabled(),
            'speechEnabled' => OpenAiMedia::speechEnabled(),
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

        Storage::deleteDirectory("project-files/{$project->id}");
        $project->files()->delete();
        $project->delete();

        return redirect()->route('projects.index');
    }

    /**
     * Add documents to the project's knowledge base. Text-extractable formats
     * only (their content is injected into every chat in the project);
     * unreadable files are rejected rather than silently stored.
     */
    public function storeFiles(Request $request, Project $project): RedirectResponse
    {
        $this->ensureOwner($request, $project);

        $mimes = (string) config('services.anthropic.uploads.project_mimes', 'docx,xlsx,csv,txt,md');
        $maxFiles = (int) config('services.anthropic.uploads.project_max_files', 10);
        $maxSizeKb = (int) config('services.anthropic.uploads.max_size_kb', 10240);

        $request->validate([
            'files' => ['required', 'array', 'max:'.$maxFiles],
            'files.*' => ['file', 'mimes:'.$mimes, 'max:'.$maxSizeKb],
        ]);

        if ($project->files()->count() + count((array) $request->file('files')) > $maxFiles) {
            return back()->with('error', "A project holds at most {$maxFiles} files — remove one first.");
        }

        $scanner = app(UploadScanner::class);
        $extractor = app(OfficeTextExtractor::class);

        foreach ((array) $request->file('files') as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            try {
                $scanner->assertClean($file);
            } catch (RuntimeException $e) {
                return back()->with('error', $e->getMessage());
            }

            $path = $file->store("project-files/{$project->id}");

            if (! is_string($path)) {
                continue;
            }

            $mime = (string) ($file->getMimeType() ?? $file->getClientMimeType());
            $text = $extractor->supports($mime)
                ? $extractor->extract(
                    Storage::path($path),
                    $mime,
                    (int) config('services.anthropic.uploads.extract_max_chars', 50000),
                )
                : null;

            if ($text === null) {
                Storage::delete($path);

                return back()->with('error', "Couldn't read any text from {$file->getClientOriginalName()} — it wasn't added.");
            }

            Storage::put($path.'.extracted.txt', $text);

            $project->files()->create([
                'name' => $file->getClientOriginalName(),
                'mime' => $mime,
                'size' => (int) $file->getSize(),
                'path' => $path,
            ]);
        }

        return back()->with('success', 'File(s) added to the project.');
    }

    /**
     * Remove one document from the knowledge base.
     */
    public function destroyFile(Request $request, Project $project, ProjectFile $file): RedirectResponse
    {
        $this->ensureOwner($request, $project);
        abort_unless($file->project_id === $project->id, 404);

        Storage::delete([$file->path, $file->path.'.extracted.txt']);
        $file->delete();

        return back()->with('success', 'File removed.');
    }

    private function ensureOwner(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 404);
    }
}
