<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\FeedbackEntry;
use App\Models\Message;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\ModelCatalog;
use App\Services\TokenBudget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, TokenBudget $budget, AppSettings $settings): Response
    {
        return Inertia::render('Dashboard', [
            'usage' => $budget->snapshot($request->user()),
            // Org-wide insights — super admin only; everyone else gets no card.
            'feedback' => $request->user()->isSuperAdmin()
                ? $this->feedbackSummary()
                : null,
            'teamUsage' => $request->user()->isSuperAdmin()
                ? $this->teamUsage($budget, $settings)
                : null,
            'costEfficiency' => $request->user()->isSuperAdmin()
                ? $this->costEfficiency()
                : null,
        ]);
    }

    /**
     * Estimated API spend by model plus prompt-cache efficiency, aggregated
     * over every stored conversation (super admin card). Prices come from
     * config('services.llm_pricing') — estimates, not invoices.
     *
     * @return array{models: array<int, array{model: string, label: string, provider: string, input_tokens: int, output_tokens: int, cost: float}>, total_usd: float, cache: array{hit_rate: float|null, read_tokens: int, write_tokens: int, uncached_tokens: int, saved_usd: float}}
     */
    private function costEfficiency(): array
    {
        $rows = Conversation::query()
            ->selectRaw('model')
            ->selectRaw('SUM(prompt_tokens) AS input_sum')
            ->selectRaw('SUM(completion_tokens) AS output_sum')
            ->selectRaw('SUM(cache_read_tokens) AS cache_read_sum')
            ->selectRaw('SUM(cache_write_tokens) AS cache_write_sum')
            ->groupBy('model')
            ->get();

        $prices = Config::array('services.llm_pricing.models');
        [$defIn, $defOut] = array_pad(Config::array('services.llm_pricing.default'), 2, 3.0);
        $readX = (float) config('services.llm_pricing.cache_read_multiplier', 0.1);
        $writeX = (float) config('services.llm_pricing.cache_write_multiplier', 1.25);

        // Model id → display label + provider name, from the picker catalog.
        $catalog = [];

        foreach (app(ModelCatalog::class)->providers() as $provider) {
            foreach ($provider['models'] as $m) {
                $catalog[$m['value']] = ['label' => $m['label'], 'provider' => $provider['name']];
            }
        }

        $models = [];
        $total = 0.0;
        $cacheRead = 0;
        $cacheWrite = 0;
        $cacheableInput = 0;
        $saved = 0.0;

        foreach ($rows as $row) {
            $model = (string) $row->getAttribute('model');
            $input = (int) $row->getAttribute('input_sum');
            $output = (int) $row->getAttribute('output_sum');
            $read = (int) $row->getAttribute('cache_read_sum');
            $write = (int) $row->getAttribute('cache_write_sum');

            [$inPrice, $outPrice] = array_pad((array) ($prices[$model] ?? [$defIn, $defOut]), 2, (float) $defOut);

            $cost = ($input * $inPrice
                + $read * $inPrice * $readX
                + $write * $inPrice * $writeX
                + $output * $outPrice) / 1_000_000;

            $total += $cost;

            // Cache efficiency is a Claude-only concept here (other providers
            // don't report cache usage), so only Claude rows feed the rate.
            if (str_starts_with($model, 'claude')) {
                $cacheRead += $read;
                $cacheWrite += $write;
                $cacheableInput += $input;
                // What the cached-read tokens would have cost at full price.
                $saved += $read * $inPrice * (1 - $readX) / 1_000_000;
            }

            $models[] = [
                'model' => $model,
                'label' => (string) ($catalog[$model]['label'] ?? $model),
                'provider' => (string) ($catalog[$model]['provider'] ?? 'Other'),
                'input_tokens' => $input + $read + $write,
                'output_tokens' => $output,
                'cost' => round($cost, 2),
            ];
        }

        usort($models, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        $denominator = $cacheRead + $cacheWrite + $cacheableInput;

        return [
            'models' => $models,
            'total_usd' => round($total, 2),
            'cache' => [
                'hit_rate' => $denominator > 0 ? round($cacheRead / $denominator, 4) : null,
                'read_tokens' => $cacheRead,
                'write_tokens' => $cacheWrite,
                'uncached_tokens' => $cacheableInput,
                'saved_usd' => round($saved, 2),
            ],
        ];
    }

    /**
     * Every user's token usage in their current window + the org total, and
     * the currently effective limit settings (super admin card).
     *
     * @return array{users: array<int, array{id: int, name: string, role: string, used: int, percent: float, resets_at: string|null}>, total: int, limit: int, period_days: int, models: array<int, array{value: string, label: string}>, default_model: string|null, env_default_model: string}
     */
    private function teamUsage(TokenBudget $budget, AppSettings $settings): array
    {
        $users = User::query()->orderBy('name')->get();

        $rows = [];
        $total = 0;
        $limit = 0;
        $periodDays = 30;

        foreach ($users as $user) {
            $snapshot = $budget->snapshot($user);
            $total += $snapshot['used'];
            $limit = $snapshot['limit'];
            $periodDays = $snapshot['period_days'];

            $rows[] = [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'used' => $snapshot['used'],
                'percent' => $snapshot['percent'],
                'resets_at' => $snapshot['resets_at'],
            ];
        }

        // Heaviest users first — that's what the card is for.
        usort($rows, fn (array $a, array $b): int => $b['used'] <=> $a['used']);

        $models = [];

        foreach (Config::array('services.anthropic.models') as $value => $label) {
            $models[] = ['value' => $value, 'label' => $label];
        }

        $workspaceModel = $settings->get('chat.default_model');

        return [
            'users' => $rows,
            'total' => $total,
            'limit' => $limit,
            'period_days' => $periodDays,
            // Workspace default model: the stored override (null = none) and
            // the .env fallback shown as the "default" option's hint.
            'models' => $models,
            'default_model' => in_array($workspaceModel, array_column($models, 'value'), true) ? $workspaceModel : null,
            'env_default_model' => (string) config('services.anthropic.model'),
        ];
    }

    /**
     * Update the org-wide usage settings (super admin only). Stored as
     * app_settings overrides; clearing a field falls back to the .env value.
     */
    public function updateUsageSettings(Request $request, AppSettings $settings): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            // 0 = unlimited (tracked, never blocks) — matches USAGE_TOKEN_LIMIT.
            'token_limit' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'period_days' => ['required', 'integer', 'min:1', 'max:365'],
            // 'default' (or absent) clears the override → .env ANTHROPIC_MODEL.
            'default_model' => ['nullable', 'string', Rule::in([...array_keys(Config::array('services.anthropic.models')), 'default'])],
        ]);

        $settings->set('usage.token_limit', (string) $validated['token_limit']);
        $settings->set('usage.period_days', (string) $validated['period_days']);

        $model = $validated['default_model'] ?? 'default';
        $settings->set('chat.default_model', $model === 'default' ? null : $model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Usage settings updated.')]);

        return to_route('dashboard');
    }

    /**
     * Store a written feedback/suggestion entry from the dashboard card
     * (any member). Shown to the super admin on their feedback card.
     */
    public function storeFeedback(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // api_request = "please enable this LLM provider" from the chat
            // model picker's locked entries.
            'type' => ['required', 'string', Rule::in(['feedback', 'suggestion', 'api_request'])],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $request->user()->feedbackEntries()->create([
            'type' => $validated['type'],
            'message' => trim($validated['message']),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Thanks — your feedback was sent.')]);

        // back(), not the dashboard: requests also come from the chat page.
        return back();
    }

    /**
     * Thumbs up/down left on AiMe's answers across the whole team — the point
     * of collecting them is spotting where answers go wrong — plus the written
     * feedback & suggestions submitted from the dashboard card.
     *
     * @return array{up: int, down: int, recent: array<int, array{id: int, rating: string, excerpt: string, conversation_id: int, conversation: string|null, user: string|null, when: string|null}>, entries: array<int, array{id: int, type: string, message: string, user: string|null, when: string|null}>}
     */
    private function feedbackSummary(): array
    {
        $query = Message::query()
            ->whereNotNull('feedback')
            ->where('role', 'assistant');

        $recent = (clone $query)
            ->with(['conversation:id,title,user_id', 'conversation.user:id,name'])
            ->latest('updated_at')
            ->limit((int) config('dashboard.feedback_limit', 8))
            ->get(['id', 'conversation_id', 'content', 'feedback', 'updated_at'])
            ->map(fn (Message $m): array => [
                'id' => $m->id,
                'rating' => $m->feedback === 1 ? 'up' : 'down',
                'excerpt' => Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($m->content))), 140),
                'conversation_id' => $m->conversation_id,
                'conversation' => $m->conversation?->title,
                'user' => $m->conversation?->user?->name,
                'when' => $m->updated_at?->diffForHumans(),
            ])
            ->all();

        $entries = FeedbackEntry::query()
            ->with('user:id,name')
            ->latest()
            ->limit((int) config('dashboard.feedback_limit', 8))
            ->get()
            ->map(fn (FeedbackEntry $e): array => [
                'id' => $e->id,
                'type' => $e->type,
                'message' => $e->message,
                'user' => $e->user?->name,
                'when' => $e->created_at?->diffForHumans(),
            ])
            ->all();

        return [
            'up' => (clone $query)->where('feedback', 1)->count(),
            'down' => (clone $query)->where('feedback', -1)->count(),
            'recent' => $recent,
            'entries' => $entries,
        ];
    }
}
