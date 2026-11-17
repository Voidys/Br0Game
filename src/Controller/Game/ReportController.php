<?php

namespace App\Controller\Game;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores a client-side error/report comment of the player (ajax).
 */
class ReportController extends AbstractGameController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('ajax');
    }

    /**
     * Stores a client-side report as a player comment (USERS_COMMENTS) through an XHR call. Request: hi, u, o, ts.
     */
    #[GameRoute('game', 'game/client-report', 'report', 'show', ['POST'])]
    public function show(): Response
    {
        $USER = $this->state->USER;

        $uri = $this->input()->string('u');
        $outline = $this->input()->string('o');

        $db = $this->db;

        // Caps (the client answer stays "saved"): at most 20 comments per user and hour and 200 in total, bounded lengths
        $sql = "SELECT COUNT(*) AS total, COALESCE(SUM(`created_at` > :since), 0) AS recent FROM %%USERS_COMMENTS%% WHERE `user_id` = :user;";
        $counts = $db->selectSingle($sql, [
            ':user'  => $USER['id'],
            ':since' => $this->state->runtime->timestamp - 3600,
        ]);
        if ((int) $counts['recent'] >= 20 || (int) $counts['total'] >= 200) {
            return new Response('saved');
        }
        $uri = mb_substr($uri, 0, 1000);
        $outline = mb_substr($outline, 0, 1000);

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

        return new Response('saved');
    }
}
