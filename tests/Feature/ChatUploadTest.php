<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

function firstModel(): string
{
    return (string) array_key_first(config('services.anthropic.models'));
}

function pngFile(string $name = 'photo.png'): UploadedFile
{
    // GD may be unavailable in CI, so build a fake file with an explicit mime
    // rather than ->image() (which renders a real PNG).
    return UploadedFile::fake()->create($name, 10, 'image/png');
}

/**
 * POST a chat message as JSON-accepting (multipart, so files upload properly).
 *
 * @param  array<string, mixed>  $data
 */
function postMessage(mixed $test, User $user, array $data): TestResponse
{
    return $test->actingAs($user)
        ->post('/chat/message', $data, ['Accept' => 'application/json']);
}

/**
 * Assert a 422 whose validation errors include the given key.
 */
function assertRejectedFor(TestResponse $res, string $key): void
{
    $res->assertStatus(422);
    expect(array_keys((array) $res->json('errors')))->toContain($key);
}

beforeEach(function () {
    config([
        'services.anthropic.uploads' => [
            'enabled' => true,
            'max_files' => 3,
            'max_size_kb' => 1024,
            'mimes' => 'jpg,jpeg,png,gif,webp,pdf',
        ],
    ]);
});

test('a disallowed file type is rejected', function () {
    $res = postMessage($this, User::factory()->create(), [
        'content' => 'look at this',
        'model' => firstModel(),
        'files' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
    ]);

    assertRejectedFor($res, 'files.0');
});

test('too many files are rejected', function () {
    $files = collect(range(1, 4))
        ->map(fn (int $i): UploadedFile => pngFile("img{$i}.png"))
        ->all();

    $res = postMessage($this, User::factory()->create(), [
        'content' => 'batch',
        'model' => firstModel(),
        'files' => $files,
    ]);

    assertRejectedFor($res, 'files');
});

test('an oversized file is rejected', function () {
    // 2 MB, over the 1 MB (1024 KB) cap configured above.
    $big = UploadedFile::fake()->create('scan.pdf', 2048, 'application/pdf');

    $res = postMessage($this, User::factory()->create(), [
        'content' => 'big file',
        'model' => firstModel(),
        'files' => [$big],
    ]);

    assertRejectedFor($res, 'files.0');
});

test('a message with no content and no files is rejected', function () {
    $res = postMessage($this, User::factory()->create(), [
        'content' => '',
        'model' => firstModel(),
    ]);

    assertRejectedFor($res, 'content');
});

test('files are prohibited when uploads are disabled', function () {
    config(['services.anthropic.uploads.enabled' => false]);

    $res = postMessage($this, User::factory()->create(), [
        'content' => 'hi',
        'model' => firstModel(),
        'files' => [pngFile()],
    ]);

    assertRejectedFor($res, 'files');
});

test('a valid attachment is stored and recorded on the user message', function () {
    Storage::fake();
    // A key must be present so the request gets past the config check and stores
    // the file + user message before contacting Claude.
    config(['services.anthropic.key' => 'sk-test-dummy']);

    $user = User::factory()->create();

    // Claude isn't reachable with a dummy key, so the call fails (502) — but the
    // file and user message are persisted beforehand, which is what we assert.
    postMessage($this, $user, [
        'content' => 'What is in this image?',
        'model' => firstModel(),
        'files' => [pngFile()],
    ])->assertStatus(502);

    $message = $user->conversations()->firstOrFail()
        ->messages()->where('role', 'user')->firstOrFail();

    expect($message->attachments)->toHaveCount(1)
        ->and($message->attachments[0]['name'])->toBe('photo.png')
        ->and($message->attachments[0]['mime'])->toContain('image/');

    Storage::assertExists($message->attachments[0]['path']);
});
