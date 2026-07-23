<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\RequestLog;
use App\Models\User;
use App\Services\AnthropicRateLimits;
use App\Services\AppSettings;
use App\Services\ModelCatalog;
use App\Services\TokenBudget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Org-wide insights the Dashboard used to carry: per-member token usage,
 * governance (pinned model + token limit), estimated API cost/cache
 * efficiency, Anthropic's own rate-limit gauges, and a per-request log. Super
 * admin only — enforced by the `super_admin` route middleware (see
 * routes/web.php), not a controller-level check.
 */
class AnalyticsController extends Controller
{
    public function index(Request $request, TokenBudget $budget, AppSettings $settings): Response
    {
        return Inertia::render('Analytics', [
            'teamUsage' => $this->teamUsage($budget, $settings),
            'costEfficiency' => $this->costEfficiency(),
            'rateLimits' => AnthropicRateLimits::current(),
            'logs' => $this->logsPage($request),
        ]);
    }

    /**
     * Estimated API spend by model plus prompt-cache efficiency, aggregated
     * over every stored conversation. Prices come from
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
     * Every user's token usage across all three windows (period, session,
     * weekly) + the org total, and the currently effective limit settings.
     *
     * @return array{users: array<int, array{id: int, name: string, role: string, used: int, percent: float, resets_at: string|null, assigned_model: string|null, token_limit: int|null, effective_limit: int, session_token_limit: int|null, session_used: int, session_percent: float, session_resets_at: string|null, effective_session_limit: int, weekly_token_limit: int|null, weekly_used: int, weekly_percent: float, weekly_resets_at: string|null, effective_weekly_limit: int}>, total: int, limit: int, period_days: int, session_limit: int, session_hours: int, weekly_limit: int, weekly_days: int, models: array<int, array{value: string, label: string}>, default_model: string|null, env_default_model: string}
     */
    private function teamUsage(TokenBudget $budget, AppSettings $settings): array
    {
        $users = User::query()->orderBy('name')->get();

        $rows = [];
        $total = 0;
        $limit = 0;
        $periodDays = 30;
        $sessionLimit = 0;
        $sessionHours = 5;
        $weeklyLimit = 0;
        $weeklyDays = 7;

        foreach ($users as $user) {
            $snapshot = $budget->snapshot($user);
            $total += $snapshot['used'];
            $limit = $snapshot['limit'];
            $periodDays = $snapshot['period_days'];
            $sessionLimit = $snapshot['session']['limit'];
            $sessionHours = $snapshot['session']['session_hours'];
            $weeklyLimit = $snapshot['weekly']['limit'];
            $weeklyDays = $snapshot['weekly']['weekly_days'];

            $rows[] = [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'used' => $snapshot['used'],
                'percent' => $snapshot['percent'],
                'resets_at' => $snapshot['resets_at'],
                // Per-user governance: the pinned model (null = free choice)
                // and the user's own cap (null = inherits the workspace limit).
                // effective_limit is what actually applies to this user.
                'assigned_model' => $user->assigned_model,
                'token_limit' => $user->token_limit,
                'effective_limit' => $snapshot['limit'],
                'session_token_limit' => $user->session_token_limit,
                'session_used' => $snapshot['session']['used'],
                'session_percent' => $snapshot['session']['percent'],
                'session_resets_at' => $snapshot['session']['resets_at'],
                'effective_session_limit' => $snapshot['session']['limit'],
                'weekly_token_limit' => $user->weekly_token_limit,
                'weekly_used' => $snapshot['weekly']['used'],
                'weekly_percent' => $snapshot['weekly']['percent'],
                'weekly_resets_at' => $snapshot['weekly']['resets_at'],
                'effective_weekly_limit' => $snapshot['weekly']['limit'],
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
            'session_limit' => $sessionLimit,
            'session_hours' => $sessionHours,
            'weekly_limit' => $weeklyLimit,
            'weekly_days' => $weeklyDays,
            // Workspace default model: the stored override (null = none) and
            // the .env fallback shown as the "default" option's hint.
            'models' => $models,
            'default_model' => in_array($workspaceModel, array_column($models, 'value'), true) ? $workspaceModel : null,
            'env_default_model' => (string) config('services.anthropic.model'),
        ];
    }

    /**
     * Update the org-wide usage settings. Stored as app_settings overrides;
     * clearing a field falls back to the .env value.
     */
    public function updateUsageSettings(Request $request, AppSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            // 0 = unlimited (tracked, never blocks) — matches USAGE_TOKEN_LIMIT.
            'token_limit' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'period_days' => ['required', 'integer', 'min:1', 'max:365'],
            'session_token_limit' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'session_hours' => ['required', 'integer', 'min:1', 'max:744'],
            'weekly_token_limit' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'weekly_days' => ['required', 'integer', 'min:1', 'max:365'],
            // 'default' (or absent) clears the override → .env ANTHROPIC_MODEL.
            'default_model' => ['nullable', 'string', Rule::in([...array_keys(Config::array('services.anthropic.models')), 'default'])],
        ]);

