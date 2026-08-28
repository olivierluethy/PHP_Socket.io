<?php

declare(strict_types=1);

namespace Realtime\Http;

use Realtime\Config;
use Realtime\Domain\Identity;
use Realtime\Exception\NotFoundException;
use Realtime\Exception\RateLimitException;
use Realtime\Exception\RealtimeException;
use Realtime\Exception\UnauthorizedException;
use Realtime\Service\SessionService;
use Realtime\Storage\StorageDriver;
use Realtime\Support\Ids;
use Realtime\Transport\LongPoll;
use Realtime\Transport\Sse;

/**
 * Routes the full realtime API surface onto the service and transports. Mount-
 * prefix agnostic: routes match the tail of the path, so the same kernel works
 * whether mounted at /realtime, /api/realtime or the web root.
 *
 *   POST /sessions                    create
 *   POST /sessions/{id}/join          join
 *   POST /sessions/{id}/leave         leave
 *   POST /sessions/{id}/actions       submit action
 *   GET  /sessions/{id}/sync?since=   long/short-poll deltas (snapshot on gap)
 *   GET  /sessions/{id}/stream?since= SSE (Tier B)
 *   POST /sessions/{id}/heartbeat     presence
 */
final class Kernel
{
    private Cors $cors;
    private LongPoll $longPoll;
    private Sse $sse;

    public function __construct(
        private SessionService $service,
        private StorageDriver $storage,
        private Config $config,
    ) {
        $this->cors = new Cors($config);
        $this->longPoll = new LongPoll($service, $storage, $config);
        $this->sse = new Sse($service, $storage, $config);
    }

    /** Read the request from globals, route it, and send the response. */
    public function run(?Request $request = null): void
    {
        $request ??= Request::fromGlobals();
        $cors = $this->cors->headers($request);

        if ($this->cors->isPreflight($request)) {
            (new Response(204, '', $cors))->send();
            return;
        }

        try {
            $response = $this->dispatch($request, $cors);
        } catch (RealtimeException $e) {
            $response = Response::json(['error' => $e->getMessage()], $e->httpStatus(), $cors);
        } catch (\Throwable $e) {
            error_log('[realtime] ' . $e->getMessage());
            $response = Response::json(['error' => 'Internal error'], 500, $cors);
        }

        $response?->send();
    }

    /** @param array<string,string> $cors */
    private function dispatch(Request $request, array $cors): ?Response
    {
        $path = $request->path;
        $method = $request->method;

        if ($method === 'POST' && preg_match('#/sessions$#', $path)) {
            return $this->create($request, $cors);
        }
        if (preg_match('#/sessions/([^/]+)/(join|leave|actions|sync|stream|heartbeat|emit)$#', $path, $m)) {
            $sessionId = $m[1];
            if (!Ids::isValidSession($sessionId)) {
                throw new NotFoundException('Invalid session id');
            }
            return match ($m[2]) {
                'join' => $this->requirePost($method, fn () => $this->join($request, $sessionId, $cors)),
                'leave' => $this->requirePost($method, fn () => $this->leave($request, $sessionId, $cors)),
                'actions' => $this->requirePost($method, fn () => $this->actions($request, $sessionId, $cors)),
                'heartbeat' => $this->requirePost($method, fn () => $this->heartbeat($request, $sessionId, $cors)),
                'emit' => $this->requirePost($method, fn () => $this->emit($request, $sessionId, $cors)),
                'sync' => $this->sync($request, $sessionId, $cors),
                'stream' => $this->stream($request, $sessionId, $cors),
                default => throw new NotFoundException(),
            };
        }

        throw new NotFoundException('No such realtime route');
    }

    // ---- Endpoints ----------------------------------------------------------

    private function create(Request $request, array $cors): Response
    {
        $body = $request->json();
        $result = $this->service->create(
            $this->optionalString($body, 'ownerId'),
            $this->arrayField($body, 'state'),
            $this->arrayField($body, 'meta'),
            $this->optionalString($body, 'sessionId'),
        );
        return Response::json($result, 201, $cors);
    }

