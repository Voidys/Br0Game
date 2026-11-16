<?php

namespace App\Controller\Game;

use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;

/**
 * Stores a client-side error/report comment of the player (ajax).
 */
#[\App\Routing\GamePage('game.php', 'report')]
#[\App\Routing\GamePage('game.php', 'report', uniPrefix: true)]
class ReportController extends AbstractGameController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('ajax');
    }

    /**
     * Stores a client-side report as a player comment (USERS_COMMENTS) through an XHR call. Request: hi, u, o, ts.
     */
    public function show()
    {
        $USER = $this->state->USER;

        $humanInteraction = $this->http()->_GP('hi', 0);
        $uri = $this->http()->_GP('u', '');
        $outline = $this->http()->_GP('o', '');
        $timestamp = $this->http()->_GP('ts', 0);

        $db = $this->db;

        // Throttle: at most 20 comments per user per minute, bounded lengths
        $sql = "SELECT COUNT(*) AS cnt FROM %%USERS_COMMENTS%% WHERE `user_id` = :user AND `created_at` > :since;";
        $recent = $db->selectSingle($sql, [
            ':user'  => $USER['id'],
            ':since' => $this->state->runtime->timestamp - 60,
        ], 'cnt');
        if ($recent >= 20) {
            throw new \App\Game\Core\EndOfRequest('saved');
        }
        $uri = mb_substr((string) $uri, 0, 1000);
        $outline = mb_substr((string) $outline, 0, 1000);

        $sql = "INSERT INTO %%USERS_COMMENTS%% SET
            `user_id`       = :user,
            `comment`       = :comment,
            `info`          = :info,
            `created_at`    = :timestamp;";

        $db->insert($sql, [
            ':user'         => $USER['id'],
            ':comment'      => urlencode($uri),
            ':info'         => $outline,
            ':timestamp'   => $this->state->runtime->timestamp,
        ]);

        throw new \App\Game\Core\EndOfRequest('saved');
    }
}
