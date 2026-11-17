<?php

/**
 * pr0game powered by steemnova
 * RebuildProtection
 * (c) 2024 Hyman
 */

namespace App\Controller\Game;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Ends the player's rebuild protection early (XHR). */
class RebuildProtectionAjaxController extends AbstractGameController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('ajax');
    }

    /**
     * Ends the player's rebuild protection early (XHR): clears the protection fields on the user row.
     */
    #[GameRoute('game', 'game/rebuild-protection', 'rebuildProtectionAjax', methods: ['POST'])]
    public function show(): Response
    {
        $USER = $this->state->USER;
        $db = $this->db;
        $sql = "UPDATE %%USERS%% SET `rebuild_protection` = 0, `rebuild_protection_attackers` = ''
            WHERE `id` = :userID;";
        $db->update($sql, [
            ':userID' => $USER['id'],
        ]);

        return new Response();
    }
}
