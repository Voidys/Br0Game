<?php

/**
 * pr0game powered by steemnova
 * RebuildProtection
 * (c) 2024 Hyman
 */

namespace App\Controller\Game;

use App\Game\Core\Database;
use App\Game\Core\Singleton;

/** Ends the player's rebuild protection early (XHR). */
#[\App\Routing\GamePage('game.php', 'rebuildProtectionAjax')]
#[\App\Routing\GamePage('game.php', 'rebuildProtectionAjax', uniPrefix: true)]
class RebuildProtectionAjaxController extends AbstractGameController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('ajax');
    }

    public function show()
    {
        $USER = & $this->state->USER;
        $db = $this->db;
        $sql = "UPDATE %%USERS%% SET `rebuild_protection` = 0, `rebuild_protection_attackers` = ''
            WHERE `id` = :userID;";
        $db->update($sql, [
            ':userID' => $USER['id'],
        ]);
    }
}
