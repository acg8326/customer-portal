<?php

namespace App\Services;

/**
 * One API round's token usage, kept split by CLASS rather than flattened to a
 * single number — because the four classes don't cost the same.
 *
 * Anthropic's prompt cache bills a read at ~0.1x the input price and a write
 * at ~1.25x, so a turn that re-reads a 100k-token cached prefix costs about
 * what 10k fresh input tokens cost. Charging that prefix at face value against
 * a user's budget punishes exactly the traffic caching is meant to make cheap
 * — coding agents, which re-send a large cached prefix every single turn.
 *
 * `billableTokens()` applies the weights from config('usage.cache_*_weight')
 * so the budget tracks real cost. Both surfaces (in-app chat and the LLM
 * gateway) go through this, so a cached token means the same thing on each.
 */
final class TokenUsage
{
    public function __construct(
        /** Prompt tokens billed at full input price (the uncached remainder). */
        public readonly int $uncachedInput = 0,
        /** Prompt tokens served from the cache (~0.1x). */
        public readonly int $cacheRead = 0,
        /** Prompt tokens written to the cache (~1.25x). */
        public readonly int $cacheWrite = 0,
        public readonly int $output = 0,
    ) {}

    /**
     * Build from an Anthropic `usage` object (a non-streamed response body, or
     * a stream's message_start frame).
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromArray(array $usage): self
    {
        return new self(
            uncachedInput: (int) ($usage['input_tokens'] ?? 0),
            cacheRead: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cacheWrite: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            output: (int) ($usage['output_tokens'] ?? 0),
        );
    }

    /**
     * The whole prompt as the API saw it — uncached + cache reads + writes.
     */
    public function promptTokens(): int
    {
        return $this->uncachedInput + $this->cacheRead + $this->cacheWrite;
    }

    /**
     * Every token moved, unweighted. Useful for raw reporting; NOT what the
     * budget should charge.
     */
    public function rawTotal(): int
    {
        return $this->promptTokens() + $this->output;
    }

    /**
     * What this turn costs the user's budget: cached prompt tokens weighted to
     * their real price, everything else at face value.
     *
     * Set USAGE_CACHE_READ_WEIGHT=0 to make cache reads free (how the in-app
     * chat behaved before this was unified), or 1 to charge them like fresh
     * input.
     */
    public function billableTokens(): int
    {
        $readWeight = (float) config('usage.cache_read_weight', 0.1);
        $writeWeight = (float) config('usage.cache_write_weight', 1.25);

        return (int) round(
            $this->uncachedInput
            + $this->cacheRead * $readWeight
            + $this->cacheWrite * $writeWeight
            + $this->output
        );
    }

    public function plus(self $other): self
    {
        return new self(
            uncachedInput: $this->uncachedInput + $other->uncachedInput,
            cacheRead: $this->cacheRead + $other->cacheRead,
            cacheWrite: $this->cacheWrite + $other->cacheWrite,
            output: $this->output + $other->output,
        );
    }
}
