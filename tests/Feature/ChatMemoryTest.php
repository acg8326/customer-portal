<?php

use App\Http\Controllers\ChatController;
use App\Jobs\UpdateUserMemory;
use App\Models\Conversation;
use App\Models\User;
use App\Services\MemoryCurator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

function memoryChat(User $user): Conversation
{
    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Memory chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    return $conversation;
}

// --- parsing -----------------------------------------------------------------

test('the curator parses lines, strips bullets, and honors NONE and caps', function () {
    config(['services.anthropic.memory.max_items' => 3, 'services.anthropic.memory.max_item_chars' => 30]);

    expect(MemoryCurator::parse("- Works in finance\n* Prefers Tagalog\n• Uses NetSuite daily\nExtra fact beyond the cap"))
        ->toBe(['Works in finance', 'Prefers Tagalog', 'Uses NetSuite daily'])
        ->and(MemoryCurator::parse('NONE'))->toBe([])
        ->and(MemoryCurator::parse("none\n\n"))->toBe([])
        ->and(MemoryCurator::parse('This single fact is much longer than thirty characters total'))
        ->toBe(['This single fact is much longe…']);
});

// --- system prompt injection ---------------------------------------------------

test('memories are injected into the system prompt, gated by the toggles', function () {
    $user = User::factory()->create();
    $user->memories()->create(['content' => 'Handles the Acme Corp account']);
    $conversation = memoryChat($user);

    $build = fn (): string => (new ReflectionMethod(ChatController::class, 'buildSystemPrompt'))
        ->invoke(app(ChatController::class), $conversation->fresh());

    expect($build())->toContain('## Memory')
        ->and($build())->toContain('Handles the Acme Corp account');

    // Per-user opt-out.
    $user->forceFill(['memory_enabled' => false])->save();
    expect($build())->not->toContain('## Memory');

    // Global config off.
    $user->forceFill(['memory_enabled' => true])->save();
    config(['services.anthropic.memory.enabled' => false]);
    expect($build())->not->toContain('## Memory');
});

// --- extraction trigger ----------------------------------------------------------

test('finalizeTurn dispatches the memory job once enough new messages exist', function () {
    Queue::fake();
    config(['services.anthropic.memory.every_messages' => 4]);

    $user = User::factory()->create();
    $conversation = memoryChat($user);

    // 3 existing + the assistant reply finalizeTurn persists = 4 → dispatch.
    foreach (range(1, 3) as $i) {
        $conversation->messages()->create(['role' => $i % 2 ? 'user' : 'assistant', 'content' => "m{$i}"]);
    }

    // fresh(): a request user is DB-loaded, so it carries the column default
    // for memory_enabled — the factory instance doesn't.
    $request = Request::create('/chat/stream', 'POST');
    $request->setUserResolver(fn () => $user->fresh());

    (new ReflectionMethod(ChatController::class, 'finalizeTurn'))->invoke(
        app(ChatController::class),
        $request, $conversation, 'the reply', 'claude-opus-4-8', 10, 5, $user->id,
    );

    Queue::assertPushed(UpdateUserMemory::class, fn ($job) => $job->conversationId === $conversation->id);
});

test('no memory job dispatches below the threshold or when opted out', function () {
    Queue::fake();
    config(['services.anthropic.memory.every_messages' => 10]);

    $user = User::factory()->create();
    $conversation = memoryChat($user);

    $request = Request::create('/chat/stream', 'POST');
    $request->setUserResolver(fn () => $user);

    $invoke = fn () => (new ReflectionMethod(ChatController::class, 'finalizeTurn'))->invoke(
        app(ChatController::class),
        $request, $conversation, 'reply', 'claude-opus-4-8', 10, 5, $user->id,
    );

    $invoke(); // 1 message < 10
    Queue::assertNotPushed(UpdateUserMemory::class);

    config(['services.anthropic.memory.every_messages' => 1]);
    $user->forceFill(['memory_enabled' => false])->save();

    $invoke(); // threshold met, but user opted out
    Queue::assertNotPushed(UpdateUserMemory::class);
});

// --- settings management ----------------------------------------------------------

test('users can edit, delete, and clear their memories — but only their own', function () {
    $user = User::factory()->create();
    $memory = $user->memories()->create(['content' => 'Old fact']);
    $user->memories()->create(['content' => 'Another fact']);

    $this->actingAs($user)
        ->patch("/settings/memories/{$memory->id}", ['content' => 'Corrected fact'])
        ->assertRedirect();
    expect($memory->fresh()->content)->toBe('Corrected fact');

    // Someone else's memory 404s.
    $this->actingAs(User::factory()->create())
        ->patch("/settings/memories/{$memory->id}", ['content' => 'hijack'])
        ->assertStatus(404);

    $this->actingAs($user)->delete("/settings/memories/{$memory->id}")->assertRedirect();
    expect($user->memories()->count())->toBe(1);

    $this->actingAs($user)->delete('/settings/memories')->assertRedirect();
    expect($user->memories()->count())->toBe(0);
});

test('the memory toggle persists and the profile page exposes memories', function () {
    $user = User::factory()->create();
    $user->memories()->create(['content' => 'Prefers XLSX exports']);

    $this->actingAs($user)
        ->patch('/settings/memory', ['enabled' => false])
        ->assertRedirect();
    expect($user->fresh()->memory_enabled)->toBeFalse();

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertInertia(fn ($page) => $page
            ->where('memoryEnabled', false)
            ->where('memories.0.content', 'Prefers XLSX exports'));
});
