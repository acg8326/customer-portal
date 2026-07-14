<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;

function privateChatPayload(array $overrides = []): array
{
    return array_merge([
        'content' => 'Hello',
        'model' => array_key_first(config('services.anthropic.models')),
        'private' => true,
    ], $overrides);
}

test('a private turn never writes a conversation or message to the database', function () {
    config(['services.anthropic.key' => 'test-key']);

    $user = User::factory()->create();

    // The Claude call itself fails (fake key) inside the stream — that's fine:
    // what matters is that nothing was persisted at any point of the turn.
    $this->actingAs($user)
        ->postJson('/chat/stream', privateChatPayload([
            'history' => [
                ['role' => 'user', 'content' => 'Earlier question'],
                ['role' => 'assistant', 'content' => 'Earlier answer'],
            ],
        ]))
        ->assertOk();

    expect(Conversation::withTrashed()->count())->toBe(0)
        ->and(Message::count())->toBe(0);

    // Control: the same turn without `private` persists the conversation and
    // the user message before streaming starts.
    $this->actingAs($user)
        ->postJson('/chat/stream', [
            'content' => 'Hello',
            'model' => array_key_first(config('services.anthropic.models')),
        ])
        ->assertOk();

    expect(Conversation::count())->toBe(1)
        ->and(Message::where('role', 'user')->count())->toBe(1);
});

test('private turns reject conversation ids, retries, and edit-resend', function () {
    config(['services.anthropic.key' => 'test-key']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/stream', privateChatPayload(['conversation_id' => 1]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('conversation_id');

    $this->actingAs($user)
        ->postJson('/chat/stream', privateChatPayload(['retry' => true]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('retry');

    $this->actingAs($user)
        ->postJson('/chat/stream', privateChatPayload(['replace_last' => true]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('replace_last');
});

test('a client-held history is only accepted on private turns and must be well-formed', function () {
    config(['services.anthropic.key' => 'test-key']);

    $user = User::factory()->create();

    // History on a normal (saved) turn is meaningless — the DB is the history.
    $this->actingAs($user)
        ->postJson('/chat/stream', [
            'content' => 'Hello',
            'model' => array_key_first(config('services.anthropic.models')),
            'history' => [['role' => 'user', 'content' => 'sneaky']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('history');

    $this->actingAs($user)
        ->postJson('/chat/stream', privateChatPayload([
            'history' => [['role' => 'system', 'content' => 'not allowed']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('history.0.role');
});

test('attachments are rejected on private turns', function () {
    config(['services.anthropic.key' => 'test-key']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/chat/stream', privateChatPayload([
            'private' => '1',
            'files' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
        ]), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('files');

    expect(Conversation::count())->toBe(0);
});