    private function join(Request $request, string $sessionId, array $cors): Response
    {
        $body = $request->json();
        $result = $this->service->join(
            $sessionId,
            $this->optionalString($body, 'userId'),
            $this->arrayField($body, 'meta'),
        );
        return Response::json($result, 200, $cors);
    }

    private function leave(Request $request, string $sessionId, array $cors): Response
    {
        $this->service->leave($this->authFor($request, $sessionId));
        return Response::json(['ok' => true], 200, $cors);
    }

    private function actions(Request $request, string $sessionId, array $cors): Response
    {
        $actor = $this->authFor($request, $sessionId);
        $body = $request->json();

        $type = $this->optionalString($body, 'type');
        if ($type === null || $type === '') {
            throw new RealtimeException('Missing action type', 400);
        }
        $baseVersion = isset($body['baseVersion']) ? (int) $body['baseVersion'] : null;

        $result = $this->service->submitAction($actor, $type, $this->arrayField($body, 'payload'), $baseVersion);
        return Response::json($result, 200, $cors);
    }

    private function heartbeat(Request $request, string $sessionId, array $cors): Response
    {
        $result = $this->service->heartbeat($this->authFor($request, $sessionId));
        return Response::json($result, 200, $cors);
    }

    private function emit(Request $request, string $sessionId, array $cors): Response
    {
        $secret = $this->config->serviceSecret;
        $presented = $request->header('x-realtime-service') ?? '';
        if ($secret === '' || !hash_equals($secret, $presented)) {
            throw new UnauthorizedException('Invalid service credentials');
        }

        $body = $request->json();
        $type = $this->optionalString($body, 'type');
        if ($type === null || $type === '') {
            throw new RealtimeException('Missing event type', 400);
        }
        $statePatch = isset($body['statePatch']) && is_array($body['statePatch']) ? $body['statePatch'] : null;

        $result = $this->service->systemEvent($sessionId, $type, $this->arrayField($body, 'payload'), $statePatch);
        return Response::json($result, 200, $cors);
    }

    private function sync(Request $request, string $sessionId, array $cors): Response
    {
        $actor = $this->authFor($request, $sessionId);
        if (!$this->storage->rateLimitAllow("poll:{$sessionId}:{$actor->userId}", $this->config->pollRateLimit, $this->config->rateWindow)) {
            throw new RateLimitException();
        }
        $since = (int) ($request->query('since') ?? 0);
        $response = $this->longPoll->handle($sessionId, $since, $actor);
        return new Response($response->status, $response->body, $response->headers + $cors);
    }

    private function stream(Request $request, string $sessionId, array $cors): ?Response
    {
        if (!$this->config->sseEnabled) {
            throw new RealtimeException('SSE transport disabled', 501);
        }
        $actor = $this->authFor($request, $sessionId);
        $since = (int) ($request->query('since') ?? 0);
        $this->sse->stream($sessionId, $since, $actor, $cors); // streams then exit()s
        return null;
    }

    // ---- Helpers ------------------------------------------------------------

    private function authFor(Request $request, string $sessionId): Identity
    {
        $token = $request->token();
        if ($token === null || $token === '') {
            throw new UnauthorizedException('Missing participant token');
        }
        return $this->service->authenticate($token, $sessionId);
    }

    /** @param callable():Response $fn */
    private function requirePost(string $method, callable $fn): Response
    {
        if ($method !== 'POST') {
            throw new RealtimeException('Method not allowed', 405);
        }
        return $fn();
    }

    /** @param array<string,mixed> $body */
    private function optionalString(array $body, string $key): ?string
    {
        return isset($body[$key]) && $body[$key] !== '' ? (string) $body[$key] : null;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function arrayField(array $body, string $key): array
    {
        return isset($body[$key]) && is_array($body[$key]) ? $body[$key] : [];
    }
}
