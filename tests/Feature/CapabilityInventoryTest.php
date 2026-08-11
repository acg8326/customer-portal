<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\User;
use App\Services\CapabilityInventory;

function inventory(): CapabilityInventory
{
    return app(CapabilityInventory::class);
}

function capabilityUser(): User
{
    config([
        'services.composio.api_key' => 'ck_test',
        'services.composio.toolkits.googlesheets.auth_config_id' => 'ac_test',
        'services.composio.toolkits.slack.auth_config_id' => 'ac_test',
        'services.netsuite.enabled' => true,
    ]);

    return User::factory()->create();
}

function capabilityConversation(User $user, string $userTurn): Conversation
{
    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Capabilities';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();
    $conversation->messages()->create(['role' => 'user', 'content' => $userTurn]);

    return $conversation;
}

function capabilityBlockFor(User $user, Conversation $conversation): string
{
    $controller = app(ChatController::class);

    (new ReflectionMethod(ChatController::class, 'resolveToolSources'))
        ->invoke($controller, $user, $conversation, false);

    return (string) (new ReflectionMethod(ChatController::class, 'connectedToolsBlock'))
        ->invoke($controller, $user);
}

// --- what's connectable -------------------------------------------------------

test('configured-but-unconnected sources are offered; connected ones are not', function () {
    $user = capabilityUser();
    $user->composioConnections()->create(['toolkit' => 'googlesheets', 'status' => 'active']);

    $names = array_column(inventory()->connectable($user), 'name');

    expect($names)->toContain('Slack')
        ->and($names)->toContain('NetSuite')
        ->and($names)->not->toContain('Google Sheets');
});

test('a toolkit with no auth config is never offered', function () {
    $user = capabilityUser();
    config(['services.composio.toolkits.slack.auth_config_id' => null]);

    // Offering it would send the user to a button that cannot work.
    expect(array_column(inventory()->connectable($user), 'name'))->not->toContain('Slack');
});

test('nothing Composio is offered when Composio itself is unconfigured', function () {
    $user = capabilityUser();
    config(['services.composio.api_key' => null]);

    $names = array_column(inventory()->connectable($user), 'name');

    expect($names)->not->toContain('Slack')
        // NetSuite is native, so it survives.
        ->and($names)->toContain('NetSuite');
});

test('a half-finished connection is flagged pending, not "go connect it"', function () {
    $user = capabilityUser();
    $user->composioConnections()->create(['toolkit' => 'slack', 'status' => 'initiated']);

    $slack = collect(inventory()->connectable($user))->firstWhere('name', 'Slack');

    expect($slack['pending'])->toBeTrue();
});

test('NetSuite is disabled entirely when the feature is off', function () {
    $user = capabilityUser();
    config(['services.netsuite.enabled' => false]);

    expect(array_column(inventory()->connectable($user), 'name'))->not->toContain('NetSuite');
});

// --- what's off this turn -----------------------------------------------------

test('a non-Claude model reports one combined reason, not a pile of them', function () {
    $off = inventory()->offThisTurn(claudeModel: false, privateMode: false, webSearchOn: true);

    expect($off)->toHaveCount(1)
        ->and($off[0]['why'])->toContain('not a Claude model');
});

test('private mode and the web toggle each explain themselves with a fix', function () {
    $off = inventory()->offThisTurn(claudeModel: true, privateMode: true, webSearchOn: false);
    $text = implode(' ', array_column($off, 'why'));

    expect($off)->toHaveCount(2)
        ->and($text)->toContain('Private')
        ->and($text)->toContain('globe');
});

test('disabled uploads are reported as an admin setting', function () {
    config(['services.anthropic.uploads.enabled' => false]);

    $why = implode(' ', array_column(
        inventory()->offThisTurn(claudeModel: true, privateMode: false, webSearchOn: true),
        'why',
    ));

    expect($why)->toContain('admin');
});

// --- out-of-band abilities ----------------------------------------------------

test('image generation is described as a button, not a refusal', function () {
    config(['services.media.image.key' => 'sk-test', 'services.media.image.provider_name' => 'OpenAI']);

    $text = implode(' ', inventory()->outOfBand());

    expect($text)->toContain('image button')
        ->and($text)->toContain('rather than saying you are unable');
});

test('an unconfigured image provider is not advertised', function () {
    config(['services.media.image.key' => null]);

    expect(implode(' ', inventory()->outOfBand()))->not->toContain('image button');
});

// --- the rendered prompt block ------------------------------------------------

test('the prompt names unconnected sources and forbids volunteering them', function () {
    $user = capabilityUser();
    $conversation = capabilityConversation($user, 'what were our open invoices?');

    $block = capabilityBlockFor($user, $conversation);

    expect($block)->toContain('Available to connect, but not connected yet')
        ->and($block)->toContain('NetSuite')
        ->and($block)->toContain('Integrations')
        // The anti-nag rule is the whole reason this is safe to ship.
        ->and($block)->toContain('never volunteer the list')
        ->and($block)->toContain('You cannot connect it for them');
});

test('the whole block disappears when the feature is switched off', function () {
    config(['services.anthropic.connected_tools_prompt' => false]);

    $user = capabilityUser();
    $conversation = capabilityConversation($user, 'what were our open invoices?');
    $controller = app(ChatController::class);

    (new ReflectionMethod(ChatController::class, 'resolveToolSources'))
        ->invoke($controller, $user, $conversation, false);

    $prompt = (string) (new ReflectionMethod(ChatController::class, 'buildSystemPrompt'))
        ->invoke($controller, $conversation);

    expect($prompt)->not->toContain('Available to connect');
});

// --- the plural regression ----------------------------------------------------

test('plural phrasing still routes to the right source', function () {
    $controller = app(ChatController::class);
    $match = fn (string $text, array $kw): bool => (bool) (new ReflectionMethod(ChatController::class, 'matchesAny'))
        ->invoke($controller, $text, $kw);

    // Word-boundary matching alone broke these: people write plurals, and a
    // miss here drops the source exactly when the user wants it.
    expect($match('show me the open invoices', ['invoice']))->toBeTrue()
        ->and($match('list our customers', ['customer']))->toBeTrue()
        ->and($match('which vendors did we pay', ['vendor']))->toBeTrue()
        ->and($match('add two rows', ['row']))->toBeTrue()
        ->and($match('check the cells', ['cell']))->toBeTrue()
        // ...without reopening the substring hole.
        ->and($match('check my admin settings', ['dm']))->toBeFalse()
        ->and($match('show me tomorrow figures', ['row']))->toBeFalse()
        ->and($match('that was excellent', ['cell']))->toBeFalse();
});

test('singularizing does not mangle short or double-s words', function () {
    $controller = app(ChatController::class);
    $match = fn (string $text, array $kw): bool => (bool) (new ReflectionMethod(ChatController::class, 'matchesAny'))
        ->invoke($controller, $text, $kw);

    expect($match('the business address', ['address']))->toBeTrue()
        ->and($match('our css file', ['css']))->toBeTrue()
        ->and($match('gas prices', ['gas']))->toBeTrue();
});
