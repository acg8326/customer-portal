<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Validate every model id in the chat picker (config services.anthropic.models)
 * against the live API, so a stale id can't 404 on users mid-chat. Uses the
 * free count_tokens endpoint — no tokens are billed. Run it after editing the
 * model list or as part of a deploy.
 */
class CheckChatModels extends Command
{
    protected $signature = 'chat:check-models';

    protected $description = 'Verify each chat model id in config against the live Anthropic API.';

    public function handle(): int
    {
        $key = (string) config('services.anthropic.key');

        if ($key === '') {
            $this->error('ANTHROPIC_API_KEY is not set — cannot check models.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) config('services.anthropic.base_url', 'https://api.anthropic.com'), '/');
        $failed = 0;

        foreach (array_keys(Config::array('services.anthropic.models')) as $model) {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ])->timeout(15)->post($baseUrl.'/v1/messages/count_tokens', [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ]);

            if ($response->successful()) {
                $this->line("  ✓ {$model}");
            } else {
                $failed++;
                $detail = (string) $response->json('error.message', $response->body());
                $this->error("  ✗ {$model} — HTTP {$response->status()}: ".mb_strimwidth($detail, 0, 120, '…'));
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} model id(s) failed — fix config/services.php (anthropic.models) before shipping.");

            return self::FAILURE;
        }

        $this->info('All chat model ids are valid.');

        return self::SUCCESS;
    }
}
