<?php

namespace App\Http\Controllers;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\Base64PDFSource;
use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextBlockParam;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
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
            'uploads' => self::uploadsProps(),
            'skills' => self::skillOptions($request),
        ]);
    }

    /**
     * The current user's skills, for the chat skill picker.
     *
     * @return array<int, array{id: int, name: string, icon: string|null}>
     */
    public static function skillOptions(Request $request): array
    {
        return $request->user()->skills()
            ->orderBy('name')
            ->get(['id', 'name', 'icon'])
            ->map(fn (Skill $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'icon' => $s->icon,
            ])
            ->all();
    }

    /**
     * The upload settings the chat UI needs (from config, .env-overridable).
     *
     * @return array{enabled: bool, maxFiles: int, maxSizeKb: int, mimes: string}
     */
    public static function uploadsProps(): array
    {
        $uploads = Config::array('services.anthropic.uploads');

        return [
            'enabled' => (bool) ($uploads['enabled'] ?? false),
            'maxFiles' => (int) ($uploads['max_files'] ?? 0),
            'maxSizeKb' => (int) ($uploads['max_size_kb'] ?? 0),
            'mimes' => (string) ($uploads['mimes'] ?? ''),
        ];
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
            'skill_id' => $conversation->skill_id,
            'prompt_tokens' => $conversation->prompt_tokens,
            'completion_tokens' => $conversation->completion_tokens,
            'messages' => $conversation->messages()
                ->orderBy('id')
                ->get(['role', 'content', 'attachments'])
                ->map(fn (Message $m): array => [
                    'role' => $m->role,
                    'content' => $m->content,
                    'attachments' => $this->publicAttachments($m),
                ])
                ->all(),
        ]);
    }

    /**
     * Persist the user's message, get Claude's reply, persist it, and return it.
     */
    public function send(Request $request): JsonResponse
    {
        $uploads = self::uploadsProps();
        $hasFiles = $uploads['enabled'] && $request->hasFile('files');

        $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'content' => [$hasFiles ? 'nullable' : 'required', 'string', 'max:8000'],
            'model' => ['required', 'string', Rule::in(array_keys(Config::array('services.anthropic.models')))],
            'skill_id' => ['nullable', 'integer'],
            'files' => [$uploads['enabled'] ? 'nullable' : 'prohibited', 'array', 'max:'.$uploads['maxFiles']],
            'files.*' => ['file', 'mimes:'.$uploads['mimes'], 'max:'.$uploads['maxSizeKb']],
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
            $conversation->title = '…'; // set below, once any files are known
            $conversation->model = $selectedModel;
            $conversation->save();
        }

        // Apply the selected skill (owned by the user), or clear it.
        $skillId = $request->integer('skill_id');
        $conversation->skill_id = $skillId > 0
            ? Skill::query()->where('user_id', $userId)->whereKey($skillId)->value('id')
            : null;
        $conversation->save();

        $attachments = $this->storeAttachments($request, $conversation, $hasFiles);

        // Title a brand-new conversation from the message, or the first file.
        if ($conversationId <= 0) {
            $title = filled($content)
                ? Str::limit($content, 48, '…')
                : (string) ($attachments[0]['name'] ?? 'New chat');
            $conversation->title = $title;
            $conversation->save();
        }

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $content,
            'attachments' => $attachments !== [] ? $attachments : null,
        ]);

        $history = [];

        foreach ($conversation->messages()->orderBy('id')->get() as $m) {
            $history[] = [
                'role' => $m->role === 'assistant' ? 'assistant' : 'user',
                'content' => $this->messageContent($m),
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
            $conversation->prompt_tokens += $message->usage->inputTokens;
            $conversation->completion_tokens += $message->usage->outputTokens;
            $conversation->save();

            return response()->json([
                'conversation_id' => $conversation->id,
                'title' => $conversation->title,
                'reply' => $reply,
                'usage' => [
                    'prompt_tokens' => $conversation->prompt_tokens,
                    'completion_tokens' => $conversation->completion_tokens,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Sorry — the assistant could not respond right now. Please try again.',
            ], 502);
        }
    }

    /**
     * Search the current user's conversations by title or message content.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $userId = $request->user()->id;
        $like = '%'.$this->escapeLike($q).'%';

        $conversations = Conversation::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($like): void {
                $query->where('title', 'like', $like)
                    ->orWhereHas('messages', fn ($m) => $m->where('content', 'like', $like));
            })
            ->latest('updated_at')
            ->limit(20)
            ->get(['id', 'title', 'project_id', 'updated_at']);

        $results = $conversations->map(fn (Conversation $c): array => [
            'id' => $c->id,
            'title' => $c->title,
            'project_id' => $c->project_id,
            'snippet' => $this->matchSnippet($c, $like, $q),
        ])->all();

        return response()->json(['results' => $results]);
    }

    /**
     * Delete a conversation (owned by the current user).
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwner($request, $conversation);

        $projectId = $conversation->project_id;
        Storage::deleteDirectory("chat-attachments/{$conversation->id}");
        $conversation->delete();

        return response()->json([
            'conversations' => $this->conversationList($request, $projectId),
        ]);
    }

    /**
     * Persist uploaded files for a conversation and return their metadata.
     *
     * @return list<array{name: string, mime: string, size: int, path: string}>
     */
    private function storeAttachments(Request $request, Conversation $conversation, bool $hasFiles): array
    {
        if (! $hasFiles) {
            return [];
        }

        $stored = [];

        foreach ((array) $request->file('files') as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store("chat-attachments/{$conversation->id}");

            if (! is_string($path)) {
                continue;
            }

            $stored[] = [
                'name' => $file->getClientOriginalName(),
                'mime' => (string) ($file->getMimeType() ?? $file->getClientMimeType()),
                'size' => (int) $file->getSize(),
                'path' => $path,
            ];
        }

        return $stored;
    }

    /**
     * Build the Claude content for a stored message: a plain string, or a list
     * of image/PDF/text blocks when a user message carried attachments. Because
     * this runs over the full history each turn, attachments are re-sent every
     * turn (the file stays "in view" for follow-up questions).
     *
     * @return string|list<ImageBlockParam|DocumentBlockParam|TextBlockParam>
     */
    private function messageContent(Message $m): string|array
    {
        $attachments = $m->attachments ?? [];

        if ($m->role === 'assistant' || $attachments === []) {
            return (string) $m->content;
        }

        $blocks = [];

        foreach ($attachments as $att) {
            $block = $this->fileBlock($att);

            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        if (filled($m->content)) {
            $blocks[] = TextBlockParam::with(text: (string) $m->content);
        }

        return $blocks !== [] ? $blocks : (string) $m->content;
    }

    /**
     * Turn one stored attachment into a Claude image or document block.
     *
     * @param  array{name?: string, mime?: string, size?: int, path?: string}  $att
     */
    private function fileBlock(array $att): ImageBlockParam|DocumentBlockParam|null
    {
        $path = $att['path'] ?? null;

        if (! is_string($path) || ! Storage::exists($path)) {
            return null;
        }

        $data = base64_encode((string) Storage::get($path));
        $mime = (string) ($att['mime'] ?? '');

        if ($mime === 'application/pdf') {
            return DocumentBlockParam::with(
                source: Base64PDFSource::with(data: $data),
                title: (string) ($att['name'] ?? 'document.pdf'),
            );
        }

        if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return ImageBlockParam::with(
                source: Base64ImageSource::with(data: $data, mediaType: $mime),
            );
        }

        return null;
    }

    /**
     * Attachment metadata safe to expose to the browser (no storage paths).
     *
     * @return list<array{name: string, mime: string}>
     */
    private function publicAttachments(Message $m): array
    {
        $out = [];

        foreach ($m->attachments ?? [] as $att) {
            $out[] = [
                'name' => $att['name'],
                'mime' => $att['mime'],
            ];
        }

        return $out;
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
     * Base guardrails, plus the conversation's project instructions/memory and
     * the selected skill's instructions.
     */
    private function buildSystemPrompt(Conversation $conversation): string
    {
        $system = (string) config('services.anthropic.system_prompt');
        $project = $conversation->project;

        if ($project && filled($project->instructions)) {
            $system .= "\n\n## Project instructions\n".$project->instructions;
        }

        $skill = $conversation->skill;

        if ($skill && filled($skill->instructions)) {
            $system .= "\n\n## Active skill: {$skill->name}\n".$skill->instructions;
        }

        return $system;
    }

    private function ensureOwner(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
    }

    /**
     * Escape LIKE wildcards so a user's literal search text isn't treated as a
     * pattern.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * A short excerpt of the first message that matches, centred on the query.
     */
    private function matchSnippet(Conversation $conversation, string $like, string $q): ?string
    {
        $match = $conversation->messages()
            ->where('content', 'like', $like)
            ->orderBy('id')
            ->first(['content']);

        if ($match === null) {
            return null;
        }

        $content = (string) $match->content;
        $pos = stripos($content, $q);

        if ($pos === false) {
            return Str::limit($content, 120);
        }

        $start = max(0, $pos - 40);
        $excerpt = trim(substr($content, $start, 160));

        return ($start > 0 ? '…' : '').$excerpt.(strlen($content) > $start + 160 ? '…' : '');
    }
}
