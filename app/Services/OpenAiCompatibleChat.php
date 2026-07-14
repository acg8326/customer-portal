<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal streaming client for OpenAI-compatible chat-completions APIs
 * (OpenAI, Gemini's compat endpoint, DeepSeek, Groq, Mistral, xAI). Used for
 * the non-Claude models in the picker: plain text chat only — no tools, no
 * attachments, no thinking blocks.
 */
class OpenAiCompatibleChat
{
    /**
     * Stream one completion; $onDelta receives each text fragment as it
     * arrives. Returns [reply, inputTokens, outputTokens] — token counts come
     * from the final usage chunk (stream_options.include_usage) and are 0 if
     * the provider doesn't send them.
     *
     * @param  array<string, mixed>  $provider  services.llm_providers entry
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{0: string, 1: int, 2: int}
     */
    public function stream(array $provider, string $model, string $system, array $messages, int $maxTokens, callable $onDelta): array
    {
        $payload = [
            'model' => $model,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ...$messages,
            ],
            (string) ($provider['max_tokens_param'] ?? 'max_tokens') => $maxTokens,
        ];

        $response = Http::withToken((string) $provider['key'])
            ->withOptions(['stream' => true])
            ->timeout(180)
            ->post(rtrim((string) $provider['base_url'], '/').'/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Provider error ('.$response->status().'): '.mb_substr((string) $response->body(), 0, 300));
        }

        $body = $response->toPsrResponse()->getBody();

        $reply = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            // SSE frames are newline-delimited; keep the trailing partial line.
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '' || $data === '[DONE]') {
                    continue;
                }

                $chunk = json_decode($data, true);

                if (! is_array($chunk)) {
                    continue;
                }

                $delta = $chunk['choices'][0]['delta']['content'] ?? null;

                if (is_string($delta) && $delta !== '') {
                    $reply .= $delta;
                    $onDelta($delta);
                }

                if (isset($chunk['usage']) && is_array($chunk['usage'])) {
                    $inputTokens = (int) ($chunk['usage']['prompt_tokens'] ?? 0);
                    $outputTokens = (int) ($chunk['usage']['completion_tokens'] ?? 0);
                }
            }
        }

        return [trim($reply), $inputTokens, $outputTokens];
    }
}
