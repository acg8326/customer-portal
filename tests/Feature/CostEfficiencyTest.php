<?php

use App\Models\Conversation;
use App\Models\User;

// Seed one conversation with known token counts for the math assertions.
function seedCostConversation(User $user): Conversation
{
    $c = new Conversation;
    $c->user_id = $user->id;
    $c->title = 'Cost test';
    $c->model = 'claude-opus-4-8';
    $c->prompt_tokens = 1_000_000;      // uncached input
    $c->completion_tokens = 200_000;
    $c->cache_read_tokens = 3_000_000;  // served from cache
    $c->cache_write_tokens = 1_000_000; // written to cache
    $c->save();

    return $c;
}

test('super admin sees the cost & efficiency card with correct math', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    seedCostConversation($superAdmin);

    $this->actingAs($superAdmin)
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page
            ->component('Analytics')
            ->has('costEfficiency', fn ($ce) => $ce
                // opus-4-8 at $5/$25 per MTok: 1M uncached (5.00) + 3M reads
                // at 0.1x (1.50) + 1M writes at 1.25x (6.25) + 0.2M output
                // (5.00) = 17.75
                ->where('total_usd', 17.75)
                // 3M reads / (3M + 1M writes + 1M uncached) = 0.6
                ->where('cache.hit_rate', 0.6)
                // 3M reads would have cost 15.00; paid 1.50 → saved 13.50
                ->where('cache.saved_usd', 13.5)
                ->where('cache.read_tokens', 3_000_000)
                ->where('models.0.model', 'claude-opus-4-8')
                ->where('models.0.provider', 'Anthropic (Claude)')
                // input column shows the whole prompt: uncached + read + write
                ->where('models.0.input_tokens', 5_000_000)
                ->where('models.0.cost', 17.75)
                ->etc()
            )
        );
});

test('admins and members do not get cost data', function () {
    $admin = User::factory()->admin()->create();
    seedCostConversation($admin);

    // Analytics (where cost data lives) is super-admin only — a plain admin
    // or member is blocked at the route, never sees the page at all.
    $this->actingAs($admin)->get('/analytics')->assertStatus(403);
    $this->actingAs(User::factory()->create())->get('/analytics')->assertStatus(403);
});

test('LLM_PRICES-style config override changes the estimate', function () {
    config(['services.llm_pricing.models' => ['claude-opus-4-8' => [10.0, 50.0]]]);

    $superAdmin = User::factory()->superAdmin()->create();
    seedCostConversation($superAdmin);

    $this->actingAs($superAdmin)
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page
            // Doubled prices → doubled estimate.
            ->where('costEfficiency.total_usd', 35.5)
        );
});
