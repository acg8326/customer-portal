<?php

namespace App\Http\Controllers;

use App\Models\NetsuiteConnection;
use App\Services\NetsuiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NetsuiteController extends Controller
{
    private const OAUTH_STATE_KEY = 'netsuite_oauth_state';

    /** Which connection row the in-flight OAuth consent belongs to. */
    private const OAUTH_CONNECTION_KEY = 'netsuite_oauth_connection_id';

    public function __construct(private readonly NetsuiteService $netsuite) {}

    /**
     * Connect a NetSuite account. A user can hold several connections (one per
     * NetSuite account id); reconnecting an account updates it in place.
     * `auth_type` selects the method:
     *  - 'tba'    → store the four TBA secrets + verify them (returns a result).
     *  - 'oauth2' → store the OAuth app id/secret and return the consent URL to
     *               redirect the browser to; the callback finishes the link.
     */
    public function connect(Request $request): JsonResponse
    {
        abort_unless($this->netsuite->enabled(), 404);

        $authType = (string) $request->input('auth_type', NetsuiteConnection::AUTH_TBA);

        $request->validate([
            'auth_type' => ['required', Rule::in([NetsuiteConnection::AUTH_TBA, NetsuiteConnection::AUTH_OAUTH2])],
            'account_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            'label' => ['nullable', 'string', 'max:60'],
        ], [
            'account_id.regex' => 'The Account ID looks wrong — use your NetSuite account id, e.g. 1234567 or 1234567_SB1.',
        ]);

        if ($authType === NetsuiteConnection::AUTH_OAUTH2) {
            return $this->connectOauth($request);
        }

        $validated = $request->validate([
            'consumer_key' => ['required', 'string', 'max:255'],
            'consumer_secret' => ['required', 'string', 'max:255'],
            'token_id' => ['required', 'string', 'max:255'],
            'token_secret' => ['required', 'string', 'max:255'],
        ]);
        $validated['account_id'] = (string) $request->input('account_id');
        $validated['label'] = $request->input('label');

        $conn = $this->netsuite->store($request->user(), $validated);
        $result = $this->netsuite->test($conn);

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'connected' => $conn->fresh()?->isActive() ?? false,
        ], $result['ok'] ? 200 : 422);
    }

    /**
     * Store the OAuth 2.0 app credentials and return the consent URL.
     */
    private function connectOauth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
        ]);
        $validated['account_id'] = (string) $request->input('account_id');
        $validated['label'] = $request->input('label');

        $state = Str::random(40);

        [$conn, $url] = $this->netsuite->beginOauth($request->user(), $validated, $state);

        // The callback needs to know which of the user's connections this
        // consent round belongs to — several accounts may exist by now.
        $request->session()->put(self::OAUTH_STATE_KEY, $state);
        $request->session()->put(self::OAUTH_CONNECTION_KEY, $conn->id);

        return response()->json(['redirect_url' => $url]);
    }

    /**
     * OAuth 2.0 redirect target: exchange the code for tokens, then return to
     * the Integrations page with a flash message.
     */
    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->netsuite->enabled(), 404);

        $connectionId = (int) $request->session()->pull(self::OAUTH_CONNECTION_KEY, 0);
        $conn = $connectionId > 0
            ? $this->netsuite->connectionById($request->user(), $connectionId)
            : null;
        $expectedState = $request->session()->pull(self::OAUTH_STATE_KEY);

        if ($request->filled('error')) {
            return redirect('/integrations')->with('error', 'NetSuite authorization was cancelled or denied.');
        }

        if ($conn === null
            || ! $conn->isOauth2()
            || $expectedState === null
            || ! hash_equals((string) $expectedState, (string) $request->query('state'))
            || ! $request->filled('code')) {
            return redirect('/integrations')->with('error', 'NetSuite authorization failed (invalid or expired request). Please try connecting again.');
        }

        $result = $this->netsuite->exchangeCode($conn, (string) $request->query('code'));

        return redirect('/integrations')->with(
            $result['ok'] ? 'success' : 'error',
            $result['message'],
        );
    }

    /**
     * Re-test a stored connection (button on the connected row).
     */
    public function test(Request $request): JsonResponse
    {
        abort_unless($this->netsuite->enabled(), 404);

        $conn = $this->resolveConnection($request);

        if ($conn === null) {
            return response()->json(['ok' => false, 'message' => 'Connect NetSuite first.'], 422);
        }

        $result = $this->netsuite->test($conn);

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'connected' => $conn->fresh()?->isActive() ?? false,
        ], $result['ok'] ? 200 : 422);
    }

    /**
     * Make one of the user's connections the default account for new chats.
     */
    public function setDefault(Request $request, NetsuiteConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === $request->user()->id, 403);

        $this->netsuite->setDefault($request->user(), $connection);

        return back()->with('success', '"'.$connection->displayLabel().'" is now your default NetSuite account.');
    }

    /**
     * Remove one stored NetSuite connection.
     */
    public function disconnect(Request $request, NetsuiteConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === $request->user()->id, 403);

        $this->netsuite->disconnect($request->user(), $connection);

        return back()->with('success', 'NetSuite account "'.$connection->displayLabel().'" disconnected.');
    }

    /**
     * The connection a JSON action targets: the given connection_id (must be
     * the user's own), else their default account.
     */
    private function resolveConnection(Request $request): ?NetsuiteConnection
    {
        $id = $request->integer('connection_id');

        return $id > 0
            ? $this->netsuite->connectionById($request->user(), $id)
            : $this->netsuite->defaultConnectionFor($request->user());
    }
}
