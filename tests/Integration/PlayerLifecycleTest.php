<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\PlayerUtil;
use App\Tests\Support\GameTestCase;

/**
 * Behaviour of the PlayerUtil facade and the services behind it (App\Game\Core\Player\*): colonies and moons,
 * messages, vacation mode, protection rules and player deletion. Not golden values: each test checks the rows
 * and flags the game relies on, so the split of PlayerUtil cannot silently change them.
 */
final class PlayerLifecycleTest extends GameTestCase
{
    private function players(): PlayerUtil
    {
        return new PlayerUtil($this->db, $this->configs, $this->state);
    }

    public function testColonyAndMoonAreCreatedAndLinked(): void
    {
        [$user, $home] = $this->createPlayer();
        $players = $this->players();

        $colonyId = $players->createPlanet(2, 20, 6, 1, (int) $user['id'], 'Colony');
        $colony = $this->loadPlanet($colonyId);
        $this->assertSame('Colony', $colony['name']);
        $this->assertSame(1, (int) $colony['planet_type']);
        $this->assertGreaterThan(0, (int) $colony['field_max']);
        $this->assertGreaterThanOrEqual(1, $players->maxPlanetCount($user));
        $this->assertSame(2, $players->currentPlanetCount($user));
        $this->assertFalse($players->isPositionFree(1, 2, 20, 6));

        $moonId = $players->createMoon(1, 2, 20, 6, (int) $user['id'], 10.0, 'Luna');
        $moon = $this->loadPlanet($moonId);
        $this->assertSame(3, (int) $moon['planet_type']);
        $this->assertSame($moonId, (int) $this->loadPlanet($colonyId)['id_luna']);
        // a planet has one moon only
        $this->assertSame(0, $players->createMoon(1, 2, 20, 6, (int) $user['id'], 10.0));

        $this->assertSame((int) $home['id'], (int) $players->getMainPlanet($user)['id']);
        $this->assertCount(3, $players->getPlanets($user)); // home, colony, moon;
    }

    public function testPositionRules(): void
    {
        $players = $this->players();
        $this->assertFalse($players->checkPosition(1, 0, 1, 1));
        $this->assertFalse($players->checkPosition(1, 1, 401, 1));
        $this->assertTrue($players->checkPosition(1, 9, 400, 15));
        // without astrophysics only the middle slots are allowed
        $this->assertTrue($players->allowPlanetPosition(8));
        $this->assertFalse($players->allowPlanetPosition(1, null, 1));
        $pos = $players->getNewPlanetPosition(1);
        $this->assertTrue($players->checkPosition(1, (int) $pos['galaxy'], (int) $pos['system'], (int) $pos['planet']));
        $this->assertTrue($players->isPositionFree(1, (int) $pos['galaxy'], (int) $pos['system'], (int) $pos['planet']));
    }

    public function testSendMessageCreatesCopiesForReceiverAndSender(): void
    {
        [$sender] = $this->createPlayer();
        [$receiver] = $this->createPlayer();
        $this->players()->sendMessage((int) $receiver['id'], (int) $sender['id'], 'Sender', MESSAGE_TYPE_USER, 'Subject', 'Text', 1700000000);

        $message = $this->db->selectSingle('SELECT * FROM %%MESSAGES%% WHERE `message_subject` = :s;', [':s' => 'Subject']);
        $this->assertSame((int) $receiver['id'], (int) $message['message_owner']);
        $this->assertSame('u_' . $message['message_id'], $message['message_group']);
        $copies = $this->db->select('SELECT `user_id`, `message_unread` FROM %%USERS_TO_MESSAGES%% WHERE `message_id` = :id ORDER BY `user_id`;', [':id' => $message['message_id']]);
        $unread = array_column($copies, 'message_unread', 'user_id');
        $this->assertSame(1, (int) $unread[$receiver['id']]);
        $this->assertSame(0, (int) $unread[$sender['id']]);
    }

