<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\OpenAiMedia;
use App\Services\TokenBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Image generation & speech — the media features Claude itself doesn't do,
 * served by an OpenAI-compatible provider (see config/services.php `media`).
 * Every request is charged to the user's token budget at a flat cost.
 */
class MediaController extends Controller
{
    /**
     * Generate an image from a prompt inside a (new or existing) chat. The
     * prompt is stored as the user's message and the image as an assistant
     * message attachment, so it lives in history like everything else.
     */
    public function generateImage(Request $request, OpenAiMedia $media): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        if (! OpenAiMedia::imageEnabled()) {
            return response()->json([
                'message' => 'Image generation is not enabled yet — ask your admin to add the provider API key.',
            ], 503);
        }

        if (app(TokenBudget::class)->exceeded($request->user())) {
            $snapshot = app(TokenBudget::class)->snapshot($request->user());

            return response()->json([
                'message' => 'You have used your token allowance for this period. It resets on '
                    .Carbon::parse($snapshot['resets_at'])->toDayDateTimeString().'.',
            ], 429);
        }

        $userId = $request->user()->id;
        $conversationId = (int) ($validated['conversation_id'] ?? 0);

        if ($conversationId > 0) {
            $conversation = Conversation::query()
                ->where('user_id', $userId)
                ->findOrFail($conversationId);
        } else {
            $conversation = new Conversation;
            $conversation->user_id = $userId;
            $conversation->title = Str::limit('🎨 '.$validated['prompt'], 48, '…');
            $conversation->model = (string) config('services.media.image.model');
            $conversation->save();
        }

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['prompt'],
        ]);

        try {
            $binary = $media->generateImage($validated['prompt']);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Image generation failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Image generation failed — please try again or rephrase the prompt.',
            ], 502);
        }

        $path = "chat-attachments/{$conversation->id}/gen-".Str::random(20).'.png';
        Storage::put($path, $binary);

        /** @var Message $assistantMessage */
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => '',
            'attachments' => [[
                'name' => Str::limit(Str::slug($validated['prompt']), 40, '').'.png',
                'mime' => 'image/png',
                'size' => strlen($binary),
                'path' => $path,
                'generated' => true,
            ]],
        ]);

        $conversation->touch();

        app(TokenBudget::class)->record($request->user(), (int) config('services.media.image.token_cost', 5000));

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'message' => [
                'id' => $assistantMessage->id,
                'role' => 'assistant',
                'content' => '',
                'attachments' => [[
                    'name' => 'image.png',
                    'mime' => 'image/png',
                    'url' => route('chat.images.show', [$assistantMessage->id, 0]),
                ]],
            ],
        ]);
    }

    /**
     * Serve a stored image attachment inline (generated or uploaded) —
     * owner-only.
     */
    public function showImage(Request $request, Message $message, int $index): BinaryFileResponse
    {
        abort_unless($message->conversation?->user_id === $request->user()->id, 404);

        $att = ($message->attachments ?? [])[$index] ?? null;

        abort_unless(
            $att !== null
            && str_starts_with($att['mime'], 'image/')
            && Storage::exists($att['path']),
            404,
        );

        return response()->file(Storage::path($att['path']), [
            'Content-Type' => (string) $att['mime'],
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Dictation: transcribe a short browser recording to text for the
     * composer. Nothing is stored — the audio is discarded after the call.
     */
    public function transcribe(Request $request, OpenAiMedia $media): JsonResponse
    {
        $request->validate([
            'audio' => [
                'required', 'file',
                'max:'.(int) config('services.media.speech.max_audio_kb', 15360),
                'mimetypes:audio/webm,video/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav,audio/x-wav,audio/flac',
            ],
        ]);

        if (! OpenAiMedia::speechEnabled()) {
            return response()->json(['message' => 'Speech is not enabled yet — ask your admin.'], 503);
        }

        if (app(TokenBudget::class)->exceeded($request->user())) {
            return response()->json(['message' => 'You have used your token allowance for this period.'], 429);
        }

        $file = $request->file('audio');

        try {
            $text = $media->transcribe(
                $file->getRealPath(),
                'recording.'.($file->getClientOriginalExtension() ?: 'webm'),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => "Couldn't transcribe that — please try again."], 502);
        }

        app(TokenBudget::class)->record($request->user(), (int) config('services.media.speech.token_cost', 500));

        return response()->json(['text' => $text]);
    }

    /**
     * Read an assistant reply aloud: returns MP3 audio for the message.
     */
    public function speak(Request $request, OpenAiMedia $media): Response
    {
        $validated = $request->validate([
            'message_id' => ['required', 'integer'],
        ]);

        if (! OpenAiMedia::speechEnabled()) {
            return response()->json(['message' => 'Speech is not enabled yet — ask your admin.'], 503);
        }

        $message = Message::query()->findOrFail((int) $validated['message_id']);

        abort_unless($message->conversation?->user_id === $request->user()->id, 404);
        abort_unless($message->role === 'assistant' && filled($message->content), 422);

        if (app(TokenBudget::class)->exceeded($request->user())) {
            return response()->json(['message' => 'You have used your token allowance for this period.'], 429);
        }

        try {
            $audio = $media->speak($message->content);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => "Couldn't generate audio — please try again."], 502);
        }

        app(TokenBudget::class)->record($request->user(), (int) config('services.media.speech.token_cost', 500));

        return response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'no-store',
        ]);
    }
}
