<?php

namespace App\Http\Controllers;

use Anthropic\Beta\Messages\BetaCacheControlEphemeral;
use Anthropic\Beta\Messages\BetaCitationsDelta;
use Anthropic\Beta\Messages\BetaCitationsWebSearchResultLocation;
use Anthropic\Beta\Messages\BetaMCPToolUseBlock;
use Anthropic\Beta\Messages\BetaMessageParam;
use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawContentBlockStartEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Beta\Messages\BetaRequestMCPServerURLDefinition;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaTextBlockParam;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Beta\Messages\BetaThinkingConfigAdaptive;
use Anthropic\Beta\Messages\BetaThinkingDelta;
use Anthropic\Beta\Messages\BetaTool;
use Anthropic\Beta\Messages\BetaTool\InputSchema as BetaInputSchema;
use Anthropic\Beta\Messages\BetaToolResultBlockParam;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Beta\Messages\BetaToolUseBlockParam;
use Anthropic\Beta\Messages\BetaWebFetchTool20250910;
use Anthropic\Beta\Messages\BetaWebSearchTool20250305;
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
use Anthropic\Messages\ThinkingConfigAdaptive;
use Anthropic\Messages\ThinkingDelta;
use App\Jobs\AutoCompactConversation;
use App\Jobs\DispatchN8nEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\Services\ComposioService;
use App\Services\ConversationCompactor;
use App\Services\Mcp\McpOAuthService;
use App\Services\NetsuiteService;
use App\Services\TokenBudget;
use App\Services\UploadScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
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
            'continuePrompt' => (string) config('services.anthropic.continue_prompt'),
        ]);
    }

    /**
     * Whether the current user has at least one enabled MCP server (so the chat
     * routes through the non-streaming, tool-capable path).
     */
    public static function mcpEnabled(Request $request): bool
    {
        $user = $request->user();

        return $user->mcpServers()->where('enabled', true)->exists()
            || $user->composioConnections()->where('status', 'active')->exists()
            || app(NetsuiteService::class)->enabledFor($user);
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
            'compacted' => filled($conversation->summary),
            'auto_approve' => $conversation->auto_approve,
            'pending_approval' => $this->pendingCalls($conversation) ?: null,
            'messages' => $conversation->messages()
                ->orderBy('id')
                ->get(['id', 'role', 'content', 'thinking', 'feedback', 'attachments'])
                ->map(fn (Message $m): array => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'thinking' => $m->thinking,
                    'feedback' => $m->feedback,
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
            'auto_approve' => ['nullable', 'boolean'],
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
            $user = $request->user();
            $composioKeys = app(ComposioService::class)->activeToolkitKeys($user);
            $netsuite = app(NetsuiteService::class)->enabledFor($user);
            // Cost routing: only ship the schemas the conversation is about.
            [$composioKeys, $netsuite] = $this->routeToolkits($composioKeys, $netsuite, $conversation);
            // Composio + NetSuite tools run through a client-side loop; custom MCP
            // servers run server-side. Prefer the client-side tools when connected.
            $useClientTools = $composioKeys !== [] || $netsuite;
            $mcp = $useClientTools ? [] : $this->mcpServerDefs($user);

            $reply = null;
            $inputTokens = 0;
            $outputTokens = 0;

            if ($useClientTools) {
                try {
                    [$reply, $inputTokens, $outputTokens, $stopReason] =
                        $this->completeWithClientTools($client, $conversation, $selectedModel, $composioKeys, $netsuite);

                    // Hard gate: nothing persists until the user decides via
                    // the toolDecision endpoint (the chat UI shows the card).
                    if ($stopReason === 'approval_required') {
                        return response()->json([
                            'conversation_id' => $conversation->id,
                            'title' => $conversation->title,
                            'reply' => trim((string) $reply),
                            'pending_approval' => $this->pendingCalls($conversation),
                        ]);
                    }
                } catch (Throwable $e) {
                    report($e);
                    Log::warning('Connected tools unavailable; answering without tools', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                    $reply = null;
                }
            } elseif ($mcp !== [] || $this->webToolDefs() !== []) {
                // Server-side tools: the user's MCP servers and/or Claude's
                // native web search + fetch. If unavailable, fall back to a plain
                // reply rather than failing the whole turn.
                try {
                    [$reply, $inputTokens, $outputTokens] =
                        $this->completeWithMcp($client, $conversation, $selectedModel, $mcp, $this->webToolDefs());
                } catch (Throwable $e) {
                    report($e);
                    Log::warning('Server-side tools unavailable; answering without tools', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                    $reply = null;
                }
            }

            if ($reply === null) {
                $message = $client->messages->create(
                    maxTokens: config('services.anthropic.max_tokens', 8192),
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

            $this->maybeAutoTitle($client, $conversation);

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

        // `retry` regenerates the last assistant reply (no new user message);
        // `replace_last` is edit-and-resend (drops the last exchange first).
        $isRetry = $request->boolean('retry');

        $request->validate([
            'conversation_id' => [$isRetry ? 'required' : 'nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'content' => [($hasFiles || $isRetry) ? 'nullable' : 'required', 'string', 'max:8000'],
            'model' => ['required', 'string', Rule::in(array_keys(Config::array('services.anthropic.models')))],
            'skill_id' => ['nullable', 'integer'],
            'auto_approve' => ['nullable', 'boolean'],
            'thinking' => ['nullable', 'boolean'],
            'retry' => ['nullable', 'boolean'],
            'replace_last' => ['nullable', 'boolean'],
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
        $composioKeys = app(ComposioService::class)->activeToolkitKeys($request->user());
        $netsuite = app(NetsuiteService::class)->enabledFor($request->user());
        // Cost routing: only ship the schemas the conversation is about.
        [$composioKeys, $netsuite] = $this->routeToolkits($composioKeys, $netsuite, $conversation);
        $useClientTools = $composioKeys !== [] || $netsuite;
        $mcp = $useClientTools ? [] : $this->mcpServerDefs($request->user());
        $maxTokens = (int) config('services.anthropic.max_tokens', 8192);
        $thinkingOn = $this->thinkingEnabled($request, $selectedModel);

        return response()->stream(function () use ($request, $conversation, $mcp, $composioKeys, $netsuite, $useClientTools, $selectedModel, $maxTokens, $thinkingOn, $userId, $apiKey): void {
            $this->emit('meta', [
                'conversation_id' => $conversation->id,
                'title' => $conversation->title,
            ]);

            try {
                $client = new Client(apiKey: $apiKey);

                $reply = '';
                $thinking = '';
                $inputTokens = 0;
                $outputTokens = 0;
                $stopReason = null;
                $toolNote = '';
                $stream = null;
                $handled = false;
                $citations = []; // url => title, from web-search results

                if ($useClientTools) {
                    // Composio + NetSuite tools: AiMe runs the tool loop itself
                    // (non-streamed — it executes each call server-side), then
                    // emits the final answer as one block. On failure, fall back
                    // to a plain reply.
                    try {
                        [$reply, $inputTokens, $outputTokens, $stopReason] =
                            $this->completeWithClientTools($client, $conversation, $selectedModel, $composioKeys, $netsuite);

                        // Hard gate: the turn paused before a destructive tool
                        // call. Surface the approval card and stop — nothing is
                        // persisted until the user decides (see toolDecision).
                        if ($stopReason === 'approval_required') {
                            $reply = trim($reply);

                            if ($reply !== '') {
                                $this->emit('delta', ['text' => $reply]);
                            }

                            $this->emit('approval', ['calls' => $this->pendingCalls($conversation)]);
                            $this->emit('done', [
                                'reply' => $reply,
                                'stop_reason' => 'approval_required',
                                'usage' => [
                                    'prompt_tokens' => $conversation->prompt_tokens,
                                    'completion_tokens' => $conversation->completion_tokens,
                                ],
                            ]);

                            return;
                        }

                        $reply = trim($reply);
                        if ($reply !== '') {
                            $this->emit('delta', ['text' => $reply]);
                        }
                        $handled = true;
                    } catch (Throwable $e) {
                        report($e);
                        Log::warning('Connected tools unavailable; answering without tools', [
                            'user_id' => $userId,
                            'error' => $e->getMessage(),
                        ]);
                        $toolNote = "_⚠️ Couldn't use your connected tools just now — answering without them._\n\n";
                        $reply = '';
                    }
                }

                if (! $handled) {
                    $webTools = $this->webToolDefs();

                    if ($mcp !== [] || $webTools !== []) {
                        // Beta endpoint: MCP servers and/or Claude's native web
                        // search + fetch (all server-side). Anthropic runs them
                        // and streams the final text. Text-only history. On
                        // failure, fall back to a plain reply.
                        try {
                            $stream = $client->beta->messages->createStream(
                                maxTokens: $maxTokens,
                                messages: $this->textHistory($conversation),
                                model: $selectedModel,
                                system: $this->betaSystemBlocks($conversation),
                                mcpServers: $mcp !== [] ? $mcp : null,
                                thinking: $thinkingOn ? BetaThinkingConfigAdaptive::with(display: 'summarized') : null,
                                tools: $webTools !== [] ? $webTools : null,
                                betas: $this->betaFlags($mcp !== [], $webTools !== []),
                            );
                        } catch (Throwable $e) {
                            report($e);
                            Log::warning('Beta tool stream unavailable; answering without tools', [
                                'user_id' => $userId,
                                'error' => $e->getMessage(),
                            ]);
                            $toolNote = $mcp !== []
                                ? "_⚠️ Couldn't reach your connected tools just now — answering without them. Check the server URL under Integrations._\n\n"
                                : "_⚠️ Couldn't use web search just now — answering without it._\n\n";
                        }
                    }

                    if ($stream === null) {
                        $stream = $client->messages->createStream(
                            maxTokens: $maxTokens,
                            messages: $this->buildHistory($conversation),
                            model: $selectedModel,
                            system: $this->systemBlocks($conversation),
                            thinking: $thinkingOn ? ThinkingConfigAdaptive::with(display: 'summarized') : null,
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
                        } elseif ($event instanceof RawContentBlockDeltaEvent && $event->delta instanceof ThinkingDelta) {
                            // Extended thinking: stream the summarized thought
                            // process to its own (collapsible) UI block.
                            $thinking .= $event->delta->thinking;
                            $this->emit('thinking', ['text' => $event->delta->thinking]);
                        } elseif ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaThinkingDelta) {
                            $thinking .= $event->delta->thinking;
                            $this->emit('thinking', ['text' => $event->delta->thinking]);
                        } elseif ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaCitationsDelta) {
                            // Web-search citation metadata — collect the source
                            // so it isn't silently dropped from the answer.
                            $citation = $event->delta->citation;
                            if ($citation instanceof BetaCitationsWebSearchResultLocation) {
                                $citations[$citation->url] = $citation->title ?: $citation->url;
                            }
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
                            $stopReason = $event->delta->stopReason ?? $stopReason;
                        } elseif ($event instanceof BetaRawMessageDeltaEvent) {
                            $outputTokens = $event->usage->outputTokens;
                            $stopReason = $event->delta->stopReason ?? $stopReason;
                        }
                    }

                    $reply = trim($reply);

                    // Sources footer — the citation links claude.ai shows inline.
                    if ($reply !== '' && $citations !== []) {
                        $footer = $this->sourcesFooter($citations);
                        $reply .= $footer;
                        $this->emit('delta', ['text' => $footer]);
                    }
                }

                $assistantMessage = $this->finalizeTurn($request, $conversation, $reply, $selectedModel, $inputTokens, $outputTokens, $userId, trim($thinking));

                // First exchange: swap the placeholder title for a generated one.
                if (($newTitle = $this->maybeAutoTitle($client, $conversation)) !== null) {
                    $this->emit('title', ['title' => $newTitle]);
                }

                // stop_reason lets the UI offer "Continue" when the reply was
                // cut off at the max-token cap instead of just going quiet;
                // message_id lets it attach feedback to the fresh reply.
                $this->emit('done', [
                    'reply' => $reply,
                    'message_id' => $assistantMessage->id,
                    'stop_reason' => $stopReason,
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

        // Session toggle: when on, skip the confirm-before-destructive-actions
        // guardrail (applied per turn from the chat UI).
        $conversation->auto_approve = $request->boolean('auto_approve');
        // A new turn supersedes any tool call still paused at the approval
        // gate — the user moved on, so the stale pending state is dropped.
        $conversation->pending_tool_state = null;
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

        // Retry: regenerate the last assistant reply — drop it and replay the
        // history as-is (no new user message). Edit-and-resend (replace_last):
        // drop the whole last exchange, then store the edited message below.
        if ($conversationId > 0 && $request->boolean('retry')) {
            $this->deleteLastMessageIf($conversation, 'assistant');

            return [$conversation, $userId, $selectedModel];
        }

        if ($conversationId > 0 && $request->boolean('replace_last')) {
            $this->deleteLastMessageIf($conversation, 'assistant');
            $this->deleteLastMessageIf($conversation, 'user');
        }

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $content,
            'attachments' => $attachments !== [] ? $attachments : null,
        ]);

        return [$conversation, $userId, $selectedModel];
    }

    /**
     * Delete the conversation's newest message when it has the given role.
     * Messages already folded into a compaction summary are left alone — the
     * summary references them, and the replayed window starts after them.
     */
    private function deleteLastMessageIf(Conversation $conversation, string $role): void
    {
        $last = $conversation->messages()->orderByDesc('id')->first();

        if ($last !== null
            && $last->role === $role
            && $last->id > (int) $conversation->summary_through_id) {
            $last->delete();
        }
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
        $query = $conversation->messages()->orderBy('id');

        // Once compacted, only replay messages newer than the summary — the
        // summary (injected into the system prompt) stands in for the rest.
        if ($conversation->summary_through_id) {
            $query->where('id', '>', $conversation->summary_through_id);
        }

        $messages = $query->get();
        $historyLimit = (int) config('services.anthropic.history_limit', 40);

        if ($historyLimit > 0 && $messages->count() > $historyLimit) {
            $messages = $messages->slice($messages->count() - $historyLimit)->values();
        }

        // The replayed window must always open on a user turn — the API expects
        // user/assistant alternation starting with user. Applies to both the
        // count trim above and the post-compaction (summary_through_id) filter.
        // Note we persist only plain text turns (never tool_use/tool_result
        // blocks — the tool loop is in-memory within a turn), so trimming can
        // never orphan a tool exchange.
        while ($messages->isNotEmpty() && $messages->first()->role === 'assistant') {
            $messages->shift();
        }

        return $messages->values();
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
     * Cost routing: when several toolkits are connected, only send the schemas
     * of the toolkit(s) this conversation actually mentions — keyword match
     * over the replayed user turns; a toolkit's key always counts as a
     * keyword. Nothing matched (or routing off, or only one source connected)
     * → everything ships, so behavior degrades safely to the unrouted case.
     *
     * @param  list<string>  $toolkitKeys
     * @return array{0: list<string>, 1: bool} [toolkitKeys, netsuite]
     */
    private function routeToolkits(array $toolkitKeys, bool $netsuite, Conversation $conversation): array
    {
        $sources = count($toolkitKeys) + ($netsuite ? 1 : 0);

        if (! config('services.composio.toolkit_routing', true) || $sources < 2) {
            return [$toolkitKeys, $netsuite];
        }

        $haystack = mb_strtolower($this->recentMessages($conversation)
            ->where('role', 'user')
            ->map(fn (Message $m): string => (string) $m->content)
            ->implode("\n"));

        $matched = [];

        foreach ($toolkitKeys as $key) {
            $keywords = array_merge([(string) $key], (array) config("services.composio.toolkits.{$key}.keywords", []));

            if ($this->matchesAny($haystack, $keywords)) {
                $matched[] = $key;
            }
        }

        $netsuiteMatched = $netsuite && $this->matchesAny(
            $haystack,
            array_merge(['netsuite'], (array) config('services.netsuite.keywords', [])),
        );

        // Never silently strip ALL tools — an unmatched turn keeps everything.
        if ($matched === [] && ! $netsuiteMatched) {
            return [$toolkitKeys, $netsuite];
        }

        return [$matched, $netsuiteMatched];
    }

    /**
     * @param  array<int, mixed>  $keywords
     */
    private function matchesAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim((string) $keyword));

            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cap a tool result before it goes back to the model — the tool loop
     * replays every result on each round within the turn, so one oversized
     * payload multiplies fast. The note steers the model to narrow the query
     * rather than assume it saw everything.
     */
    private function truncateToolResult(string $content): string
    {
        $max = (int) config('services.anthropic.tool_result_max_chars', 20000);

        if ($max <= 0 || mb_strlen($content) <= $max) {
            return $content;
        }

        return mb_substr($content, 0, $max)
            ."\n\n[Result truncated — showing the first {$max} of ".mb_strlen($content)
            .' characters. Narrow the query (add filters, select fewer fields, or lower the limit) instead of assuming you saw everything.]';
    }

    /**
     * Whether this turn should request extended thinking: feature on, the user
     * toggled it, and the selected model supports adaptive thinking.
     */
    private function thinkingEnabled(Request $request, string $model): bool
    {
        $supported = array_map('trim', explode(',', (string) config('services.anthropic.thinking_models', '')));

        return (bool) config('services.anthropic.thinking', true)
            && $request->boolean('thinking')
            && in_array($model, $supported, true);
    }

    /**
     * A Markdown "Sources" footer for web-search citations, so the links the
     * model cited aren't dropped from the rendered answer.
     *
     * @param  array<string, string>  $citations  url => title
     */
    private function sourcesFooter(array $citations): string
    {
        $links = [];

        foreach ($citations as $url => $title) {
            $links[] = '['.str_replace(['[', ']'], '', $title).']('.$url.')';
        }

        return "\n\n**Sources:** ".implode(' · ', $links);
    }

    /**
     * Collect web-search citations (url => title) off a text block.
     *
     * @param  array<string, string>  $citations
     * @return array<string, string>
     */
    private function collectCitations(BetaTextBlock $block, array $citations): array
    {
        foreach ($block->citations ?? [] as $citation) {
            if ($citation instanceof BetaCitationsWebSearchResultLocation) {
                $citations[$citation->url] = $citation->title ?: $citation->url;
            }
        }

        return $citations;
    }

    /**
     * The system prompt as a cached content block for the BETA endpoints. The
     * cache prefix is built tools → system → messages, so this one breakpoint
     * also caches every tool schema sent before it (the biggest static cost on
     * the connected-tools path).
     *
     * @return list<BetaTextBlockParam>
     */
    private function betaSystemBlocks(Conversation $conversation): array
    {
        return [
            BetaTextBlockParam::with(
                text: $this->buildSystemPrompt($conversation),
                cacheControl: BetaCacheControlEphemeral::with(),
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
     * Claude's native server-side web tools (search + fetch), or [] when the
     * feature is off. Anthropic executes these itself; we just declare them.
     *
     * @return list<BetaWebSearchTool20250305|BetaWebFetchTool20250910>
     */
    private function webToolDefs(): array
    {
        if (! config('services.anthropic.web_tools', false)) {
            return [];
        }

        $maxUses = (int) config('services.anthropic.web_tool_max_uses', 5);

        // Web search is GA; web fetch is beta and separately toggleable.
        $defs = [BetaWebSearchTool20250305::with(maxUses: $maxUses)];

        if ($this->webFetchOn()) {
            $defs[] = BetaWebFetchTool20250910::with(maxUses: $maxUses);
        }

        return $defs;
    }

    private function webFetchOn(): bool
    {
        return (bool) config('services.anthropic.web_tools', false)
            && (bool) config('services.anthropic.web_fetch', true);
    }

    /**
     * Beta header flags required for the active beta features.
     *
     * @return list<string>
     */
    private function betaFlags(bool $mcp, bool $webTools): array
    {
        $flags = [];

        if ($mcp) {
            $flags[] = (string) config('services.anthropic.mcp_beta', 'mcp-client-2025-04-04');
        }

        // Web fetch is beta; web search is GA and needs no flag. Only add the
        // fetch beta when fetch is actually enabled.
        if ($webTools && $this->webFetchOn()) {
            $flags[] = (string) config('services.anthropic.web_fetch_beta', 'web-fetch-2025-09-10');
        }

        return $flags;
    }

    /**
     * Run a client-side tool-use loop over the user's connected tools (Composio
     * toolkits and/or the native NetSuite integration). AiMe executes each tool
     * call itself server-side and feeds the result back to Claude until it stops
     * calling tools — the Composio MCP endpoint needs an `x-api-key` header the
     * MCP connector can't send, and NetSuite needs OAuth 1.0a request signing.
     * Returns [reply, inputTokens, outputTokens].
     *
     * A destructive tool call (see isDestructiveTool) pauses the loop at the
     * HARD GATE: state is persisted on the conversation and stopReason comes
     * back as 'approval_required'. Pass that state back as $resume (via the
     * toolDecision endpoint) to execute the approved calls and continue.
     *
     * @param  list<string>  $toolkitKeys
     * @param  array<string, mixed>|null  $resume
     * @return array{0: string, 1: int, 2: int, 3: string|null} [reply, inputTokens, outputTokens, stopReason]
     */
    private function completeWithClientTools(Client $client, Conversation $conversation, string $model, array $toolkitKeys, bool $netsuite, ?array $resume = null): array
    {
        $composio = app(ComposioService::class);
        $netsuiteService = app(NetsuiteService::class);

        $schemas = $composio->toolSchemas($toolkitKeys);

        if ($netsuite) {
            $schemas = array_merge($schemas, $netsuiteService->toolSchemas());
        }

        if ($schemas === []) {
            throw new RuntimeException('No connected tools available for this user.');
        }

        // Custom (client-executed) tools as beta params. We use the beta
        // endpoint so Claude's native, server-side web search + fetch can run
        // alongside the user's connected tools in the same loop — otherwise a
        // user with Slack/NetSuite connected would lose web access entirely.
        $tools = [];
        foreach ($schemas as $schema) {
            $properties = is_array($schema['input_schema']['properties'] ?? null)
                ? $schema['input_schema']['properties']
                : [];
            $required = array_values(array_filter(
                (array) ($schema['input_schema']['required'] ?? []),
                'is_string',
            ));

            $tools[] = BetaTool::with(
                inputSchema: BetaInputSchema::with(properties: $properties, required: $required),
                name: $schema['name'],
                description: $schema['description'],
            );
        }

        $webTools = $this->webToolDefs();
        $tools = array_merge($tools, $webTools);

        // Cached system block — its breakpoint also covers the (large) tool
        // schema list, and hits again on every round of the loop below.
        $system = $this->betaSystemBlocks($conversation);
        $maxTokens = (int) config('services.anthropic.max_tokens', 8192);
        $maxRounds = (int) config('services.composio.max_tool_rounds', 8);

        // Alongside the live param list we keep a JSON-safe mirror ($plain) of
        // the same messages, so the loop can PAUSE at the hard approval gate
        // (state persisted on the conversation) and resume after the user's
        // Approve/Cancel decision.
        if ($resume !== null) {
            $plain = array_values((array) ($resume['messages'] ?? []));
            $messages = $this->betaMessagesFromPlain($plain);
            $reply = (string) ($resume['reply'] ?? '');
            $inputTokens = (int) ($resume['input_tokens'] ?? 0);
            $outputTokens = (int) ($resume['output_tokens'] ?? 0);
            $citations = (array) ($resume['citations'] ?? []);

            // The user approved: run the paused calls, then rejoin the loop.
            [$messages, $plain] = $this->applyToolResults(
                $conversation, $this->normalizedCalls((array) ($resume['pending'] ?? [])), $messages, $plain,
            );
        } else {
            $plain = $this->textHistory($conversation);
            $messages = $plain;
            $reply = '';
            $inputTokens = 0;
            $outputTokens = 0;
            $citations = [];
        }

        for ($round = 0; $round < $maxRounds; $round++) {
            $message = $client->beta->messages->create(
                maxTokens: $maxTokens,
                messages: $messages,
                model: $model,
                system: $system,
                tools: $tools,
                betas: $this->betaFlags(false, $webTools !== []),
            );

            $inputTokens += $message->usage->inputTokens;
            $outputTokens += $message->usage->outputTokens;

            $assistantContent = [];
            $assistantPlain = [];
            $toolUses = [];

            foreach ($message->content as $block) {
                if ($block instanceof BetaTextBlock) {
                    $reply .= $block->text;
                    $citations = $this->collectCitations($block, $citations);
                    $assistantContent[] = BetaTextBlockParam::with(text: $block->text);
                    $assistantPlain[] = ['type' => 'text', 'text' => $block->text];
                } elseif ($block instanceof BetaToolUseBlock) {
                    $toolUses[] = $block;
                    $assistantContent[] = BetaToolUseBlockParam::with(
                        id: $block->id,
                        input: (array) $block->input,
                        name: $block->name,
                    );
                    $assistantPlain[] = [
                        'type' => 'tool_use',
                        'id' => $block->id,
                        'name' => $block->name,
                        'input' => (array) $block->input,
                    ];
                }
                // Server-side web tool blocks resolve within the same response;
                // they need no client handling and aren't replayed.
            }

            if ($message->stopReason !== 'tool_use' || $toolUses === []) {
                if (trim($reply) !== '' && $citations !== []) {
                    $reply .= $this->sourcesFooter($citations);
                }

                return [$reply, $inputTokens, $outputTokens, $message->stopReason];
            }

            $messages[] = BetaMessageParam::with(content: $assistantContent, role: 'assistant');
            $plain[] = ['role' => 'assistant', 'content' => $assistantPlain];

            // HARD GATE: a destructive call pauses the whole round — nothing
            // executes until the user clicks Approve in the chat. The paused
            // state (encrypted) carries everything needed to resume exactly.
            if ($this->gateActive($conversation) && $this->anyDestructive($toolUses)) {
                $conversation->pending_tool_state = [
                    'model' => $model,
                    'toolkits' => $toolkitKeys,
                    'netsuite' => $netsuite,
                    'reply' => $reply,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'citations' => $citations,
                    'messages' => $plain,
                    'pending' => array_map(fn (BetaToolUseBlock $t): array => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'input' => (array) $t->input,
                    ], $toolUses),
                ];
                $conversation->save();

                return [$reply, $inputTokens, $outputTokens, 'approval_required'];
            }

            [$messages, $plain] = $this->applyToolResults(
                $conversation,
                array_map(fn (BetaToolUseBlock $t): array => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'input' => (array) $t->input,
                ], $toolUses),
                $messages,
                $plain,
            );
        }

        return [$reply, $inputTokens, $outputTokens, null];
    }

    /**
     * Execute a batch of tool calls and append the results to both the live
     * param list and its JSON-safe mirror. Shared by the normal loop and the
     * post-approval resume.
     *
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $calls
     * @param  list<mixed>  $messages
     * @param  list<mixed>  $plain
     * @return array{0: list<mixed>, 1: list<mixed>}
     */
    private function applyToolResults(Conversation $conversation, array $calls, array $messages, array $plain): array
    {
        $composio = app(ComposioService::class);
        $netsuiteService = app(NetsuiteService::class);
        $user = $conversation->user;

        $results = [];
        $resultsPlain = [];

        foreach ($calls as $call) {
            if ($user === null) {
                $result = ['ok' => false, 'output' => 'No user context.'];
            } elseif ($netsuiteService->isNetsuiteTool($call['name'])) {
                $result = $netsuiteService->execute($user, $call['name'], (array) $call['input']);
            } else {
                $result = $composio->execute($user, $call['name'], (array) $call['input']);
            }

            $content = is_string($result['output'])
                ? $result['output']
                : (string) json_encode($result['output']);
            $content = $this->truncateToolResult($content);
            $content = $content !== '' ? $content : '(no output)';

            $results[] = BetaToolResultBlockParam::with(
                toolUseID: $call['id'],
                content: $content,
                isError: ! $result['ok'],
            );
            $resultsPlain[] = [
                'type' => 'tool_result',
                'tool_use_id' => $call['id'],
                'content' => $content,
                'is_error' => ! $result['ok'],
            ];
        }

        $messages[] = BetaMessageParam::with(content: $results, role: 'user');
        $plain[] = ['role' => 'user', 'content' => $resultsPlain];

        return [$messages, $plain];
    }

    /**
     * Normalize raw pending-call entries from stored gate state.
     *
     * @param  array<int|string, mixed>  $raw
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    private function normalizedCalls(array $raw): array
    {
        $calls = [];

        foreach ($raw as $call) {
            if (! is_array($call)) {
                continue;
            }

            $input = $call['input'] ?? [];

            $calls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['name'] ?? ''),
                'input' => is_array($input) ? $input : [],
            ];
        }

        return $calls;
    }

    /**
     * Rebuild live SDK params from the JSON-safe mirror stored while paused at
     * the approval gate.
     *
     * @param  list<mixed>  $plain
     * @return list<mixed>
     */
    private function betaMessagesFromPlain(array $plain): array
    {
        $out = [];

        foreach ($plain as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $role = ($entry['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $content = $entry['content'] ?? '';

            if (is_string($content)) {
                $out[] = ['role' => $role, 'content' => $content];

                continue;
            }

            $blocks = [];

            foreach ((array) $content as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $blocks[] = match ((string) ($block['type'] ?? '')) {
                    'tool_use' => BetaToolUseBlockParam::with(
                        id: (string) ($block['id'] ?? ''),
                        input: is_array($block['input'] ?? null) ? $block['input'] : [],
                        name: (string) ($block['name'] ?? ''),
                    ),
                    'tool_result' => BetaToolResultBlockParam::with(
                        toolUseID: (string) ($block['tool_use_id'] ?? ''),
                        content: (string) ($block['content'] ?? ''),
                        isError: (bool) ($block['is_error'] ?? false),
                    ),
                    default => BetaTextBlockParam::with(text: (string) ($block['text'] ?? '')),
                };
            }

            $out[] = BetaMessageParam::with(content: $blocks, role: $role);
        }

        return $out;
    }

    /**
     * The hard approval gate applies when the feature (and tool safety) is on
     * and the conversation hasn't opted into auto-approve.
     */
    private function gateActive(Conversation $conversation): bool
    {
        return (bool) config('services.anthropic.tool_hard_gate', true)
            && (bool) config('services.anthropic.tool_safety', true)
            && ! $conversation->auto_approve;
    }

    /**
     * @param  list<BetaToolUseBlock>  $toolUses
     */
    private function anyDestructive(array $toolUses): bool
    {
        foreach ($toolUses as $toolUse) {
            if ($this->isDestructiveTool($toolUse->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A tool is destructive when its name contains one of the configured verb
     * tokens (name split on _/-/whitespace) — SLACK_SEND_MESSAGE matches
     * "send"; netsuite_suiteql and GET/LIST/SEARCH tools never match.
     */
    private function isDestructiveTool(string $name): bool
    {
        $verbs = array_filter(array_map(
            static fn (string $v): string => mb_strtolower(trim($v)),
            explode(',', (string) config('services.anthropic.tool_gate_verbs', '')),
        ));
        $tokens = preg_split('/[_\-\s]+/', mb_strtolower($name)) ?: [];

        return array_intersect($verbs, $tokens) !== [];
    }

    /**
     * Complete a turn with server-side tools attached — the user's MCP servers
     * and/or Claude's native web search + fetch. Anthropic runs the tool calls
     * server-side (looping internally) and returns the final text in one
     * response.
     *
     * Note: this path sends text-only history — per-message image/PDF
     * re-sending is a chat-only feature for now.
     *
     * @param  list<BetaRequestMCPServerURLDefinition>  $mcp
     * @param  list<BetaWebSearchTool20250305|BetaWebFetchTool20250910>  $webTools
     * @return array{0: string, 1: int, 2: int, 3: string|null} [reply, inputTokens, outputTokens, stopReason]
     */
    private function completeWithMcp(Client $client, Conversation $conversation, string $model, array $mcp, array $webTools = []): array
    {
        $message = $client->beta->messages->create(
            maxTokens: config('services.anthropic.max_tokens', 8192),
            messages: $this->textHistory($conversation),
            model: $model,
            system: $this->betaSystemBlocks($conversation),
            mcpServers: $mcp !== [] ? $mcp : null,
            tools: $webTools !== [] ? $webTools : null,
            betas: $this->betaFlags($mcp !== [], $webTools !== []),
        );

        $reply = '';
        $citations = [];

        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $reply .= $block->text;
                $citations = $this->collectCitations($block, $citations);
            }
        }

        $reply = trim($reply);

        if ($reply !== '' && $citations !== []) {
            $reply .= $this->sourcesFooter($citations);
        }

        return [$reply, $message->usage->inputTokens, $message->usage->outputTokens, $message->stopReason];
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
        ?string $thinking = null,
    ): Message {
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply,
            'thinking' => filled($thinking) ? $thinking : null,
        ]);

        $conversation->model = $selectedModel;
        $conversation->prompt_tokens += $inputTokens;
        $conversation->completion_tokens += $outputTokens;
        $conversation->save();

        // Charge this turn's tokens against the user's rolling budget.
        app(TokenBudget::class)->record($request->user(), $inputTokens + $outputTokens);

        // Auto-compact: this turn's input size IS the replayed context — once it
        // crosses the threshold, summarize in the background (like claude.ai)
        // instead of waiting for the user to notice degradation.
        $compactThreshold = (int) config('services.anthropic.auto_compact_tokens', 100000);

        if ($compactThreshold > 0 && $inputTokens >= $compactThreshold) {
            AutoCompactConversation::dispatch($conversation->id);
        }

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

        return $assistantMessage;
    }

    /**
     * Thumbs up/down on an assistant reply. Stored as 1 / -1 / null on the
     * message; sending the same rating again clears it (toggle).
     */
    public function feedback(Request $request, Message $message): JsonResponse
    {
        abort_unless($message->conversation?->user_id === $request->user()->id, 404);
        abort_unless($message->role === 'assistant', 422);

        $validated = $request->validate([
            'rating' => ['required', Rule::in(['up', 'down', 'none'])],
        ]);

        $message->feedback = match ($validated['rating']) {
            'up' => 1,
            'down' => -1,
            default => null,
        };
        $message->save();

        return response()->json(['feedback' => $message->feedback]);
    }

    /**
     * The user's Approve / Cancel decision for a turn paused at the hard tool
     * gate. Consumes the pending state exactly once (a double click can't run
     * the tools twice), then either resumes the loop (approve) or finalizes
     * the turn with a cancellation note (cancel). Streams the outcome as SSE,
     * same shape as stream().
     */
    public function toolDecision(Request $request, Conversation $conversation): JsonResponse|StreamedResponse
    {
        $this->ensureOwner($request, $conversation);

        $request->validate(['approve' => ['required', 'boolean']]);

        $state = $conversation->pending_tool_state;

        abort_unless(is_array($state) && ($state['pending'] ?? []) !== [], 404);

        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            return response()->json([
                'message' => 'The chat is not configured yet. Add ANTHROPIC_API_KEY to your .env file.',
            ], 503);
        }

        // Consume the pending state before doing anything with it.
        $conversation->pending_tool_state = null;
        $conversation->save();

        $approve = $request->boolean('approve');
        $userId = $request->user()->id;
        $model = (string) ($state['model'] ?? $conversation->model);

        return response()->stream(function () use ($request, $conversation, $state, $approve, $model, $userId, $apiKey): void {
            try {
                if (! $approve) {
                    // Cancelled: no tool runs, no model call. Persist what the
                    // assistant said so far plus an explicit cancellation note,
                    // and charge the tokens already spent.
                    $names = implode(', ', array_map(
                        static fn (array $c): string => (string) ($c['name'] ?? ''),
                        (array) $state['pending'],
                    ));
                    $reply = trim((string) ($state['reply'] ?? ''));
                    $reply .= ($reply !== '' ? "\n\n" : '')."_Action cancelled — I did not run: {$names}._";

                    $assistantMessage = $this->finalizeTurn(
                        $request, $conversation, $reply, $model,
                        (int) ($state['input_tokens'] ?? 0), (int) ($state['output_tokens'] ?? 0), $userId,
                    );

                    $this->emit('delta', ['text' => $reply]);
                    $this->emit('done', [
                        'reply' => $reply,
                        'message_id' => $assistantMessage->id,
                        'stop_reason' => 'cancelled',
                        'usage' => [
                            'prompt_tokens' => $conversation->prompt_tokens,
                            'completion_tokens' => $conversation->completion_tokens,
                        ],
                    ]);

                    return;
                }

                $client = new Client(apiKey: $apiKey);

                $toolkits = array_values(array_filter((array) ($state['toolkits'] ?? []), 'is_string'));

                [$reply, $inputTokens, $outputTokens, $stopReason] = $this->completeWithClientTools(
                    $client, $conversation, $model,
                    $toolkits, (bool) ($state['netsuite'] ?? false), $state,
                );

                // The continuation hit ANOTHER destructive call — gate again.
                if ($stopReason === 'approval_required') {
                    $this->emit('approval', ['calls' => $this->pendingCalls($conversation->fresh() ?? $conversation)]);
                    $this->emit('done', [
                        'reply' => trim($reply),
                        'stop_reason' => 'approval_required',
                        'usage' => [
                            'prompt_tokens' => $conversation->prompt_tokens,
                            'completion_tokens' => $conversation->completion_tokens,
                        ],
                    ]);

                    return;
                }

                $reply = trim($reply);

                if ($reply !== '') {
                    $this->emit('delta', ['text' => $reply]);
                }

                $assistantMessage = $this->finalizeTurn($request, $conversation, $reply, $model, $inputTokens, $outputTokens, $userId);

                $this->emit('done', [
                    'reply' => $reply,
                    'message_id' => $assistantMessage->id,
                    'stop_reason' => $stopReason,
                    'usage' => [
                        'prompt_tokens' => $conversation->prompt_tokens,
                        'completion_tokens' => $conversation->completion_tokens,
                    ],
                ]);
            } catch (Throwable $e) {
                Log::error('Tool decision failed', [
                    'user_id' => $userId,
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
                report($e);

                $this->emit('error', [
                    'message' => 'Sorry — the assistant could not continue right now. Please try again.',
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * The calls awaiting approval, shaped for the UI card.
     *
     * @return list<array{name: string, input: array<string, mixed>}>
     */
    private function pendingCalls(Conversation $conversation): array
    {
        return array_values(array_map(
            static fn (array $c): array => [
                'name' => (string) ($c['name'] ?? ''),
                'input' => (array) ($c['input'] ?? []),
            ],
            (array) ($conversation->pending_tool_state['pending'] ?? []),
        ));
    }

    /**
     * After the FIRST exchange, replace the truncated-first-message title with
     * a short model-generated one (like claude.ai). Returns the new title, or
     * null when auto-titling is off / not the first exchange / the call fails —
     * the fallback title from startTurn() stays in place.
     */
    private function maybeAutoTitle(Client $client, Conversation $conversation): ?string
    {
        if (! config('services.anthropic.auto_title', true)
            || $conversation->messages()->count() !== 2) {
            return null;
        }

        try {
            $transcript = $conversation->messages()
                ->orderBy('id')
                ->get(['role', 'content'])
                ->map(fn (Message $m): string => ($m->role === 'assistant' ? 'Assistant' : 'User').': '
                    .Str::limit(trim((string) $m->content), 600, '…'))
                ->implode("\n\n");

            $message = $client->messages->create(
                maxTokens: 32,
                messages: [['role' => 'user', 'content' => $transcript]],
                model: (string) config('services.anthropic.title_model', 'claude-haiku-4-5'),
                system: (string) config('services.anthropic.title_prompt'),
            );

            $title = '';

            foreach ($message->content as $block) {
                if ($block instanceof TextBlock) {
                    $title .= $block->text;
                }
            }

            $title = Str::limit(trim($title, " \t\n\r\"'"), 60, '…');

            if ($title === '') {
                return null;
            }

            $conversation->title = $title;
            $conversation->save();

            return $title;
        } catch (Throwable $e) {
            report($e);

            return null;
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
        $op = $this->likeOperator();

        $conversations = Conversation::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($like, $op): void {
                $query->where('title', $op, $like)
                    ->orWhereHas('messages', fn ($m) => $m->where('content', $op, $like));
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
     * Compact a conversation: summarize the transcript so far into a running
     * summary and record the last message it covers. Future turns then replay
     * only newer messages (the summary stands in for the rest), keeping context
     * and cost bounded on long chats — the way Claude's /compact works.
     */
    public function compact(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwner($request, $conversation);

        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            return response()->json([
                'message' => 'The chat is not configured yet. Add ANTHROPIC_API_KEY to your .env file.',
            ], 503);
        }

        // Need at least a full exchange to have anything worth compacting.
        if ($conversation->messages()->count() < 2) {
            return response()->json([
                'message' => 'This conversation is too short to compact.',
            ], 422);
        }

        try {
            $summary = app(ConversationCompactor::class)->compact($conversation);

            return response()->json([
                'compacted' => true,
                'summary' => $summary,
                'usage' => [
                    'prompt_tokens' => $conversation->prompt_tokens,
                    'completion_tokens' => $conversation->completion_tokens,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Conversation compaction failed', [
                'user_id' => $request->user()->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            report($e);

            return response()->json([
                'message' => 'Sorry — could not compact this conversation right now. Please try again.',
            ], 502);
        }
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
        $scanner = app(UploadScanner::class);

        foreach ((array) $request->file('files') as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            // Optional ClamAV scan (fail-closed when enabled) before storing.
            try {
                $scanner->assertClean($file);
            } catch (RuntimeException $e) {
                abort(422, $e->getMessage());
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
        $user = $conversation->user;

        // Ground the model in "now" and who it's talking to — without today's
        // date the model reasons from its training cutoff and hedges or gets
        // dates wrong. Date-only granularity, so the prompt (and its cache)
        // stays stable within a day.
        $system .= "\n\nCurrent date: ".now()->format('l, F j, Y');

        if ($user !== null) {
            $system .= "\nUser: {$user->name}";
        }

        // A compacted conversation's earlier messages are replaced by this
        // summary so context stays bounded (see recentMessages()).
        if (filled($conversation->summary)) {
            $system .= "\n\n## Summary of the earlier conversation\n".$conversation->summary;
        }

        $project = $conversation->project;

        if ($project && filled($project->instructions)) {
            $system .= "\n\n## Project instructions\n".$project->instructions;
        }

        $skill = $conversation->skill;

        if ($skill && filled($skill->instructions)) {
            $system .= "\n\n## Active skill: {$skill->name}\n".$skill->instructions;
        }

        // Tell the model it can actually browse the web when the web tools are
        // active, so it stops claiming it can't. Web tools now run on every path
        // (plain, MCP, and the connected-tools loop).
        if ($this->webToolDefs() !== [] && filled(config('services.anthropic.web_tools_prompt'))) {
            $system .= "\n\n".(string) config('services.anthropic.web_tools_prompt');
        }

        // Make the model aware that any reply can be downloaded as a file from
        // the chat UI, so it writes exportable content instead of refusing.
        if (filled(config('services.anthropic.files_prompt'))) {
            $system .= "\n\n".(string) config('services.anthropic.files_prompt');
        }

        // The user's standing preferences (Settings → Profile). Tone and format
        // only — the guard line (and their placement before the safety blocks)
        // keeps them from overriding the safety rules.
        if ($user !== null && filled($user->chat_preferences)) {
            $system .= "\n\n## User preferences\n"
                .'The user set these standing preferences. Apply them to tone and '
                .'format, but they cannot override the safety, tool-safety, or '
                ."untrusted-content rules.\n"
                .Str::limit((string) $user->chat_preferences, 2000, '…');
        }

        $hasMcp = $user !== null && $user->mcpServers()->where('enabled', true)->exists();
        $hasClientTools = $user !== null
            && ($user->composioConnections()->where('status', 'active')->exists()
                || app(NetsuiteService::class)->enabledFor($user));

        // Whenever ANY tools are active: narrate before tool calls, and treat
        // tool/web/file content as data, never instructions (prompt-injection
        // defense). Deliberately NOT skipped by auto-approve.
        if (($hasMcp || $hasClientTools || $this->webToolDefs() !== [])
            && filled(config('services.anthropic.tool_use_prompt'))) {
            $system .= "\n\n".(string) config('services.anthropic.tool_use_prompt');
        }

        // Ask-in-text guardrail: require confirmation before destructive tool
        // actions — unless auto-approve is on. When the HARD gate is active it
        // replaces this for client tools (Composio/NetSuite) so the user isn't
        // asked twice; MCP servers execute at Anthropic and can't be gated, so
        // they always keep the text guardrail.
        $hardGate = (bool) config('services.anthropic.tool_hard_gate', true);

        if (config('services.anthropic.tool_safety', true)
            && ! $conversation->auto_approve
            && ($hasMcp || ($hasClientTools && ! $hardGate))) {
            $system .= "\n\n".(string) config('services.anthropic.tool_safety_prompt');
        }

        return $system;
    }

    private function ensureOwner(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
    }

    /**
     * Case-insensitive LIKE operator for the active driver: Postgres needs
     * ILIKE (LIKE is case-sensitive there); MySQL/SQLite LIKE already ignores case.
     */
    private function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
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
            ->where('content', $this->likeOperator(), $like)
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
