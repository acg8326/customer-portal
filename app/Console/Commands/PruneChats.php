<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Project;
use App\Models\Skill;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Enforces the data-retention policy (config/retention.php). Scheduled daily;
 * both knobs default to safe values (chat pruning is OFF unless
 * RETENTION_CHAT_DAYS is set; trashed rows purge after 30 days).
 */
class PruneChats extends Command
{
    protected $signature = 'chat:prune {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete conversations past the retention window and purge old trashed records';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->pruneOldChats($dry);
        $this->purgeTrash($dry);

        return self::SUCCESS;
    }

    private function pruneOldChats(bool $dry): void
    {
        $days = (int) config('retention.chat_days', 0);

        if ($days <= 0) {
            $this->info('Chat retention is off (RETENTION_CHAT_DAYS=0) — no conversations pruned.');

            return;
        }

        $query = Conversation::withTrashed()
            ->where('updated_at', '<', Carbon::now()->subDays($days));

        $count = $query->count();

        if ($dry) {
            $this->info("[dry-run] Would permanently delete {$count} conversation(s) older than {$days} days.");

            return;
        }

        foreach ($query->cursor() as $conversation) {
            Storage::deleteDirectory("chat-attachments/{$conversation->id}");
            $conversation->forceDelete(); // messages cascade via FK
        }

        $this->info("Permanently deleted {$count} conversation(s) older than {$days} days.");
    }

    private function purgeTrash(bool $dry): void
    {
        $days = (int) config('retention.trash_days', 30);

        if ($days <= 0) {
            return;
        }

        $cutoff = Carbon::now()->subDays($days);
        $purged = 0;

        $conversations = Conversation::onlyTrashed()->where('deleted_at', '<', $cutoff);

        if (! $dry) {
            foreach ($conversations->cursor() as $conversation) {
                Storage::deleteDirectory("chat-attachments/{$conversation->id}");
                $conversation->forceDelete();
                $purged++;
            }
        } else {
            $purged += $conversations->count();
        }

        foreach ([Project::class, Skill::class] as $model) {
            $query = $model::onlyTrashed()->where('deleted_at', '<', $cutoff);
            $purged += $dry ? $query->count() : $query->forceDelete();
        }

        $this->info(($dry ? '[dry-run] Would purge' : 'Purged')." {$purged} trashed record(s) deleted more than {$days} days ago.");
    }
}
