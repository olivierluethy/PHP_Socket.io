<?php

declare(strict_types=1);

namespace Realtime\Service;

use Realtime\Exception\RealtimeException;

/**
 * Maps action types to handlers. The host app registers domain handlers
 * (queue.add, vote.cast, playback.set, ...); the module keeps a few generic
 * state handlers so simple apps need no code at all.
 *
 * A handler is any callable(ActionContext): ActionResult.
 */
final class ActionRegistry
{
    /** @var array<string, callable(ActionContext):ActionResult> */
    private array $handlers = [];

    public function __construct(bool $withBuiltins = true)
    {
        if ($withBuiltins) {
            $this->registerBuiltins();
        }
    }

    /** @param callable(ActionContext):ActionResult $handler */
    public function register(string $type, callable $handler): self
    {
        $this->handlers[$type] = $handler;
        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    public function handle(ActionContext $ctx): ActionResult
    {
        $handler = $this->handlers[$ctx->type] ?? null;
        if ($handler === null) {
            throw new RealtimeException("Unknown action type: {$ctx->type}", 422);
        }
        return $handler($ctx);
    }

    /**
     * Built-in generic state actions:
     *  - state.patch : shallow-merge payload into top-level state
     *  - state.set   : same, but null values delete keys
     *  - state.replace : replace the whole state with payload
     */
    private function registerBuiltins(): void
    {
        $this->register('state.patch', static function (ActionContext $ctx): ActionResult {
            return ActionResult::state(array_merge($ctx->state(), $ctx->payload));
        });

        $this->register('state.set', static function (ActionContext $ctx): ActionResult {
            $state = $ctx->state();
            foreach ($ctx->payload as $k => $v) {
                if ($v === null) {
                    unset($state[$k]);
                } else {
                    $state[$k] = $v;
                }
            }
            return ActionResult::state($state);
        });

        $this->register('state.replace', static function (ActionContext $ctx): ActionResult {
            return ActionResult::state($ctx->payload);
        });
    }
}
