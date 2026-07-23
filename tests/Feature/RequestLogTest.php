<?php

use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\RequestLog;
use App\Models\User;
use Illuminate\Http\Request;

function requestLogChat(User $user): Conversation
{
    $conversation = new Conversation;
    $conversation->user_id = $user->id;
    $conversation->title = 'Log test chat';
    $conversation->model = 'claude-opus-4-8';
    $conversation->save();

    return $conversation;
}

test('finalizeTurn logs a successful persisted chat request', function () {
    $user = User::factory()->create();
    $conversation = requestLogChat($user);

    $request = Request::create('/chat/stream', 'POST');
    $request->setUserResolver(fn () => $user->fresh());

    (new ReflectionMethod(ChatController::class, 'finalizeTurn'))->invoke(
        app(ChatController::class),
        $request, $conversation, 'the reply', 'claude-opus-4-8', 120, 45, $user->id,
    );

    $log = RequestLog::query()->where('surface', 'chat')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id)
        ->and($log->model)->toBe('claude-opus-4-8')
        ->and($log->input_tokens)->toBe(120)
        ->and($log->output_tokens)->toBe(45)
        ->and($log->status)->toBe(200)
        ->and($log->latency_ms)->not->toBeNull();
});

test('finalizeTurn logs a successful private-mode chat request', function () {
    $user = User::factory()->create();
    $conversation = requestLogChat($user);

    $request = Request::create('/chat/stream', 'POST');
    $request->setUserResolver(fn () => $user->fresh());

    $controller = app(ChatController::class);
    (new ReflectionProperty(ChatController::class, 'privateMode'))->setValue($controller, true);

    (new ReflectionMethod(ChatController::class, 'finalizeTurn'))->invoke(
        $controller,
        $request, $conversation, 'the reply', 'claude-opus-4-8', 30, 10, $user->id,
    );

    // The private-mode branch is a separate early return — guard it logs too.
    $log = RequestLog::query()->where('surface', 'chat')->first();

    expect($log)->not->toBeNull()
        ->and($log->input_tokens)->toBe(30)
        ->and($log->output_tokens)->toBe(10)
        ->and($log->status)->toBe(200);
});

test('chat request logging respects the request_log_enabled config', function () {
    config(['services.anthropic.request_log_enabled' => false]);

    $user = User::factory()->create();
    $conversation = requestLogChat($user);

    $request = Request::create('/chat/stream', 'POST');
    $request->setUserResolver(fn () => $user->fresh());

    (new ReflectionMethod(ChatController::class, 'finalizeTurn'))->invoke(
        app(ChatController::class),
        $request, $conversation, 'the reply', 'claude-opus-4-8', 10, 5, $user->id,
    );

    expect(RequestLog::query()->count())->toBe(0);
});
