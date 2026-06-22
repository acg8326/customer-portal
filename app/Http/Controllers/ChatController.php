<?php

namespace App\Http\Controllers;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use App\Models\Conversation;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ChatController extends Controller
{
    /**
     * Render the chat page with the user's saved conversations and model list.
     */
    public function index(Request $request): Response
    {
        $models = [];

        foreach (Config::array('services.anthropic.models') as $value => $label) {
            $models[] = ['value' => $value, 'label' => $label];
        }

        return Inertia::render('Chat', [
            'models' => $models,
            'defaultModel' => config('services.anthropic.model'),
            'conversations' => $this->conversationList($request),
        ]);
    }

    /**
     * Return a single conversation's messages (owned by the current user).
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwner($request, $conversation);

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'model' => $conversation->model,
            'messages' => $conversation->messages()
                ->orderBy('id')
                ->get(['role', 'content']),
        ]);
    }

    /**
     * Persist the user's message, get Claude's reply, persist it, and return it.
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'content' => ['required', 'string', 'max:8000'],
            'model' => ['required', 'string', Rule::in(array_keys(Config::array('services.anthropic.models')))],
        ]);

        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            return response()->json([
                'message' => 'The chat is not configured yet. Add ANTHROPIC_API_KEY to your .env file.',
            ], 503);
        }

        $userId = $request->user()->id;
        $content = (string) $request->input('content');
        $selectedModel = (string) $request->input('model');
        $conversationId = $request->integer('conversation_id');

        if ($conversationId > 0) {
            $conversation = Conversation::query()
                ->where('user_id', $userId)
                ->findOrFail($conversationId);
        } else {
            $projectId = $request->integer('project_id');

            if ($projectId > 0) {
                // Ensure the project belongs to this user before linking.
                Project::query()->where('user_id', $userId)->findOrFail($projectId);
            }

            $conversation = new Conversation;
            $conversation->user_id = $userId;
            $conversation->project_id = $projectId > 0 ? $projectId : null;
            $conversation->title = Str::limit($content, 48, '…');
            $conversation->model = $selectedModel;
            $conversation->save();
        }

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $content,
        ]);

        $history = [];

        foreach ($conversation->messages()->orderBy('id')->get() as $m) {
            $history[] = [
                'role' => $m->role === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $m->content,
            ];
        }

        try {
            $client = new Client(apiKey: $apiKey);

            $message = $client->messages->create(
                maxTokens: config('services.anthropic.max_tokens', 4096),
                messages: $history,
                model: $selectedModel,
                system: $this->buildSystemPrompt($conversation),
            );

            $reply = '';

            foreach ($message->content as $block) {
                if ($block instanceof TextBlock) {
                    $reply .= $block->text;
                }
            }

            $reply = trim($reply);

            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $reply,
            ]);

            $conversation->model = $selectedModel;
            $conversation->save();

            return response()->json([
                'conversation_id' => $conversation->id,
                'title' => $conversation->title,
                'reply' => $reply,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Sorry — the assistant could not respond right now. Please try again.',
            ], 502);
        }
    }

    /**
     * Delete a conversation (owned by the current user).
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwner($request, $conversation);

        $projectId = $conversation->project_id;
        $conversation->delete();

        return response()->json([
            'conversations' => $this->conversationList($request, $projectId),
        ]);
    }

    /**
     * The current user's conversations, most recently updated first.
     * Scoped to a project, or to standalone chats when $projectId is null.
     *
     * @return array<int, array{id: int, title: string, updated_at: string|null}>
     */
    private function conversationList(Request $request, ?int $projectId = null): array
    {
        $query = $request->user()->conversations()->latest('updated_at');

        if ($projectId === null) {
            $query->whereNull('project_id');
        } else {
            $query->where('project_id', $projectId);
        }

        return $query->get(['id', 'title', 'updated_at'])
            ->map(fn (Conversation $c): array => [
                'id' => $c->id,
                'title' => $c->title,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Base guardrails, plus the conversation's project instructions and memory.
     */
    private function buildSystemPrompt(Conversation $conversation): string
    {
        $system = (string) config('services.anthropic.system_prompt');
        $project = $conversation->project;

        if ($project) {
            if (filled($project->instructions)) {
                $system .= "\n\n## Project instructions\n".$project->instructions;
            }

            if (filled($project->memory)) {
                $system .= "\n\n## Project memory (notes to remember)\n".$project->memory;
            }
        }

        return $system;
    }

    private function ensureOwner(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
    }
}
