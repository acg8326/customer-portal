<?php

namespace App\Http\Controllers;

use Anthropic\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ChatController extends Controller
{
    /**
     * Render the chat page with the list of selectable models.
     */
    public function index(): Response
    {
        $models = collect(config('services.anthropic.models'))
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values();

        return Inertia::render('Chat', [
            'models' => $models,
            'defaultModel' => config('services.anthropic.model'),
        ]);
    }

    /**
     * Send the conversation to Claude and return the assistant's reply.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:50'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:8000'],
            'model' => ['required', 'string', Rule::in(array_keys(config('services.anthropic.models')))],
        ]);

        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            return response()->json([
                'message' => 'The chat is not configured yet. Add ANTHROPIC_API_KEY to your .env file.',
            ], 503);
        }

        try {
            $client = new Client(apiKey: $apiKey);

            $message = $client->messages->create(
                maxTokens: config('services.anthropic.max_tokens', 4096),
                messages: $validated['messages'],
                model: $validated['model'],
                system: config('services.anthropic.system_prompt'),
            );

            $reply = collect($message->content)
                ->filter(fn ($block) => $block->type === 'text')
                ->map(fn ($block) => $block->text)
                ->implode('');

            return response()->json([
                'reply' => trim($reply),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Sorry — the assistant could not respond right now. Please try again.',
            ], 502);
        }
    }
}
