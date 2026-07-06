<?php

use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

function makeProject(User $user, string $name = 'Project', array $attrs = []): Project
{
    $project = new Project;
    $project->user_id = $user->id;
    $project->name = $name;
    $project->instructions = $attrs['instructions'] ?? null;
    $project->memory = $attrs['memory'] ?? null;
    $project->save();

    return $project;
}

test('the projects list only shows the logged-in user\'s projects', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    makeProject($me, 'Mine');
    makeProject($other, 'Theirs');

    $this->actingAs($me)
        ->get(route('projects.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/Index')
            ->has('projects', 1)
            ->where('projects.0.name', 'Mine')
        );
});

test('a user cannot open another user\'s project', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $project = makeProject($owner, 'Secret', ['memory' => 'The code is BLUE.']);

    $this->actingAs($intruder)
        ->get(route('projects.show', $project))
        ->assertNotFound();
});

test('a user cannot edit another user\'s project memory', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $project = makeProject($owner, 'Secret', ['memory' => 'Original memory.']);

    $this->actingAs($intruder)
        ->patch(route('projects.update', $project), [
            'name' => 'Hijacked',
            'memory' => 'Tampered memory.',
        ])
        ->assertNotFound();

    expect($project->fresh()->memory)->toBe('Original memory.');
});

test('a user cannot delete another user\'s project', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $project = makeProject($owner, 'Secret');

    $this->actingAs($intruder)
        ->delete(route('projects.destroy', $project))
        ->assertNotFound();

    expect(Project::query()->whereKey($project->id)->exists())->toBeTrue();
});

test('a chat cannot be attached to another user\'s project', function () {
    // A key must be present so ownership is checked before any API call;
    // the 404 fires before Claude is ever contacted.
    config(['services.anthropic.key' => 'sk-test-dummy']);

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $project = makeProject($owner, 'Secret');

    $this->actingAs($intruder)
        ->postJson('/chat/message', [
            'project_id' => $project->id,
            'content' => 'Hello',
            'model' => array_key_first(config('services.anthropic.models')),
        ])
        ->assertNotFound();
});
