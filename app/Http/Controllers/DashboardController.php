<?php

namespace App\Http\Controllers;

use App\Models\FeedbackEntry;
use App\Models\Message;
use App\Models\User;
use App\Services\AppSettings;
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
        ]);
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
