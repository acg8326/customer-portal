<?php

namespace App\Services;

use App\Http\Controllers\ChatController;
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $forwardHeaders
     */
    public function messages(User $user, array $payload, array $forwardHeaders, bool $wantsStream): Response
    {
        $payload = $this->pinModel($user, $payload);
        $url = $this->url('/v1/messages');
        $headers = $this->headers($forwardHeaders);

        if (! $wantsStream) {
            $response = Http::withHeaders($headers)->post($url, $payload);
            $body = $response->json();

            if (is_array($body)) {
                $this->budget->record($user, $this->usageFromBody($body));
            }

            return response()->json(is_array($body) ? $body : [], $response->status());
        }

        $response = Http::withHeaders($headers)
            ->withOptions(['stream' => true])
            ->post($url, $payload);

        $contentType = (string) $response->header('Content-Type');

        // An error (or any non-SSE reply) to a streaming request comes back as
        // a normal body — pass it straight through so the client sees it.
        if (! str_contains($contentType, 'text/event-stream')) {
            return response($response->body(), $response->status())
                ->header('Content-Type', $contentType ?: 'application/json');
        }

        return $this->streamThrough($user, $response->toPsrResponse()->getBody(), $response->status());
    }

    /**
     * Proxy POST /v1/messages/count_tokens (a metadata call — not budgeted).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $forwardHeaders
     */
    public function countTokens(User $user, array $payload, array $forwardHeaders): Response
    {
        $payload = $this->pinModel($user, $payload);
        $response = Http::withHeaders($this->headers($forwardHeaders))
            ->post($this->url('/v1/messages/count_tokens'), $payload);

        $body = $response->json();

        return response()->json(is_array($body) ? $body : [], $response->status());
    }

    /**
     * Stream the upstream SSE body through to the client verbatim, tallying
     * usage as it passes and recording it once the stream ends.
     */
    private function streamThrough(User $user, StreamInterface $upstream, int $status): StreamedResponse
    {
        return response()->stream(function () use ($user, $upstream): void {
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function pinModel(User $user, array $payload): array
    {
        $requested = (string) ($payload['model'] ?? config('services.anthropic.model'));
        $payload['model'] = ChatController::effectiveModel($user, $requested);

        return $payload;
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
