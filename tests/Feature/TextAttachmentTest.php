<?php

use App\Http\Controllers\ChatController;
use App\Models\User;
use App\Services\OfficeTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A .txt whose contents are code, JSON or a log is NOT sniffed as text/plain —
 * finfo calls it text/x-c, text/x-php, application/json. That broke attached
 * text twice over: `mimes:txt` rejected it at the door, and the extractor
 * skipped it, so an upload that squeaked through reached the model as nothing
 * at all. Pasted text arrives as pasted-text-N.txt, so the paste feature failed
 * on precisely what people paste.
 */
function realFile(string $name, string $body): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'att').'-'.$name;
    file_put_contents($path, $body);

    // Not UploadedFile::fake() — this needs real bytes on disk, because the
    // whole bug lives in what finfo makes of the content.
    return new UploadedFile($path, $name, null, null, true);
}

beforeEach(function () {
    config([
        'services.anthropic.uploads.enabled' => true,
        'services.anthropic.uploads.max_files' => 5,
        'services.anthropic.uploads.max_size_kb' => 1024,
        'services.anthropic.uploads.mimes' => 'jpg,jpeg,png,gif,webp,pdf,docx,xlsx,csv,txt,md',
        'services.anthropic.key' => 'sk-test-dummy',
    ]);
});

test('the extractor reads text whatever finfo decides to call it', function () {
    $extractor = app(OfficeTextExtractor::class);

    foreach (['text/plain', 'text/x-c', 'text/x-php', 'text/markdown', 'text/csv',
        'application/json', 'application/xml', 'application/javascript'] as $mime) {
        expect($extractor->supports($mime))->toBeTrue("should support {$mime}");
    }

    // ...but still not binaries.
    expect($extractor->supports('image/png'))->toBeFalse()
        ->and($extractor->supports('application/pdf'))->toBeFalse()
        ->and($extractor->supports('application/octet-stream'))->toBeFalse();
});

test('a pasted code block is accepted and reaches the model as text', function () {
    Storage::fake();

    $body = "// GENERATED FILE — DO NOT EDIT\n#include <stdio.h>\nint main(void){return 0;}\n";
    $user = User::factory()->create();

    // 502: the dummy key fails at Claude, but only after the file is stored —
    // which is the part under test.
    $this->actingAs($user)
        ->post('/chat/message', [
            'content' => 'can you check this?',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('pasted-text-1.txt', $body)],
        ], ['Accept' => 'application/json'])
        ->assertStatus(502);

    $message = $user->conversations()->firstOrFail()
        ->messages()->where('role', 'user')->firstOrFail();

    $attachment = $message->attachments[0];

    // The sidecar is what actually reaches Claude. No sidecar = the model gets
    // an attachment that contributes nothing, which is what users saw.
    Storage::assertExists($attachment['path'].'.extracted.txt');
    expect(Storage::get($attachment['path'].'.extracted.txt'))->toContain('GENERATED FILE');
});

test('a JSON paste survives too', function () {
    Storage::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/chat/message', [
            'content' => 'check',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('pasted-text-1.txt', "{\n  \"invoice\": 42\n}\n")],
        ], ['Accept' => 'application/json'])
        ->assertStatus(502);

    $attachment = $user->conversations()->firstOrFail()
        ->messages()->where('role', 'user')->firstOrFail()->attachments[0];

    expect(Storage::get($attachment['path'].'.extracted.txt'))->toContain('invoice');
});

