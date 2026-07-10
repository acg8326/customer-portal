<?php

namespace App\Http\Controllers;

use App\Services\ComposioService;
use Illuminate\Http\JsonResponse;
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
        $cfg = $this->composio->toolkit($toolkit);

        if (! $this->composio->enabled() || $cfg === null) {
            return back()->with('error', 'That Composio app is not configured.');
        }

        // Credentials toolkits (e.g. NetSuite) carry secrets, so they connect via
        // the POST endpoint below, not this one-click redirect.
        if ($cfg['mode'] === 'credentials') {
            return back()->with('error', $cfg['name'].' needs its credentials — use the Connect dialog.');
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
     * Connect a "bring-your-own OAuth app" toolkit (e.g. NetSuite): the user
     * submits their client id/secret + any initiation fields; we create the
     * auth config and start the link, returning the consent URL as JSON so the
     * browser can navigate to it (a full redirect can't carry the secrets).
     */
    public function connectWithCredentials(Request $request, string $toolkit): JsonResponse
    {
        $cfg = $this->composio->toolkit($toolkit);

        if (! $this->composio->enabled() || $cfg === null || $cfg['mode'] !== 'credentials') {
            return response()->json(['message' => 'That Composio app is not configured.'], 422);
        }

        // Required secret credentials (client id/secret).
        $credentials = [];
        foreach ($cfg['credentials'] as $field => $label) {
            $value = trim((string) $request->input($field, ''));

            if ($value === '') {
                return response()->json(['message' => $label.' is required.'], 422);
            }

            $credentials[$field] = $value;
        }

        // Required non-secret initiation fields (e.g. account id / subdomain).
        // Free-form identifiers, so validate conservatively.
        $connectionData = [];
        foreach ($cfg['initiation'] as $field => $label) {
            $value = trim((string) $request->input($field, ''));

            if ($value === '' || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value)) {
                return response()->json(['message' => $label.' is required.'], 422);
            }

            $connectionData[$field] = $value;
        }

        // Optional scopes the user opted into — only those we actually offer.
        $requested = (array) $request->input('scopes', []);
        $extraScopes = array_values(array_intersect(
            array_keys($cfg['optional_scopes']),
            array_map('strval', $requested),
        ));

        try {
            $redirect = $this->composio->initiateWithCredentials(
                $request->user(),
                $toolkit,
                route('integrations.composio.callback', ['toolkit' => $toolkit]),
                $credentials['client_id'] ?? '',
                $credentials['client_secret'] ?? '',
                $connectionData,
                $extraScopes,
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not start the connection. Double-check the Client ID/Secret and Account ID.',
            ], 502);
        }

        return response()->json(['redirect_url' => $redirect]);
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
