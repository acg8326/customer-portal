<?php

use Anthropic\Beta\Messages\BetaImageBlockParam;
use Anthropic\Beta\Messages\BetaMessageParam;
use Anthropic\Beta\Messages\BetaRequestDocumentBlock;
use Anthropic\Beta\Messages\BetaTextBlockParam;
use Anthropic\Messages\ImageBlockParam;
use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Call one of the controller's private history builders. These are internals,
 * but they are exactly where the bug lived: the beta path (web search, MCP,
 * connected tools) used to flatten every message to a string and silently drop
 * attachments — and because web search defaults on, that was the path *every*
 * chat took. Asserting on the built params is the only way to catch a
 * regression without a live Claude call.
 */
function historyVia(string $method, Conversation $conversation): array
{
    $controller = new ChatController;
    $ref = new ReflectionMethod($controller, $method);

    return $ref->invoke($controller, $conversation);
}

/**
 * A conversation with one user message carrying one attachment of $mime.
 */
function conversationWithAttachment(string $mime, string $name, string $body = 'bytes'): Conversation
{
    Storage::fake();

    $user = User::factory()->create();
    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Attachment test';
    $conversation->model = (string) array_key_first(config('services.anthropic.models'));
    $conversation->save();

    $path = "chat-attachments/{$conversation->id}/file";
    Storage::put($path, $body);

    $message = new Message;
    $message->conversation_id = $conversation->id;
    $message->role = 'user';
    $message->content = 'What is in this file?';
    $message->attachments = [
        ['name' => $name, 'mime' => $mime, 'size' => strlen($body), 'path' => $path],
    ];
    $message->save();

    return $conversation;
}

test('the beta history carries an image block, not just the filename', function () {
    $conversation = conversationWithAttachment('image/png', 'photo.png');

    $history = historyVia('betaHistory', $conversation);

    expect($history)->toHaveCount(1)
        ->and($history[0])->toBeInstanceOf(BetaMessageParam::class);

    $blocks = $history[0]['content'];

    // The image first, then the user's question — the same order the plain
    // path uses, so a cached prefix stays comparable between them.
    expect($blocks)->toHaveCount(2)
        ->and($blocks[0])->toBeInstanceOf(BetaImageBlockParam::class)
        ->and($blocks[0]['source']['mediaType'])->toBe('image/png')
        ->and($blocks[0]['source']['data'])->toBe(base64_encode('bytes'))
        ->and($blocks[1])->toBeInstanceOf(BetaTextBlockParam::class)
        ->and($blocks[1]['text'])->toBe('What is in this file?');
});

test('the beta history carries a PDF as a document block', function () {
    $conversation = conversationWithAttachment('application/pdf', 'invoice.pdf');

    $blocks = historyVia('betaHistory', $conversation)[0]['content'];

    expect($blocks[0])->toBeInstanceOf(BetaRequestDocumentBlock::class)
        ->and($blocks[0]['title'])->toBe('invoice.pdf');
});

test('the plain history still carries attachments too', function () {
    $conversation = conversationWithAttachment('image/png', 'photo.png');

    $blocks = historyVia('buildHistory', $conversation)[0]['content'];

    expect($blocks[0])->toBeInstanceOf(ImageBlockParam::class);
});

test('both paths send the same number of blocks', function () {
    $conversation = conversationWithAttachment('image/webp', 'shot.webp');

    expect(historyVia('betaHistory', $conversation)[0]['content'])
        ->toHaveCount(count(historyVia('buildHistory', $conversation)[0]['content']));
});

test('the JSON-safe mirror references the path instead of inlining base64', function () {
    $conversation = conversationWithAttachment('image/png', 'photo.png');

    $plain = historyVia('plainHistory', $conversation);
    $encoded = json_encode($plain);

    // The approval-gate state persists this mirror, so it must stay small: a
    // storage reference, never the file's bytes.
    expect($encoded)->toBeString()
        ->and($encoded)->not->toContain(base64_encode('bytes'))
        ->and($plain[0]['content'][0]['type'])->toBe('attachment')
        ->and($plain[0]['content'][0]['att']['name'])->toBe('photo.png');
});

test('a message with no attachments stays a plain string on both paths', function () {
    $conversation = conversationWithAttachment('image/png', 'photo.png');

    $message = new Message;
    $message->conversation_id = $conversation->id;
    $message->role = 'assistant';
    $message->content = 'A cat.';
    $message->save();

    $beta = historyVia('betaHistory', $conversation);
    $plain = historyVia('plainHistory', $conversation);

    // String content keeps the request byte-identical to before this change,
    // which is what lets the cached prefix keep hitting.
    expect($beta[1])->toBe(['role' => 'assistant', 'content' => 'A cat.'])
        ->and($plain[1]['content'])->toBe('A cat.');
});

test('an attachment whose file is gone drops out instead of failing', function () {
    $conversation = conversationWithAttachment('image/png', 'photo.png');
    Storage::deleteDirectory("chat-attachments/{$conversation->id}");

    $blocks = historyVia('betaHistory', $conversation)[0]['content'];

    // Only the text survives — a purged upload must not break the whole turn.
    expect($blocks)->toHaveCount(1)
        ->and($blocks[0])->toBeInstanceOf(BetaTextBlockParam::class);
});
