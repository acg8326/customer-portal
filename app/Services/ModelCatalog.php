<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

/**
 * The grouped model picker's source of truth: Claude (full-featured, from
 * services.anthropic) plus the OpenAI-compatible providers from
 * services.llm_providers (plain chat). A provider is "available" when its
 * global API key is set; locked providers still show in the picker so users
 * can request access from the super admin.
 */
class ModelCatalog
{
    public const ANTHROPIC = 'anthropic';

    /**
     * "When to use it" lines for the Claude models (the anthropic list keeps
     * its legacy id => label shape, so hints live here). Unknown ids get ''.
     */
    private const CLAUDE_HINTS = [
        'claude-opus-4-8' => 'Deep reasoning — the hardest problems',
        'claude-opus-4-7' => 'Previous flagship — complex work',
        'claude-opus-4-1' => 'Legacy Opus',
        'claude-sonnet-5' => 'Best everyday balance of speed & smarts',
        'claude-sonnet-4-6' => 'Fast, capable all-rounder',
        'claude-sonnet-4-5' => 'Fast, capable all-rounder',
        'claude-haiku-4-5' => 'Fastest & cheapest — quick questions',
        'claude-fable-5' => 'Frontier reasoning — most capable Claude',
    ];

    /**
     * Every provider for the picker, Claude first.
     *
     * @return list<array{key: string, name: string, available: bool, blurb: string, models: list<array{value: string, label: string, hint: string}>}>
     */
    public function providers(): array
    {
        $claudeModels = [];

        foreach (Config::array('services.anthropic.models') as $id => $label) {
            $claudeModels[] = [
                'value' => (string) $id,
                'label' => (string) $label,
                'hint' => self::CLAUDE_HINTS[$id] ?? '',
            ];
        }

        $providers = [[
            'key' => self::ANTHROPIC,
            'name' => 'Anthropic (Claude)',
            'available' => filled(config('services.anthropic.key')),
            'blurb' => 'Full experience — connected tools, web search, thinking, files & memory.',
            'models' => $claudeModels,
        ]];

        foreach (Config::array('services.llm_providers') as $key => $cfg) {
            $models = $this->parseModelsEnv((string) ($cfg['models_env'] ?? ''));

            if ($models === []) {
                foreach ((array) ($cfg['models'] ?? []) as $id => $m) {
                    $models[] = [
                        'value' => (string) $id,
                        'label' => (string) ($m['label'] ?? $id),
                        'hint' => (string) ($m['hint'] ?? ''),
                    ];
                }
            }

            if ($models === []) {
                continue;
            }

            $providers[] = [
                'key' => (string) $key,
                'name' => (string) ($cfg['name'] ?? $key),
                'available' => filled($cfg['key'] ?? null),
                'blurb' => (string) ($cfg['blurb'] ?? ''),
                'models' => $models,
            ];
        }

        return $providers;
    }

    /**
     * Model ids a user may actually send with. Claude models always validate
     * (a missing ANTHROPIC_API_KEY gets the clearer "chat not configured"
     * 503, not a validation error); other providers' models validate only
     * when their key is set — the picker offers "request access" otherwise.
     *
     * @return list<string>
     */
    public function selectableModelIds(): array
    {
        $ids = [];

        foreach ($this->providers() as $provider) {
            if ($provider['available'] || $provider['key'] === self::ANTHROPIC) {
                $ids = array_merge($ids, array_column($provider['models'], 'value'));
            }
        }

        return $ids;
    }

    /**
     * The provider key a model belongs to (null = unknown model).
     */
    public function providerFor(string $modelId): ?string
    {
        foreach ($this->providers() as $provider) {
            if (in_array($modelId, array_column($provider['models'], 'value'), true)) {
                return $provider['key'];
            }
        }

        return null;
    }

    public function isAnthropic(string $modelId): bool
    {
        return $this->providerFor($modelId) === self::ANTHROPIC;
    }

    /**
     * Raw config for one OpenAI-compatible provider (base_url, key, …).
     *
     * @return array<string, mixed>|null
     */
    public function providerConfig(string $key): ?array
    {
        $cfg = config('services.llm_providers.'.$key);

        return is_array($cfg) ? $cfg : null;
    }

    /**
     * "id:Label|hint,id:Label|hint" — the single-line .env override shape.
     *
     * @return list<array{value: string, label: string, hint: string}>
     */
    private function parseModelsEnv(string $csv): array
    {
        $models = [];

        foreach (array_filter(explode(',', trim($csv))) as $entry) {
            [$id, $rest] = array_pad(explode(':', $entry, 2), 2, '');
            [$label, $hint] = array_pad(explode('|', $rest, 2), 2, '');

            if (trim($id) !== '') {
                $models[] = [
                    'value' => trim($id),
                    'label' => trim($label) !== '' ? trim($label) : trim($id),
                    'hint' => trim($hint),
                ];
            }
        }

        return $models;
    }
}
