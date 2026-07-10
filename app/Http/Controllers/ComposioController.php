<?php

namespace App\Http\Controllers;

use App\Services\ComposioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ComposioController extends Controller
{
    public function __construct(private ComposioService $composio) {}

    /**
     * Start a per-user Composio connection: redirect the user to the provider's
     * consent screen (Composio brokers the OAuth app).
     */
    public function connect(Request $request, string $toolkit): RedirectResponse
    {
        if (! $this->composio->enabled() || $this->composio->toolkit($toolkit) === null) {
            return back()->with('error', 'That Composio app is not configured.');
        }

        try {
            $redirect = $this->composio->initiateLink(
                $request->user(),
                $toolkit,
                route('integrations.composio.callback', ['toolkit' => $toolkit]),
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not start the connection. Check the Composio API key and app setup.');
        }

        return redirect()->away($redirect);
    }

    /**
     * Composio redirects the user back here once consent completes. Verify with
     * Composio that the grant is actually ACTIVE before marking it connected —
     * a returned redirect alone doesn't guarantee the account was authorized.
     */
    public function callback(Request $request, string $toolkit): RedirectResponse
    {
        if ($this->composio->toolkit($toolkit) === null) {
            return redirect()->route('integrations');
        }

        $status = $this->composio->remoteStatus($request->user(), $toolkit);

        if ($status === 'ACTIVE') {
            $this->composio->markActive($request->user(), $toolkit);

            return redirect()->route('integrations')
                ->with('success', ucfirst($toolkit).' connected.');
        }

        return redirect()->route('integrations')
            ->with('error', ucfirst($toolkit).' authorization didn\'t complete'
                .($status ? " (status: {$status})" : '').'. Please try Connect again.');
    }

    public function disconnect(Request $request, string $toolkit): RedirectResponse
    {
        $this->composio->disconnect($request->user(), $toolkit);

        return back()->with('success', ucfirst($toolkit).' disconnected.');
    }
}
