<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Messages\MessageComposer;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\Mission;
use App\Game\Enum\MessageType;

/**
 * Private message sending (request ids arrive as strings) and the moon creation contract the admin creator relies on.
 */
final class MessagesAndCreatorTest extends GameTestCase
{
    public function testSendingAPrivateMessageAcceptsStringIds(): void
    {
        [$sender] = $this->createPlayer();
        [$receiver] = $this->createPlayer();
        $this->state->USER = $sender;
        $composer = new MessageComposer($this->db, $this->configs, $this->state);

        $receivers = $composer->deliverableReceivers([(string) $receiver['id']]);
        $this->assertSame([(int) $receiver['id']], $receivers);

        $banned = $composer->send($receivers, [(string) $receiver['id']], 'subject p4b4', 'hello', '');
        $this->assertFalse($banned);
        $count = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%USERS_TO_MESSAGES%% um JOIN %%MESSAGES%% m ON m.message_id = um.message_id WHERE um.user_id = :u AND m.message_subject = :s;',
            [':u' => $receiver['id'], ':s' => 'subject p4b4'],
            'c',
        );
        $this->assertSame(1, $count);
    }

    public function testCreatingASecondMoonReturnsZero(): void
    {
        [$user, $planet] = $this->createPlayer();
        $players = $this->planetManager();
        $args = [1, (int) $planet['galaxy'], (int) $planet['system'], (int) $planet['planet'], (int) $user['id'], 20.0];
        $this->assertGreaterThan(0, $players->createMoon(...$args));
        $this->assertSame(0, $players->createMoon(...$args), 'the creator treats 0 as "planet already has a moon"');
    }

    private function planetManager(): \App\Game\Core\Player\PlanetManager
    {
        return new \App\Game\Core\Player\PlanetManager($this->db, $this->configs, $this->state);
    }

    /** The stay event draws a card (random), reports it to the owner and sends the fleet home or destroys it (black hole / battle). */
    public function testExpeditionEndStayReportsToTheOwner(): void
    {
        [$user, $planet] = $this->createPlayer(['spy_tech' => 5], ['small_ship_cargo' => 50]);
        $now = time();
        $this->db->insert(
            "INSERT INTO %%FLEETS%% (universe, mission, owner, target_owner, ship_amount, ships, start_ships, start_time, arrival_time, stay_time, return_time, start_id, end_id, end_galaxy, end_system, end_planet, end_type, status) VALUES (1, :m, :o, 0, 10, '202,10', '202,10', :t, :t, :t, :r, :p, 0, 1, 1, 16, 1, :s);",
            [':m' => Mission::Expedition->value, ':o' => $user['id'], ':t' => $now - 100, ':r' => $now + 100, ':p' => $planet['id'], ':s' => FLEET_HOLD],
        );
        $fleetId = $this->db->lastInsertId();
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);

        (new \App\Game\Missions\MissionCaseExpedition($fleet, $this->db, $this->configs, $this->state))->EndStayEvent();

        $reports = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%USERS_TO_MESSAGES%% um JOIN %%MESSAGES%% m ON m.message_id = um.message_id WHERE um.user_id = :u AND m.message_type IN (:e, :b);',
            [':u' => $user['id'], ':e' => MessageType::Expedition->value, ':b' => MessageType::BattleReport->value],
            'c',
        );
        $this->assertGreaterThanOrEqual(1, $reports);
    }
}
