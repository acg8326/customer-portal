<?php

use App\Models\Skill;
use App\Models\User;

function makeSkill(User $user, string $name = 'My skill', string $instructions = 'Do the thing.'): Skill
{
    $skill = new Skill;
    $skill->user_id = $user->id;
    $skill->name = $name;
    $skill->instructions = $instructions;
    $skill->save();

    return $skill;
}

test('a user can create a skill', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/skills', [
            'name' => 'RMA evaluator',
            'icon' => '🔧',
            'description' => 'Guides RMA cases.',
            'instructions' => 'Be methodical.',
        ])
        ->assertRedirect();

    expect($user->skills()->count())->toBe(1)
        ->and($user->skills()->first()->name)->toBe('RMA evaluator');
});

test('the skills page only lists the current user\'s skills', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    makeSkill($me, 'Mine');
    makeSkill($other, 'Theirs');

    $this->actingAs($me)
        ->get('/settings/skills')
        ->assertInertia(fn ($page) => $page
            ->component('settings/Skills')
            ->has('skills', 1)
            ->where('skills.0.name', 'Mine')
            ->has('library')
        );
});

test('a user cannot edit or delete another user\'s skill', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $skill = makeSkill($owner, 'Secret', 'Original.');

    $this->actingAs($intruder)
        ->patch("/settings/skills/{$skill->id}", [
            'name' => 'Hacked',
            'instructions' => 'Changed.',
        ])
        ->assertNotFound();

    $this->actingAs($intruder)
        ->delete("/settings/skills/{$skill->id}")
        ->assertNotFound();

    expect($skill->fresh()->instructions)->toBe('Original.');
});

test('importing a SKILL.md parses front matter', function () {
    $user = User::factory()->create();
    $md = "---\nname: Imported Skill\ndescription: From a file\n---\nThese are the instructions.";

    $this->actingAs($user)
        ->post('/settings/skills/import', ['content' => $md])
        ->assertRedirect();

    $skill = $user->skills()->firstOrFail();

    expect($skill->name)->toBe('Imported Skill')
        ->and($skill->description)->toBe('From a file')
        ->and($skill->instructions)->toBe('These are the instructions.');
});

test('a selected skill is stored on the conversation', function () {
    config(['services.anthropic.key' => 'sk-test-dummy']);
    $user = User::factory()->create();
    $skill = makeSkill($user, 'Translator', 'Translate everything.');

    // Claude is unreachable with a dummy key (502), but the conversation +
    // skill link are persisted before the API call.
    $this->actingAs($user)
        ->postJson('/chat/message', [
            'content' => 'hola',
            'model' => (string) array_key_first(config('services.anthropic.models')),
            'skill_id' => $skill->id,
        ])
        ->assertStatus(502);

    expect($user->conversations()->first()->skill_id)->toBe($skill->id);
});

test('another user\'s skill id is ignored on send', function () {
    config(['services.anthropic.key' => 'sk-test-dummy']);
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreignSkill = makeSkill($other, 'Foreign');

    $this->actingAs($user)
        ->postJson('/chat/message', [
            'content' => 'hi',
            'model' => (string) array_key_first(config('services.anthropic.models')),
            'skill_id' => $foreignSkill->id,
        ])
        ->assertStatus(502);

    expect($user->conversations()->first()->skill_id)->toBeNull();
});