        $settings->set('usage.token_limit', (string) $validated['token_limit']);
        $settings->set('usage.period_days', (string) $validated['period_days']);
        $settings->set('usage.session_token_limit', (string) $validated['session_token_limit']);
        $settings->set('usage.session_hours', (string) $validated['session_hours']);
        $settings->set('usage.weekly_token_limit', (string) $validated['weekly_token_limit']);
        $settings->set('usage.weekly_days', (string) $validated['weekly_days']);

        $model = $validated['default_model'] ?? 'default';
        $settings->set('chat.default_model', $model === 'default' ? null : $model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Usage settings updated.')]);

        return to_route('analytics.index');
    }

    /**
     * Set one user's pinned model and/or personal token limit. Both are
     * per-user overrides: 'default' model or a null limit clears the
     * override so the user falls back to the workspace setting.
     */
    public function updateUserLimits(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            // 'default' (or absent) clears the pin → workspace default model.
            'assigned_model' => ['nullable', 'string', Rule::in([...array_keys(Config::array('services.anthropic.models')), 'default'])],
            // null/absent = inherit the workspace limit; 0 = unlimited for this
            // user; a positive value caps them specifically. Same semantics
            // for all three tiers.
            'token_limit' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'session_token_limit' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'weekly_token_limit' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $model = $validated['assigned_model'] ?? 'default';
        $user->assigned_model = $model === 'default' ? null : $model;
        $user->token_limit = array_key_exists('token_limit', $validated)
            ? $validated['token_limit']
            : null;
        $user->session_token_limit = array_key_exists('session_token_limit', $validated)
            ? $validated['session_token_limit']
            : null;
        $user->weekly_token_limit = array_key_exists('weekly_token_limit', $validated)
            ? $validated['weekly_token_limit']
            : null;
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Updated :name.', ['name' => $user->name])]);

        // back() so this works from wherever the editor is used.
        return back();
    }

    /**
     * A filterable, paginated page of request_logs rows (Analytics → Logs),
     * covering both the in-app chat and the LLM gateway.
     *
     * @return array<string, mixed>
     */
    private function logsPage(Request $request): array
    {
        $query = RequestLog::query()->with('user:id,name')->latest('created_at');

        if ($userId = $request->integer('log_user')) {
            $query->where('user_id', $userId);
        }

        if ($surface = $request->string('log_surface')->toString()) {
            $query->where('surface', $surface);
        }

        if ($status = $request->string('log_status')->toString()) {
            match (true) {
                $status === '2xx' => $query->whereBetween('status', [200, 299]),
                $status === '4xx' => $query->whereBetween('status', [400, 499]),
                $status === '5xx' => $query->whereBetween('status', [500, 599]),
                ctype_digit($status) => $query->where('status', (int) $status),
                default => null,
            };
        }

        if ($from = $request->date('log_from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->date('log_to')) {
            $query->where('created_at', '<=', $to);
        }

        return $query->paginate(25, pageName: 'log_page')
            ->withQueryString()
            ->through(fn (RequestLog $r): array => [
                'id' => $r->id,
                'user' => $r->user?->name,
                'surface' => $r->surface,
                'model' => $r->model,
                'input_tokens' => $r->input_tokens,
                'output_tokens' => $r->output_tokens,
                'status' => $r->status,
                'latency_ms' => $r->latency_ms,
                'when' => $r->created_at?->diffForHumans(),
            ])
            ->toArray();
    }
}
