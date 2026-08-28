<?php

declare(strict_types=1);

/**
 * TuneVote front controller for php-realtime.
 *
 * Deploy on the VPS (php-fpm behind the existing nginx) so it can reach the
 * `tunevote` MySQL on 127.0.0.1:3306. Node keeps all domain logic and fans out
 * events via POST /realtime/sessions/{id}/emit (see rt-emit.js); clients receive
 * them over long-poll/SSE. The only participant-driven action here is the
 * ephemeral emoji reaction; everything else arrives from Node.
 *
 * nginx: location /realtime/ { try_files $uri /realtime.tunevote.php$is_args$args; }
 *        (or an alias + php-fpm fastcgi_pass, routing /realtime/* to this file).
 *
 * Env (share the app DB, distinct prefix so nothing collides):
 *   RT_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=tunevote;charset=utf8mb4'
 *   RT_DB_USER=... RT_DB_PASSWORD=...
 *   RT_TABLE_PREFIX='rt_'
 *   RT_TOKEN_SECRET=<random>        # signs participant tokens
 *   RT_SERVICE_SECRET=<random>      # equals Node's RT_SERVICE_SECRET
 *   RT_CORS_ORIGINS='https://app.tunevote.com'
 */

require __DIR__ . '/../../autoload.php';

use Realtime\Domain\Role;
use Realtime\Domain\SessionRecord;
use Realtime\Realtime;
use Realtime\Service\ActionContext;
use Realtime\Service\ActionResult;

const TUNEVOTE_REACTIONS = ['😍', '🔥', '👏', '🎉', '😴'];

$realtime = Realtime::fromEnv()
    // Anyone who joins can send reactions; only the session owner/host may drive
    // privileged fan-out actions if you choose to move any here later.
    ->setDefaultJoinRole(Role::PARTICIPANT)
    ->setDefaultPermission(Role::PARTICIPANT)

    // Owner = the TuneVote session owner (Node ensures the rt session with
    // ownerId = the host's user id). Everyone else is a participant/viewer.
    ->setRoleResolver(static function (SessionRecord $session, ?string $userId): string {
        if ($userId !== null && $session->ownerId !== null && $userId === $session->ownerId) {
            return Role::OWNER;
        }
        return Role::PARTICIPANT;
    })

    // Ephemeral emoji reaction: validated, rate-limited by the module, broadcast
    // to everyone as `song_reaction_broadcast` (mirrors the old Socket.io event).
    // It intentionally does not mutate shared state — only the event is fanned out.
    ->registerAction('song_reaction', static function (ActionContext $c): ActionResult {
        $emoji = (string) ($c->payload['emoji'] ?? '');
        if (!in_array($emoji, TUNEVOTE_REACTIONS, true)) {
            throw new \Realtime\Exception\RealtimeException('Invalid reaction', 422);
        }
        return ActionResult::state(
            $c->state(),                                   // unchanged
            ['emoji' => $emoji, 'actorId' => $c->actor->userId],
            'song_reaction_broadcast'                      // broadcast event type
        );
    });

$realtime->run();
