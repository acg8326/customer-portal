<?php

namespace App\Services;

/**
 * Extracts token usage from a streamed Anthropic Messages response as its SSE
 * bytes pass through the gateway, without holding the whole body in memory:
 * feed each chunk to push(); it buffers partial lines and keeps only the
 * latest usage numbers.
 *
 * Usage lands in two event types:
 *  - message_start → the prompt (input) tokens, incl. cache reads/writes
 *  - message_delta → the cumulative output tokens (the last one wins)
 */
class SseUsageParser
{
    private string $buffer = '';

    private int $input = 0;

    private int $output = 0;

    public function push(string $chunk): void
    {
        $this->buffer .= $chunk;

        // Process only complete lines; keep any trailing partial in the buffer.
        while (($nl = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $nl);
            $this->buffer = substr($this->buffer, $nl + 1);
            $this->line(rtrim($line, "\r"));
        }
    }

    private function line(string $line): void
    {
        if (! str_starts_with($line, 'data:')) {
            return;
        }

        $json = trim(substr($line, 5));

        if ($json === '' || $json === '[DONE]') {
            return;
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return;
        }

        $type = $data['type'] ?? null;

        if ($type === 'message_start') {
            $usage = $data['message']['usage'] ?? [];
            $this->input = (int) ($usage['input_tokens'] ?? 0)
                + (int) ($usage['cache_creation_input_tokens'] ?? 0)
                + (int) ($usage['cache_read_input_tokens'] ?? 0);
        } elseif ($type === 'message_delta') {
            // Cumulative — the last value seen is the final output count.
            $this->output = (int) ($data['usage']['output_tokens'] ?? $this->output);
        }
    }

    /**
     * Total billed tokens seen so far (input incl. cache + output).
     */
    public function total(): int
    {
        return $this->input + $this->output;
    }
}
