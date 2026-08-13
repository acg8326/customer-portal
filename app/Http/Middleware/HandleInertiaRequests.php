<?php

namespace App\Http\Middleware;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * How many chats the sidebar carries. Enough to cover "the chat I was just
     * in" without turning a shared prop into an unbounded list on every page;
     * older chats are reachable through search (⌘K).
     */
    private const SIDEBAR_CHAT_LIMIT = 30;

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Chat history for the app sidebar (claude.ai-style: one rail, always
            // there, rather than a second column beside the chat). Shared rather
            // than page-scoped so the sidebar keeps its shape between pages —
            // a nav that gains and loses a section as you navigate reads as a
            // glitch. Kept cheap: three indexed columns, newest first, capped.
            // The chat page refreshes it with a partial reload of this key alone
            // when a chat is created, renamed, starred or deleted.
            'recentChats' => fn (): array => $request->user()
                ? $request->user()->conversations()
                    ->whereNull('project_id')   // project chats belong to their project
                    ->orderByDesc('starred')
                    ->latest('updated_at')
                    ->limit(self::SIDEBAR_CHAT_LIMIT)
                    ->get(['id', 'title', 'starred'])
                    ->map(fn (Conversation $c): array => [
                        'id' => $c->id,
                        'title' => $c->title,
                        'starred' => $c->starred,
                    ])
                    ->all()
                : [],
            // Whether the LLM gateway is on — drives the Developer access nav item.
            'gatewayEnabled' => (bool) config('services.anthropic.gateway.enabled', false),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                // A freshly-issued gateway token, shown once on the settings page.
                'gatewayToken' => $request->session()->get('gatewayToken'),
            ],
        ];
    }
}