    public function testVacationModeRoundTrip(): void
    {
        [$user, $planet] = $this->createPlayer(['urlaubs_modus' => 0], ['metal_mine_porcent' => 10]);
        $players = $this->players();

        $players->enable_vmode($user, $planet);
        $this->assertSame(1, (int) $this->loadUser((int) $user['id'])['urlaubs_modus']);
        $this->assertSame(0, (int) $this->loadPlanet((int) $planet['id'])['metal_mine_porcent']);

        // vacation by ban has no minimum duration; leaving ends the flag and restores the mine percentage
        $this->db->update('UPDATE %%USERS%% SET `urlaubs_until` = 0, `urlaubs_start` = :t WHERE id = :id;', [':t' => time() - 100, ':id' => $user['id']]);
        $user = $this->loadUser((int) $user['id']);
        $planet = $this->loadPlanet((int) $planet['id']);
        $players->disable_vmode($user, $planet);
        $this->assertSame(0, (int) $this->loadUser((int) $user['id'])['urlaubs_modus']);
        $this->assertSame(10, (int) $this->loadPlanet((int) $planet['id'])['metal_mine_porcent']);
    }

    public function testNoobProtectionBetweenPlayers(): void
    {
        $this->setUniverseConfig(['noob_protection' => 1, 'noob_protection_time' => 5000, 'noob_protection_multi' => 5, 'noob_inactives' => 0, 'rebuild_protection' => 0, 'noob_protection_outlaw' => 0]);
        $small = ['id' => 1, 'banaday' => 0, 'onlinetime' => time(), 'total_points' => 100, 'rebuild_protection' => 0, 'rebuild_protection_attackers' => null, 'outlaw_time' => 0];
        $big = ['id' => 2, 'total_points' => 100000] + $small;
        $rules = $this->players();

        $this->assertSame(['NoobPlayer' => true, 'StrongPlayer' => false], $rules->checkNoobProtection($big, $small));
        $this->assertSame(['NoobPlayer' => false, 'StrongPlayer' => true], $rules->checkNoobProtection($small, $big));
        $this->assertSame(['NoobPlayer' => false, 'StrongPlayer' => false], $rules->checkNoobProtection($small, $small + ['total_points' => 200]));
        $this->assertSame(['banned', 'noob'], $rules->userStatus(['banaday' => time() + 1000], ['NoobPlayer' => true, 'StrongPlayer' => false]));
    }

    public function testDeletePlayerRemovesOwnedRows(): void
    {
        [$user, $planet] = $this->createPlayer();
        $players = $this->players();
        $userId = (int) $user['id'];

        $this->assertTrue($players->deletePlayer($userId));
        $this->assertFalse($players->deletePlayer($userId));
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS%% WHERE id = :id;', [':id' => $userId], 'c'));
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%PLANETS%% WHERE id_owner = :id;', [':id' => $userId], 'c'));
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_SETTINGS%% WHERE user_id = :id;', [':id' => $userId], 'c'));
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%STATPOINTS%% WHERE id_owner = :id;', [':id' => $userId], 'c'));
    }

    public function testDeletePlayerRemovesRowsOfBothDirectionsOfTwoColumnTables(): void
    {
        [$user] = $this->createPlayer();
        [$other] = $this->createPlayer();
        $userId = (int) $user['id'];
        $otherId = (int) $other['id'];
        foreach ([[$userId, $otherId], [$otherId, $userId], [$otherId, $otherId + 1000]] as [$blocking, $blocked]) {
            $this->db->insert('INSERT INTO %%USERS_BLOCKLIST%% SET blocking_user = :a, blocked_user = :b;', [':a' => $blocking, ':b' => $blocked]);
        }

        $this->assertTrue($this->players()->deletePlayer($userId));

        $left = $this->db->select('SELECT blocking_user, blocked_user FROM %%USERS_BLOCKLIST%%;');
        $this->assertCount(1, $left, 'both entries naming the deleted player are gone, the unrelated one stays');
        $this->assertEquals($otherId, $left[0]['blocking_user']);
    }
}
