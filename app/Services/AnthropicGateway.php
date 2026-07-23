<?php

namespace App\Services;

use App\Http\Controllers\ChatController;
use App\Models\RequestLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The LLM gateway: forwards Anthropic Messages requests from a developer's
 * Claude Code to the real API, injecting the central key, forcing the user's
 * assigned model, and recording token usage against their budget. A transparent
 * proxy — it only touches auth, the model field, and usage accounting.
 */
class AnthropicGateway
{
    public function __construct(private readonly TokenBudget $budget) {}

    /**
     * Proxy POST /v1/messages. Streams the response through untouched when the
     * client asked to stream; otherwise buffers and returns JSON. Either way,
     * usage is recorded against the user's budget.
     *
     * @param  array<string, string>  $forwardHeaders
     */
    public function messages(User $user, string $rawBody, array $forwardHeaders, bool $wantsStream): Response
    {
        $startedAt = microtime(true);
        $body = $this->pinModel($user, $rawBody);
        $model = $this->modelFromBody($body);
        $url = $this->url('/v1/messages');
        $headers = $this->headers($forwardHeaders);

        if (! $wantsStream) {
            $response = Http::withHeaders($headers)->withBody($body, 'application/json')->post($url);
            AnthropicRateLimits::capture($response);

            $decoded = $response->json();

            if (is_array($decoded)) {
                $this->budget->record($user, $this->usageFromBody($decoded));
            }

            $this->logRequest($user, 'gateway', $model, $decoded, $response->status(), $startedAt);

            // Relay the upstream bytes verbatim — re-encoding a decoded array
            // would mangle any empty JSON objects in the response.
            return response($response->body(), $response->status())
                ->header('Content-Type', $response->header('Content-Type') ?: 'application/json');
        }

        $response = Http::withHeaders($headers)
            ->withOptions(['stream' => true])
            ->withBody($body, 'application/json')
            ->post($url);

        AnthropicRateLimits::capture($response);

        $contentType = (string) $response->header('Content-Type');

        // An error (or any non-SSE reply) to a streaming request comes back as
        // a normal body — pass it straight through so the client sees it.
        if (! str_contains($contentType, 'text/event-stream')) {
            $this->logRequest($user, 'gateway', $model, null, $response->status(), $startedAt);

            return response($response->body(), $response->status())
                ->header('Content-Type', $contentType ?: 'application/json');
        }

        return $this->streamThrough($user, $response->toPsrResponse()->getBody(), $response->status(), $model, $startedAt);
    }

    /**
     * Proxy POST /v1/messages/count_tokens (a metadata call — not budgeted).
     *
     * @param  array<string, string>  $forwardHeaders
     */
    public function countTokens(User $user, string $rawBody, array $forwardHeaders): Response
    {
        $body = $this->pinModel($user, $rawBody);
        $response = Http::withHeaders($this->headers($forwardHeaders))
            ->withBody($body, 'application/json')
            ->post($this->url('/v1/messages/count_tokens'));

        AnthropicRateLimits::capture($response);

        return response($response->body(), $response->status())
            ->header('Content-Type', $response->header('Content-Type') ?: 'application/json');
    }

    /**
     * Stream the upstream SSE body through to the client verbatim, tallying
     * usage as it passes and recording it once the stream ends.
     */
    private function streamThrough(User $user, StreamInterface $upstream, int $status, ?string $model, float $startedAt): StreamedResponse
    {
        return response()->stream(function () use ($user, $upstream, $status, $model, $startedAt): void {
            $parser = new SseUsageParser;

            while (! $upstream->eof()) {
                $chunk = $upstream->read(8192);

                if ($chunk === '') {
                    continue;
                }

                echo $chunk;
                $parser->push($chunk);

                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
            }

            $this->budget->record($user, $parser->total());
            $this->logRequest($user, 'gateway', $model, [
                'usage' => ['input_tokens' => $parser->inputTokens(), 'output_tokens' => $parser->outputTokens()],
            ], $status, $startedAt);
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Force the user's assigned model when one is pinned (governance); leave
     * the requested model otherwise.
     *
     * Decodes as objects (not associative arrays) so empty JSON objects — like
     * a no-parameter tool's "input_schema": {"properties": {}} — survive the
     * round trip as {} rather than being flattened to []. Only the top-level
     * "model" field is touched; everything else is re-emitted unchanged.
     */
    private function pinModel(User $user, string $rawBody): string
    {
        $payload = json_decode($rawBody, false);

        if (! $payload instanceof \stdClass) {
            return $rawBody;
        }

        $requested = (string) ($payload->model ?? config('services.anthropic.model'));
        $payload->model = ChatController::effectiveModel($user, $requested);

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $rawBody;
    }

    /**
     * The effective model from an already-pinned request body, for logging.
     */
    private function modelFromBody(string $body): ?string
    {
        $payload = json_decode($body, true);

        return is_array($payload) ? ($payload['model'] ?? null) : null;
    }

    /**
     * Record one gateway request to the Logs table (Analytics → Logs). A
     * no-op when disabled via config('services.anthropic.request_log_enabled').
     *
     * @param  array<string, mixed>|null  $decodedBody
     */
    private function logRequest(User $user, string $surface, ?string $model, ?array $decodedBody, int $status, float $startedAt): void
    {
        if (! config('services.anthropic.request_log_enabled', true)) {
            return;
        }

        $usage = is_array($decodedBody) ? ($decodedBody['usage'] ?? []) : [];

        RequestLog::create([
            'user_id' => $user->id,
            'surface' => $surface,
            'model' => $model,
            'input_tokens' => is_array($usage) ? ($usage['input_tokens'] ?? null) : null,
            'output_tokens' => is_array($usage) ? ($usage['output_tokens'] ?? null) : null,
            'status' => $status,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * Total billed tokens from a non-streamed response body.
     *
     * @param  array<string, mixed>  $body
     */
    private function usageFromBody(array $body): int
    {
        $usage = $body['usage'] ?? [];

        if (! is_array($usage)) {
            return 0;
        }

        return (int) ($usage['input_tokens'] ?? 0)
            + (int) ($usage['cache_creation_input_tokens'] ?? 0)
            + (int) ($usage['cache_read_input_tokens'] ?? 0)
            + (int) ($usage['output_tokens'] ?? 0);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.anthropic.base_url', 'https://api.anthropic.com'), '/').$path;
    }

    /**
     * Upstream headers: our central key + the client's version/beta headers.
     *
     * @param  array<string, string>  $forwardHeaders
     * @return array<string, string>
     */
    private function headers(array $forwardHeaders): array
    {
        return array_merge($forwardHeaders, [
            'x-api-key' => (string) config('services.anthropic.key'),
            'content-type' => 'application/json',
        ]);
    }
}
