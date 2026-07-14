<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('image generation stores the prompt, the image, and charges the budget', function () {
    Storage::fake('local');
    config(['services.media.image.key' => 'sk-test', 'services.media.image.token_cost' => 5000]);

    Http::fake([
        '*/images/generations' => Http::response([
            'data' => [['b64_json' => base64_encode('fake-png-bytes')]],
        ]),
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/chat/image', ['prompt' => 'A lighthouse at dusk'])
        ->assertOk()
        ->assertJsonStructure(['conversation_id', 'title', 'message' => ['id', 'attachments']]);

    $conversation = Conversation::sole();

    expect($conversation->messages()->where('role', 'user')->sole()->content)->toBe('A lighthouse at dusk')
        ->and($conversation->title)->toStartWith('🎨');

    $attachment = $conversation->messages()->where('role', 'assistant')->sole()->attachments[0];

    expect($attachment['mime'])->toBe('image/png')
        ->and(Storage::exists($attachment['path']))->toBeTrue()
        ->and($user->fresh()->token_budget_used)->toBe(5000);

    // The returned URL serves the image to the owner and 404s for others.
    $url = $response->json('message.attachments.0.url');

    $this->actingAs($user)->get($url)->assertOk();
    $this->actingAs(User::factory()->create())->get($url)->assertStatus(404);
});

test('image generation is locked without a key and validates the prompt', function () {
    config(['services.media.image.key' => null]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/image', ['prompt' => 'x'])
        ->assertStatus(503);

    config(['services.media.image.key' => 'sk-test']);

    $this->actingAs($user)
        ->from('/chat')
        ->post('/chat/image', ['prompt' => ''])
        ->assertRedirect('/chat')
        ->assertSessionHasErrors('prompt');

    expect(Conversation::count())->toBe(0);
});

test('dictation transcribes audio and returns the text', function () {
    config(['services.media.speech.key' => 'sk-test', 'services.media.speech.token_cost' => 500]);

    Http::fake([
        '*/audio/transcriptions' => Http::response(['text' => 'Magandang umaga sa lahat']),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/chat/transcribe', [
            'audio' => UploadedFile::fake()->create('recording.webm', 50, 'audio/webm'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJson(['text' => 'Magandang umaga sa lahat']);

    expect($user->fresh()->token_budget_used)->toBe(500);
});

test('read-aloud returns audio for own assistant messages only', function () {
    config(['services.media.speech.key' => 'sk-test']);

    Http::fake([
        '*/audio/speech' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    $owner = User::factory()->create();
    $conversation = new Conversation;
    $conversation->user_id = $owner->id;
    $conversation->title = 'Chat';
    $conversation->model = 'claude-sonnet-5';
    $conversation->save();
    $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hello there.']);

    $this->actingAs($owner)
        ->post('/chat/speech', ['message_id' => $message->id])
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg');

    $this->actingAs(User::factory()->create())
        ->postJson('/chat/speech', ['message_id' => $message->id])
        ->assertStatus(404);
});