test('a disallowed extension is still rejected', function () {
    $this->actingAs(User::factory()->create())
        ->post('/chat/message', [
            'content' => 'x',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('payload.exe', "MZ\x90\x00binary")],
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

test('a binary renamed to .txt is still rejected', function () {
    // The relaxation is "trust the extension for TEXT", not "trust the
    // extension" — a PNG called notes.txt must not slip through.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    $this->actingAs(User::factory()->create())
        ->post('/chat/message', [
            'content' => 'x',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('notes.txt', $png)],
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

test('a real image still uploads', function () {
    Storage::fake();

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    $this->actingAs(User::factory()->create())
        ->post('/chat/message', [
            'content' => 'look',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('shot.png', $png)],
        ], ['Accept' => 'application/json'])
        ->assertStatus(502);
});

test('a jpg is not rejected for being sniffed as jpeg', function () {
    expect(ChatController::allowedUploadExtensions())->toContain('jpg')->toContain('jpeg');
});

// --- the pasted card and its viewer -------------------------------------------

test('a pasted attachment keeps its identity into the transcript', function () {
    Storage::fake();

    $user = User::factory()->create();
    $body = "line one\nline two\nline three\n";

    $this->actingAs($user)
        ->post('/chat/message', [
            'content' => 'can you read this?',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('pasted-text-1.txt', $body)],
            // The browser is the only thing that knows this was a paste.
            'pasted' => ['1'],
        ], ['Accept' => 'application/json'])
        ->assertStatus(502);

    $conversation = $user->conversations()->firstOrFail();
    $message = $conversation->messages()->where('role', 'user')->firstOrFail();

    expect($message->attachments[0]['pasted'])->toBeTrue()
        // Stored at upload so the card renders without fetching the file.
        ->and($message->attachments[0]['lines'])->toBe(4);

    // ...and the browser is told, so the bubble shows a PASTED card rather
    // than the filename the user never chose.
    $this->actingAs($user)
        ->getJson("/chat/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('messages.0.attachments.0.pasted', true)
        ->assertJsonPath('messages.0.attachments.0.lines', 4);
});

test('an ordinary upload is not marked as pasted', function () {
    Storage::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/chat/message', [
            'content' => 'notes',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('notes.txt', "hello\n")],
            'pasted' => ['0'],
        ], ['Accept' => 'application/json'])
        ->assertStatus(502);

    $message = $user->conversations()->firstOrFail()
        ->messages()->where('role', 'user')->firstOrFail();

    expect($message->attachments[0]['pasted'] ?? false)->toBeFalse();
});

test('the viewer serves the full original text to its owner', function () {
    Storage::fake();

    $user = User::factory()->create();
    // Longer than the extraction cap, to prove the viewer serves the ORIGINAL
    // and not the sidecar the model gets.
    config(['services.anthropic.uploads.extract_max_chars' => 50]);
    $body = str_repeat("abcdefghij\n", 40);

    $this->actingAs($user)
        ->post('/chat/message', [
            'content' => 'check',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('pasted-text-1.txt', $body)],
            'pasted' => ['1'],
        ], ['Accept' => 'application/json'])
        ->assertStatus(502);

    $message = $user->conversations()->firstOrFail()
        ->messages()->where('role', 'user')->firstOrFail();

    $res = $this->actingAs($user)->get("/chat/attachments/{$message->id}/0/text");

    $res->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8')
        // Never text/html or a script type — this is user content rendered in
        // the chat, so a sniffable content type would be an XSS vector.
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($res->getContent())->toBe($body)
        // The sidecar the model reads is capped at 50 chars here; the user
        // gets everything back.
        ->and(mb_strlen((string) $res->getContent()))->toBeGreaterThan(50);
});

test('the viewer endpoint is owner-only', function () {
    Storage::fake();

    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post('/chat/message', [
            'content' => 'check',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('pasted-text-1.txt', "secret\n")],
            'pasted' => ['1'],
        ], ['Accept' => 'application/json'])
        ->assertStatus(502);

    $message = $owner->conversations()->firstOrFail()
        ->messages()->where('role', 'user')->firstOrFail();

    $this->actingAs(User::factory()->create())
        ->get("/chat/attachments/{$message->id}/0/text")
        ->assertNotFound();
});

test('the viewer refuses to serve a binary attachment', function () {
    Storage::fake();

    $user = User::factory()->create();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    $this->actingAs($user)
        ->post('/chat/message', [
            'content' => 'look',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('shot.png', $png)],
        ], ['Accept' => 'application/json'])
        ->assertStatus(502);

    $message = $user->conversations()->firstOrFail()
        ->messages()->where('role', 'user')->firstOrFail();

    // Images have their own route; this one must not become a generic file read.
    $this->actingAs($user)
        ->get("/chat/attachments/{$message->id}/0/text")
        ->assertNotFound();
});

test('the pasted card carries a snippet so the transcript can draw it', function () {
    Storage::fake();

    $user = User::factory()->create();
    $body = "// GENERATED FILE — DO NOT EDIT\nline two\nline three\nline four\nline five\nline six\n";

    $this->actingAs($user)
        ->post('/chat/message', [
            'content' => 'check',
            'model' => array_key_first(config('services.anthropic.models')),
            'files' => [realFile('pasted-text-1.txt', $body)],
            'pasted' => ['1'],
        ], ['Accept' => 'application/json'])
        ->assertStatus(502);

    $conversation = $user->conversations()->firstOrFail();
    $snippet = $conversation->messages()->where('role', 'user')
        ->firstOrFail()->attachments[0]['snippet'];

    // A few lines, not the whole file — the card is a preview, and the message
    // payload shouldn't carry 56 KB to render a thumbnail.
    expect($snippet)->toContain('GENERATED FILE')
        ->and($snippet)->not->toContain('line six')
        ->and(mb_strlen($snippet))->toBeLessThanOrEqual(400);

    $this->actingAs($user)
        ->getJson("/chat/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('messages.0.attachments.0.snippet', $snippet);
});
