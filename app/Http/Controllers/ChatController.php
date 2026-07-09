<?php

namespace App\Http\Controllers;

use Anthropic\Beta\Messages\BetaMCPToolUseBlock;
use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawContentBlockStartEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Beta\Messages\BetaRequestMCPServerURLDefinition;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\Base64PDFSource;
use Anthropic\Messages\CacheControlEphemeral;
use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawMessageDeltaEvent;
use Anthropic\Messages\RawMessageStartEvent;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\TextDelta;
use App\Jobs\DispatchN8nEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\Services\Mcp\McpOAuthService;
use App\Services\TokenBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
            'mcpEnabled' => self::mcpEnabled($request),
        ]);
    }

    /**
     * Whether the current user has at least one enabled MCP server (so the chat
     * routes through the non-streaming, tool-capable path).
     */
    public static function mcpEnabled(Request $request): bool
    {
        return $request->user()->mcpServers()->where('enabled', true)->exists();
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

        // Enforce the per-user token budget before doing any work.
        $budget = app(TokenBudget::class);

        if ($budget->exceeded($request->user())) {
            $snapshot = $budget->snapshot($request->user());

            return response()->json([
                'message' => 'You have used your token allowance for this period. It resets on '
                    .Carbon::parse($snapshot['resets_at'])->toDayDateTimeString().'.',
                'usage_limit' => $snapshot,
            ], 429);
        }

        [$conversation, $userId, $selectedModel] = $this->startTurn($request, $hasFiles);

        try {
            $client = new Client(apiKey: $apiKey);
            $mcp = $this->mcpServerDefs($request->user());

            $reply = null;
            $inputTokens = 0;
            $outputTokens = 0;

            if ($mcp !== []) {
                // Native tool use via the user's MCP servers (server-side). If a
                // server is unreachable/misconfigured, fall back to a plain reply
                // rather than failing the whole turn.
                try {
                    [$reply, $inputTokens, $outputTokens] =
                        $this->completeWithMcp($client, $conversation, $selectedModel, $mcp);
                } catch (Throwable $e) {
                    report($e);
                    Log::warning('MCP request unavailable; answering without tools', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                    $reply = null;
                }
            }

            if ($reply === null) {
                $message = $client->messages->create(
                    maxTokens: config('services.anthropic.max_tokens', 4096),
                    messages: $this->buildHistory($conversation),
                    model: $selectedModel,
                    system: $this->systemBlocks($conversation),
                );

                $reply = '';

                foreach ($message->content as $block) {
                    if ($block instanceof TextBlock) {
                        $reply .= $block->text;
                    }
                }

                $reply = trim($reply);
                $inputTokens = $message->usage->inputTokens;
                $outputTokens = $message->usage->outputTokens;
            }

            $this->finalizeTurn(
                $request,
                $conversation,
                $reply,
                $selectedModel,
                $inputTokens,
                $outputTokens,
                $userId,
            );

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
            Log::error('Claude chat request failed', [
                'user_id' => $userId,
                'conversation_id' => $conversation->id,
                'model' => $selectedModel,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
            report($e);

            return response()->json([
                'message' => 'Sorry — the assistant could not respond right now. Please try again.',
            ], 502);
        }
    }

    /**
     * Same as send(), but streams Claude's reply token-by-token over SSE.
     * Persists the message, records tokens, and fires the n8n event once the
     * stream completes — identical bookkeeping to send().
     */
    public function stream(Request $request): JsonResponse|StreamedResponse
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

        if (app(TokenBudget::class)->exceeded($request->user())) {
            $snapshot = app(TokenBudget::class)->snapshot($request->user());

            return response()->json([
                'message' => 'You have used your token allowance for this period. It resets on '
                    .Carbon::parse($snapshot['resets_at'])->toDayDateTimeString().'.',
                'usage_limit' => $snapshot,
            ], 429);
        }

        [$conversation, $userId, $selectedModel] = $this->startTurn($request, $hasFiles);
        $mcp = $this->mcpServerDefs($request->user());
        $maxTokens = (int) config('services.anthropic.max_tokens', 4096);

        return response()->stream(function () use ($request, $conversation, $mcp, $selectedModel, $maxTokens, $userId, $apiKey): void {
            $this->emit('meta', [
                'conversation_id' => $conversation->id,
                'title' => $conversation->title,
            ]);

            try {
                $client = new Client(apiKey: $apiKey);

                $reply = '';
                $inputTokens = 0;
                $outputTokens = 0;
                $toolNote = '';
                $stream = null;

                if ($mcp !== []) {
                    // Native tool use, streamed: Anthropic runs the MCP tools
                    // server-side and streams the final text. Text-only history.
                    try {
                        $stream = $client->beta->messages->createStream(
                            maxTokens: $maxTokens,
                            messages: $this->textHistory($conversation),
                            model: $selectedModel,
                            system: $this->buildSystemPrompt($conversation),
                            mcpServers: $mcp,
                            betas: [(string) config('services.anthropic.mcp_beta', 'mcp-client-2025-04-04')],
                        );
                    } catch (Throwable $e) {
                        // A connected MCP server is unreachable or misconfigured
                        // (e.g. a wrong server URL). Don't fail the whole chat —
                        // answer without tools and tell the user.
                        report($e);
                        Log::warning('MCP stream unavailable; answering without tools', [
                            'user_id' => $userId,
                            'error' => $e->getMessage(),
                        ]);
                        $toolNote = "_⚠️ Couldn't reach your connected tools just now — answering without them. Check the server URL under Integrations._\n\n";
                    }
                }

                if ($stream === null) {
                    $stream = $client->messages->createStream(
                        maxTokens: $maxTokens,
                        messages: $this->buildHistory($conversation),
                        model: $selectedModel,
                        system: $this->systemBlocks($conversation),
                    );
                }

                if ($toolNote !== '') {
                    $reply .= $toolNote;
                    $this->emit('delta', ['text' => $toolNote]);
                }

                foreach ($stream as $event) {
                    // Text deltas (plain + beta/MCP streams).
                    if ($event instanceof RawContentBlockDeltaEvent && $event->delta instanceof TextDelta) {
                        $reply .= $event->delta->text;
                        $this->emit('delta', ['text' => $event->delta->text]);
                    } elseif ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) {
                        $reply .= $event->delta->text;
                        $this->emit('delta', ['text' => $event->delta->text]);
                    } elseif ($event instanceof BetaRawContentBlockStartEvent && $event->contentBlock instanceof BetaMCPToolUseBlock) {
                        // Surface a tool call as it starts.
                        $this->emit('tool', [
                            'name' => $event->contentBlock->name,
                            'server' => $event->contentBlock->serverName,
                        ]);
                    } elseif ($event instanceof RawMessageStartEvent) {
                        $inputTokens = $event->message->usage->inputTokens;
                    } elseif ($event instanceof BetaRawMessageStartEvent) {
                        $inputTokens = $event->message->usage->inputTokens;
                    } elseif ($event instanceof RawMessageDeltaEvent) {
                        $outputTokens = $event->usage->outputTokens;
                    } elseif ($event instanceof BetaRawMessageDeltaEvent) {
                        $outputTokens = $event->usage->outputTokens;
                    }
                }

                $reply = trim($reply);

                $this->finalizeTurn($request, $conversation, $reply, $selectedModel, $inputTokens, $outputTokens, $userId);

                $this->emit('done', [
                    'reply' => $reply,
                    'usage' => [
                        'prompt_tokens' => $conversation->prompt_tokens,
                        'completion_tokens' => $conversation->completion_tokens,
                    ],
                ]);
            } catch (Throwable $e) {
                Log::error('Claude chat stream failed', [
                    'user_id' => $userId,
                    'conversation_id' => $conversation->id,
                    'model' => $selectedModel,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
                report($e);

                $this->emit('error', [
                    'message' => 'Sorry — the assistant could not respond right now. Please try again.',
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // don't let nginx buffer the stream
        ]);
    }

    /**
     * Send one SSE frame to the client and flush it immediately.
     *
     * @param  array<string, mixed>  $data
     */
    private function emit(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data)."\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    /**
     * Resolve (or create) the conversation, apply the selected skill, store any
     * uploaded files, title a new conversation, and persist the user's message.
     * Shared by send() and stream().
     *
     * @return array{0: Conversation, 1: int, 2: string} [conversation, userId, model]
     */
    private function startTurn(Request $request, bool $hasFiles): array
    {
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

        return [$conversation, $userId, $selectedModel];
    }

    /**
     * Build the (trimmed) message history to send to Claude.
     *
     * @return list<array{role: 'assistant'|'user', content: string|list<ImageBlockParam|DocumentBlockParam|TextBlockParam>}>
     */
    private function buildHistory(Conversation $conversation): array
    {
        $history = [];

        foreach ($this->recentMessages($conversation) as $m) {
            $history[] = [
                'role' => $m->role === 'assistant' ? 'assistant' : 'user',
                'content' => $this->messageContent($m),
            ];
        }

        return $history;
    }

    /**
     * The conversation's messages, trimmed to the most recent N turns so
     * context (and cost) stays bounded. Kept starting on a user turn.
     *
     * @return Collection<int, Message>
     */
    private function recentMessages(Conversation $conversation): Collection
    {
        $messages = $conversation->messages()->orderBy('id')->get();
        $historyLimit = (int) config('services.anthropic.history_limit', 40);

        if ($historyLimit > 0 && $messages->count() > $historyLimit) {
            $messages = $messages->slice($messages->count() - $historyLimit)->values();

            while ($messages->isNotEmpty() && $messages->first()->role === 'assistant') {
                $messages->shift();
            }

            $messages = $messages->values();
        }

        return $messages;
    }

    /**
     * Text-only trimmed history (role + string content) for the beta/MCP path,
     * which uses beta block types incompatible with the rich attachment
     * history builder.
     *
     * @return list<array{role: 'assistant'|'user', content: string}>
     */
    private function textHistory(Conversation $conversation): array
    {
        $messages = [];

        foreach ($this->recentMessages($conversation) as $m) {
            $messages[] = [
                'role' => $m->role === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $m->content,
            ];
        }

        return $messages;
    }

    /**
     * The system prompt as a cached content block (prompt caching).
     *
     * @return list<TextBlockParam>
     */
    private function systemBlocks(Conversation $conversation): array
    {
        return [
            TextBlockParam::with(
                text: $this->buildSystemPrompt($conversation),
                cacheControl: CacheControlEphemeral::with(),
            ),
        ];
    }

    /**
     * The user's enabled MCP servers as request definitions, or [] if none.
     *
     * @return list<BetaRequestMCPServerURLDefinition>
     */
    private function mcpServerDefs(User $user): array
    {
        $defs = [];
        $oauth = app(McpOAuthService::class);

        foreach ($user->mcpServers()->where('enabled', true)->orderBy('id')->get() as $s) {
            if ($s->usesOAuth()) {
                // Refresh if needed; skip (rather than 401 mid-turn) if not connected.
                $token = $oauth->accessToken($s);
                if ($token === null) {
                    continue;
                }
            } else {
                $token = filled($s->auth_token) ? $s->auth_token : null;
            }

            $defs[] = BetaRequestMCPServerURLDefinition::with(
                name: Str::slug($s->name) ?: 'mcp'.$s->id,
                url: $s->url,
                authorizationToken: $token,
            );
        }

        return $defs;
    }

    /**
     * Complete a turn with the user's MCP servers attached. Anthropic runs the
     * MCP tool calls server-side (looping internally) and returns the final
     * text plus mcp_tool_use / mcp_tool_result blocks in one response.
     *
     * Note: the MCP path sends text-only history — per-message image/PDF
     * re-sending is a chat-only feature for now.
     *
     * @param  list<BetaRequestMCPServerURLDefinition>  $mcp
     * @return array{0: string, 1: int, 2: int} [reply, inputTokens, outputTokens]
     */
    private function completeWithMcp(Client $client, Conversation $conversation, string $model, array $mcp): array
    {
        $message = $client->beta->messages->create(
            maxTokens: config('services.anthropic.max_tokens', 4096),
            messages: $this->textHistory($conversation),
            model: $model,
            system: $this->buildSystemPrompt($conversation),
            mcpServers: $mcp,
            betas: [(string) config('services.anthropic.mcp_beta', 'mcp-client-2025-04-04')],
        );

        $reply = '';

        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $reply .= $block->text;
            }
        }

        return [trim($reply), $message->usage->inputTokens, $message->usage->outputTokens];
    }

    /**
     * Persist the assistant reply, update token totals, charge the user's
     * budget, and queue the n8n event. Shared by send() and stream().
     */
    private function finalizeTurn(
        Request $request,
        Conversation $conversation,
        string $reply,
        string $selectedModel,
        int $inputTokens,
        int $outputTokens,
        int $userId,
    ): void {
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply,
        ]);

        $conversation->model = $selectedModel;
        $conversation->prompt_tokens += $inputTokens;
        $conversation->completion_tokens += $outputTokens;
        $conversation->save();

        // Charge this turn's tokens against the user's rolling budget.
        app(TokenBudget::class)->record($request->user(), $inputTokens + $outputTokens);

        // Fire a chat.completed event to each connected webhook provider
        // (n8n / Zapier / generic) on the queue, so a slow webhook never
        // delays the reply. One job per provider → independent retries.
        $payload = [
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'model' => $selectedModel,
            'project_id' => $conversation->project_id,
            'reply' => $reply,
            'usage' => [
                'prompt_tokens' => $conversation->prompt_tokens,
                'completion_tokens' => $conversation->completion_tokens,
            ],
        ];

        $providers = (array) config('integrations.webhook_providers', ['n8n']);

        foreach ($request->user()->integrations()->whereIn('provider', $providers)->pluck('provider') as $provider) {
            DispatchN8nEvent::dispatch($userId, 'chat.completed', $payload, (string) $provider);
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

        // When the user has connected tools, require confirmation before any
        // destructive tool action (create/update/delete/send/…).
        if (config('services.anthropic.tool_safety', true)
            && $conversation->user?->mcpServers()->where('enabled', true)->exists()) {
            $system .= "\n\n".(string) config('services.anthropic.tool_safety_prompt');
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
