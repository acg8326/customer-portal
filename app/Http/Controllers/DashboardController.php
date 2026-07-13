<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\TokenBudget;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, TokenBudget $budget): Response
    {
        return Inertia::render('Dashboard', [
            'usage' => $budget->snapshot($request->user()),
            'stats' => [
                'conversations' => $request->user()->conversations()->count(),
                'projects' => $request->user()->projects()->count(),
                'skills' => $request->user()->skills()->count(),
            ],
            // Org-wide insight — super admin only; everyone else gets no card.
            'feedback' => $request->user()->isSuperAdmin()
                ? $this->feedbackSummary()
                : null,
        ]);
    }

    /**
     * Thumbs up/down left on AiMe's answers across the whole team — the point
     * of collecting them is spotting where answers go wrong.
     *
     * @return array{up: int, down: int, recent: array<int, array{id: int, rating: string, excerpt: string, conversation_id: int, conversation: string|null, user: string|null, when: string|null}>}
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

        return [
            'up' => (clone $query)->where('feedback', 1)->count(),
            'down' => (clone $query)->where('feedback', -1)->count(),
            'recent' => $recent,
        ];
    }
}
