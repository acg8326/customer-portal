<?php

use App\Models\Conversation;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;

test('conversations, projects, and skills soft-delete and stay recoverable', function () {
    $user = User::factory()->create();

    $c = $user->conversations()->create(['title' => 'Chat', 'model' => 'claude-opus-4-8']);
    $p = $user->projects()->create(['name' => 'Proj']);
    $s = $user->skills()->create(['name' => 'Skill', 'instructions' => 'Do X']);

    $c->delete();
    $p->delete();
    $s->delete();

    // Hidden from normal queries...
    expect(Conversation::whereKey($c->id)->exists())->toBeFalse()
        ->and(Project::whereKey($p->id)->exists())->toBeFalse()
        ->and(Skill::whereKey($s->id)->exists())->toBeFalse();

    // ...but the rows are retained and can be restored.
    expect(Conversation::withTrashed()->whereKey($c->id)->exists())->toBeTrue()
        ->and(Project::withTrashed()->whereKey($p->id)->exists())->toBeTrue()
        ->and(Skill::withTrashed()->whereKey($s->id)->exists())->toBeTrue();

    $c->restore();

    expect(Conversation::whereKey($c->id)->exists())->toBeTrue();
});

test('a soft-deleted conversation is not returned to its owner', function () {
    $user = User::factory()->create();
    $c = $user->conversations()->create(['title' => 'Secret', 'model' => 'claude-opus-4-8']);
    $c->delete();

    expect($user->conversations()->count())->toBe(0);
});
