<?php

namespace App\Http\Controllers;

use App\Services\AnthropicGateway;
use App\Services\TokenBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The developer-facing LLM gateway. Authenticated by GatewayAuth (a personal
 * token → user); this controller applies the user's token budget and hands off
 * to AnthropicGateway, which forces their assigned model and forwards to the
 * real Anthropic API. See config services.anthropic.gateway.
 */
class GatewayController extends Controller
{
    public function __construct(
        private readonly AnthropicGateway $gateway,
        private readonly TokenBudget $budget,
    ) {}

    /**
     * POST /llm/v1/messages
     */
    public function messages(Request $request): Response
    {
        $user = $request->user();

        if ($this->budget->exceeded($user)) {
            return $this->error(
                'rate_limit_error',
                'Your AiMe token budget for this period is used up. It resets automatically — contact your administrator to raise the limit.',
                429,
            );
        }

        // Forward the RAW request body verbatim. Decoding to a PHP associative
        // array and re-encoding would turn empty JSON objects (e.g. a tool's
        // "input_schema": {"properties": {}}) into empty arrays ([]), which the
        // Anthropic API rejects. We only need to peek at "stream" to decide how
        // to relay the response; the model is pinned downstream on an
        // object-decode that preserves {}.
        $raw = $request->getContent();
        $decoded = json_decode($raw, true);
        $wantsStream = is_array($decoded) && (bool) ($decoded['stream'] ?? false);

        return $this->gateway->messages($user, $raw, $this->forwardHeaders($request), $wantsStream);
    }

    /**
     * POST /llm/v1/messages/count_tokens
     */
    public function countTokens(Request $request): Response
    {
        return $this->gateway->countTokens($request->user(), $request->getContent(), $this->forwardHeaders($request));
    }

    /**
     * The Anthropic protocol headers we pass through unchanged (auth is
     * swapped in by the service; we never forward the client's).
     *
     * @return array<string, string>
     */
    private function forwardHeaders(Request $request): array
    {
        $headers = ['anthropic-version' => $request->header('anthropic-version', '2023-06-01')];

        if ($request->hasHeader('anthropic-beta')) {
            $headers['anthropic-beta'] = (string) $request->header('anthropic-beta');
        }

        return $headers;
    }

    private function error(string $type, string $message, int $status): JsonResponse
    {
        return response()->json([
            'type' => 'error',
            'error' => ['type' => $type, 'message' => $message],
        ], $status);
    }
}
