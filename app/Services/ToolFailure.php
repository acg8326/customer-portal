<?php

namespace App\Services;

/**
 * Turns a raw tool error into something a user can act on.
 *
 * A failed tool call used to reach the user only as whatever prose the model
 * chose to write about it — the actual cause (missing OAuth scope, expired
 * token, 404 on a spreadsheet id) was handed to the model as a tool_result and
 * never shown. So "I couldn't retrieve that" was all you got, when the real
 * answer was "Slack is connected without channels:read".
 *
 * This classifies the raw provider message into a kind plus a concrete fix, and
 * keeps the original text so nothing is hidden behind our paraphrase.
 */
final class ToolFailure
{
    public function __construct(
        public readonly string $tool,
        public readonly string $source,
        public readonly string $kind,
        public readonly string $fix,
        public readonly string $detail,
    ) {}

    /**
     * @return array{tool: string, source: string, kind: string, fix: string, detail: string}
     */
    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'source' => $this->source,
            'kind' => $this->kind,
            'fix' => $this->fix,
            'detail' => $this->detail,
        ];
    }

    /**
     * Classify one failure. $raw is the provider's own message, which is the
     * part worth surfacing verbatim — our categories are a convenience on top,
     * never a replacement.
     */
    public static function from(string $tool, string $raw): self
    {
        $source = self::sourceFor($tool);
        $haystack = mb_strtolower($raw);

        foreach ((array) config('services.anthropic.tool_errors.rules', []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            foreach ((array) ($rule['match'] ?? []) as $needle) {
                if ($needle !== '' && str_contains($haystack, mb_strtolower((string) $needle))) {
                    return new self(
                        tool: $tool,
                        source: $source,
                        kind: (string) ($rule['kind'] ?? 'Tool error'),
                        fix: str_replace(':source', $source, (string) ($rule['fix'] ?? '')),
                        detail: self::trim($raw),
                    );
                }
            }
        }

        return new self(
            tool: $tool,
            source: $source,
            kind: 'Tool error',
            fix: "The {$source} call didn't succeed. The provider's own message is below —"
                .' if it points at a setting or an id, fix that and ask again.',
            detail: self::trim($raw),
        );
    }

    /**
     * The human name of the system a tool slug belongs to. Composio slugs are
     * prefixed with their toolkit (GOOGLESHEETS_BATCH_GET), NetSuite tools with
     * netsuite_ — so the prefix identifies the source without a lookup table
     * that could drift from the configured toolkits.
     */
    private static function sourceFor(string $tool): string
    {
        if (str_starts_with(mb_strtolower($tool), NetsuiteService::TOOL_PREFIX)) {
            return 'NetSuite';
        }

        $prefix = mb_strtolower(explode('_', $tool)[0]);

        foreach ((array) config('services.composio.toolkits', []) as $key => $meta) {
            if (mb_strtolower((string) $key) === $prefix) {
                return (string) ($meta['name'] ?? $key);
            }
        }

        return $prefix !== '' ? ucfirst($prefix) : 'The tool';
    }

    private static function trim(string $raw): string
    {
        $raw = trim($raw);
        $max = (int) config('services.anthropic.tool_errors.max_detail_chars', 600);

        if ($max > 0 && mb_strlen($raw) > $max) {
            return mb_substr($raw, 0, $max).'…';
        }

        return $raw !== '' ? $raw : 'No message returned.';
    }
}
