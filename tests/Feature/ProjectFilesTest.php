<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function makeKbProject(?User $user = null): Project
{
    $project = new Project;
    $project->user_id = ($user ?? User::factory()->create())->id;
    $project->name = 'Client Alpha';
    $project->save();

    return $project;
}

function kbCsvUpload(string $name = 'rates.csv', string $content = "role,rate\nDeveloper,45\nAnalyst,38"): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

test('a csv joins the knowledge base with its text extracted', function () {
    Storage::fake();
    $project = makeKbProject();

    $this->actingAs($project->user)
        ->post("/projects/{$project->id}/files", ['files' => [kbCsvUpload()]])
        ->assertRedirect();

    $file = $project->files()->first();

    expect($file)->not->toBeNull()
        ->and($file->name)->toBe('rates.csv')
        ->and(Storage::get($file->path.'.extracted.txt'))->toContain('Developer,45');
});

test('project file contents are injected into chats in that project', function () {
    Storage::fake();
    $project = makeKbProject();

    $this->actingAs($project->user)
        ->post("/projects/{$project->id}/files", ['files' => [kbCsvUpload()]])
        ->assertRedirect();

    $conversation = new Conversation;
    $conversation->user_id = $project->user_id;
    $conversation->project_id = $project->id;
    $conversation->title = 'Project chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    $system = (new ReflectionMethod(ChatController::class, 'buildSystemPrompt'))
        ->invoke(app(ChatController::class), $conversation);

    expect($system)->toContain('## Project files')
        ->and($system)->toContain('### File: rates.csv')
        ->and($system)->toContain('Analyst,38');
});

test('files over the total budget are listed by name instead of inlined', function () {
    Storage::fake();
    config(['services.anthropic.uploads.project_max_chars' => 50]);

    $project = makeKbProject();

    $this->actingAs($project->user)->post("/projects/{$project->id}/files", [
        'files' => [kbCsvUpload('huge.csv', "col\n".str_repeat("row-value\n", 50))],
    ])->assertRedirect();

    $conversation = new Conversation;
    $conversation->user_id = $project->user_id;
    $conversation->project_id = $project->id;
    $conversation->title = 'Budget chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    $system = (new ReflectionMethod(ChatController::class, 'buildSystemPrompt'))
        ->invoke(app(ChatController::class), $conversation);

    expect($system)->toContain('Not loaded, over the context budget: huge.csv')
        ->and($system)->not->toContain('row-value');
});

test('unsupported types are rejected and unreadable files are not stored', function () {
    Storage::fake();
    $project = makeKbProject();

    // Images are not knowledge-base material (can't inject into the prompt).
    $this->actingAs($project->user)
        ->post("/projects/{$project->id}/files", [
            'files' => [UploadedFile::fake()->create('photo.png', 10, 'image/png')],
        ])
        ->assertSessionHasErrors('files.0');

    expect($project->files()->count())->toBe(0);
});

test('the per-project file cap is enforced', function () {
    Storage::fake();
    config(['services.anthropic.uploads.project_max_files' => 1]);

    $project = makeKbProject();

    $this->actingAs($project->user)
        ->post("/projects/{$project->id}/files", ['files' => [kbCsvUpload('one.csv')]])
        ->assertRedirect();

    $this->actingAs($project->user)
        ->post("/projects/{$project->id}/files", ['files' => [kbCsvUpload('two.csv')]])
        ->assertRedirect(); // back() with an error flash, not a validation error

    expect($project->files()->count())->toBe(1);
});

test('only the owner can add or remove files, and removal deletes storage', function () {
    Storage::fake();
    $project = makeKbProject();

    $this->actingAs(User::factory()->create())
        ->post("/projects/{$project->id}/files", ['files' => [kbCsvUpload()]])
        ->assertStatus(404);

    $this->actingAs($project->user)
        ->post("/projects/{$project->id}/files", ['files' => [kbCsvUpload()]])
        ->assertRedirect();

    $file = $project->files()->firstOrFail();

    $this->actingAs(User::factory()->create())
        ->delete("/projects/{$project->id}/files/{$file->id}")
        ->assertStatus(404);

    $this->actingAs($project->user)
        ->delete("/projects/{$project->id}/files/{$file->id}")
        ->assertRedirect();

    expect($project->files()->count())->toBe(0)
        ->and(Storage::exists($file->path))->toBeFalse()
        ->and(Storage::exists($file->path.'.extracted.txt'))->toBeFalse();
});
