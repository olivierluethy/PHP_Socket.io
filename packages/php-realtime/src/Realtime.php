<?php

declare(strict_types=1);

namespace Realtime;

use PDO;
use Realtime\Domain\Role;
use Realtime\Http\Kernel;
use Realtime\Http\Request;
use Realtime\Service\ActionContext;
use Realtime\Service\ActionRegistry;
use Realtime\Service\ActionResult;
use Realtime\Service\Permissions;
use Realtime\Service\SessionService;
use Realtime\Storage\MySqlStorageDriver;
use Realtime\Storage\RedisStorageDriver;
use Realtime\Storage\StorageDriver;

/**
 * The single entry point an app mounts. Boot it with a Config, register your
 * domain actions and permissions, then dispatch the request:
 *
 *   Realtime::fromEnv()
 *     ->registerAction('queue.add', fn (ActionContext $c) => ...)
 *     ->setPermission('playback.set', Role::OWNER)
 *     ->run();
 *
 * Or drive the service directly (server-to-server) via ->service().
 * Nothing app-specific lives in the module — domain logic is the app's handlers.
 */
final class Realtime
{
    private ActionRegistry $actions;
    /** @var array<string,string> */
    private array $permissionRules = [];
    private string $defaultPermission = Role::PARTICIPANT;
    private string $defaultJoinRole = Role::PARTICIPANT;
    /** @var (callable(\Realtime\Domain\SessionRecord,?string,array):string)|null */
    private $roleResolver = null;

    private ?PDO $pdo = null;
    private ?StorageDriver $driver = null;
    private ?SessionService $service = null;

    private function __construct(private Config $config)
    {
        $this->actions = new ActionRegistry();
    }

    public static function boot(Config $config): self
    {
        return new self($config);
    }

    public static function fromEnv(array $overrides = []): self
    {
        return new self(Config::fromEnv($overrides));
    }

    // ---- Fluent configuration ----------------------------------------------

    /** @param callable(ActionContext):ActionResult $handler */
    public function registerAction(string $type, callable $handler): self
    {
        $this->actions->register($type, $handler);
        return $this;
    }

    public function setPermission(string $actionType, string $minRole): self
    {
        $this->permissionRules[$actionType] = $minRole;
        return $this;
    }

    public function setDefaultPermission(string $minRole): self
    {
        $this->defaultPermission = $minRole;
        return $this;
    }

    public function setDefaultJoinRole(string $role): self
    {
        $this->defaultJoinRole = $role;
        return $this;
    }

    /** @param callable(\Realtime\Domain\SessionRecord,?string,array):string $resolver */
    public function setRoleResolver(callable $resolver): self
    {
        $this->roleResolver = $resolver;
        return $this;
    }

    /** Share the host app's PDO connection (MySQL driver only). */
    public function usePdo(PDO $pdo): self
    {
        $this->pdo = $pdo;
        return $this;
    }

    /** Override the storage driver entirely (e.g. a custom implementation). */
    public function useDriver(StorageDriver $driver): self
    {
        $this->driver = $driver;
        return $this;
    }

    // ---- Accessors ----------------------------------------------------------

    public function config(): Config
    {
        return $this->config;
    }

    public function driver(): StorageDriver
    {
        if ($this->driver === null) {
            $this->driver = $this->config->driver === 'redis'
                ? new RedisStorageDriver($this->config)
                : new MySqlStorageDriver($this->config, $this->pdo);
        }
        return $this->driver;
    }

    public function service(): SessionService
    {
        if ($this->service === null) {
            $this->service = new SessionService(
                $this->driver(),
                $this->config,
                $this->actions,
                new Permissions($this->permissionRules, $this->defaultPermission),
                $this->defaultJoinRole,
                $this->roleResolver,
            );
        }
        return $this->service;
    }

    public function kernel(): Kernel
    {
        return new Kernel($this->service(), $this->driver(), $this->config);
    }

    /** Handle the current HTTP request end to end (routes, streams, sends). */
    public function run(?Request $request = null): void
    {
        $this->kernel()->run($request);
    }
}
