<?php

namespace App\Http\Controllers;

use App\Models\FeedbackEntry;
use App\Models\Message;
use App\Services\TokenBudget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, TokenBudget $budget): Response
    {
        return Inertia::render('Dashboard', [
            'usage' => $budget->snapshot($request->user()),
            // Org-wide insights — super admin only; everyone else gets no card.
            'feedback' => $request->user()->isSuperAdmin()
                ? $this->feedbackSummary()
                : null,
        ]);
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
