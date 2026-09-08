<?php

declare(strict_types=1);

namespace Realtime\Tests\Support;

use Realtime\Config;
use Realtime\Domain\Role;
use Realtime\Service\ActionContext;
use Realtime\Service\ActionRegistry;
use Realtime\Service\ActionResult;
use Realtime\Service\Permissions;
use Realtime\Service\SessionService;

/**
 * Builds a {@see SessionService} wired to the in-memory driver, with a small set
 * of domain actions and permission rules, so integration tests read declaratively.
 */
final class Factory
{
    public ArrayStorageDriver $storage;
    public Config $config;

    /** @param array<string,mixed> $configOverrides */
    public function __construct(array $configOverrides = [])
    {
        $this->storage = new ArrayStorageDriver();
        $this->config = new Config(...array_merge([
            'tokenSecret' => 'test-secret-please-change',
            'presenceTtl' => 30,
            'eventRetention' => 500,
            'actionRateLimit' => 30,
            'pollRateLimit' => 120,
            'rateWindow' => 10,
        ], $configOverrides));
    }

    public function service(): SessionService
    {
        $actions = new ActionRegistry();
        // A couple of representative domain actions on top of the built-ins.
        $actions->register('queue.add', static function (ActionContext $c): ActionResult {
            $state = $c->state();
            $state['queue'] = $state['queue'] ?? [];
            $state['queue'][] = $c->payload['item'] ?? null;
            return ActionResult::state($state);
        });
        $actions->register('playback.set', static function (ActionContext $c): ActionResult {
            $state = $c->state();
            $state['nowPlaying'] = $c->payload['videoId'] ?? null;
            return ActionResult::state($state, eventType: 'playback_sync');
        });

        $permissions = new Permissions(
            rules: ['playback.set' => Role::OWNER],
            default: Role::PARTICIPANT,
        );

        return new SessionService(
            $this->storage,
            $this->config,
            $actions,
            $permissions,
            Role::PARTICIPANT,
        );
    }
}
