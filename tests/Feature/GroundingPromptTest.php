<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\User;

/**
 * The anti-fabrication rules are prompt text, which makes them easy to trim by
 * accident during an unrelated edit — and their absence is invisible until the
 * assistant confidently invents a number. These assert the load-bearing clauses
 * survive in the fully built prompt, so an override that drops them fails here
 * too.
 *
 * Whitespace is collapsed because the prompt is a wrapped heredoc: the clauses
 * are split across lines and would otherwise never match as written.
 */
function groundingPrompt(?User $user = null): string
{
    $user ??= User::factory()->create();

    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Grounding';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    $prompt = (string) (new ReflectionMethod(ChatController::class, 'buildSystemPrompt'))
        ->invoke(app(ChatController::class), $conversation);

    return (string) preg_replace('/\s+/', ' ', $prompt);
}

test('the prompt forbids inventing data', function () {
    expect(groundingPrompt())
        ->toContain('Never invent account details, policy specifics, dates, or numbers')
        ->toContain('a confident wrong answer is worse than an honest "I don\'t know."');
});

test('the prompt forbids claiming to have checked a source it never called', function () {
    // The routing bug made this concrete: a source can be dropped from a turn,
    // and "I checked NetSuite" would then be a fabrication.
    expect(groundingPrompt())
        ->toContain('Never claim to have checked a system you did not actually call')
        ->toContain('do not answer as though you had the data');
});

test('the prompt forbids filling gaps in a partial result', function () {
    // Tool results are truncated at ANTHROPIC_TOOL_RESULT_MAX_CHARS, so a
    // partial result is routine, not an edge case.
    expect(groundingPrompt())
        ->toContain('Do not fill gaps in a partial or truncated result')
        ->toContain('report what you actually got and what is missing');
});

test('the prompt forbids inventing ids, links and citations', function () {
    expect(groundingPrompt())
        ->toContain('Never invent record ids, links, file names, or citations');
});

test('a guess dressed as an answer is ruled out explicitly', function () {
    expect(groundingPrompt())
        ->toContain('a guess dressed as an answer is not');
});

test('a connected source is told to answer from what the tool returned', function () {
    config(['services.composio.toolkits.googlesheets.auth_config_id' => 'ac_test']);

    $user = User::factory()->create();
    $user->composioConnections()->create(['toolkit' => 'googlesheets', 'status' => 'active']);

    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Grounding';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    $controller = app(ChatController::class);
    (new ReflectionMethod(ChatController::class, 'resolveToolSources'))
        ->invoke($controller, $user, $conversation, false);

    $block = (string) (new ReflectionMethod(ChatController::class, 'connectedToolsBlock'))
        ->invoke($controller, $user);

    expect($block)->toContain('never claim a number you did not fetch')
        ->and($block)->toContain('If a tool returns nothing or errors, say so plainly');
});
