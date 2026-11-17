<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\FleetTableController;
use App\Game\Core\FleetLauncher;
use App\Game\Core\Language;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\Mission;

/**
 * ACS group management (invite, rename) belongs to the creator of the group, not to every invited member.
 */
final class AcsManagementTest extends GameTestCase
{
    /** @var list<int> */
    private array $fleetIds = [];

    /** @var list<int> */
    private array $acsIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $lng = new Language($this->configs, 'en');
        $lng->includeData(['L18N', 'INGAME', 'TECH', 'FLEET', 'CUSTOM']);
        $this->state->LNG = $lng;
    }

    protected function tearDown(): void
    {
        foreach ($this->fleetIds as $id) {
            foreach (['FLEETS_EVENT' => 'fleetID', 'LOG_FLEETS' => 'id', 'FLEETS' => 'id'] as $table => $column) {
                $this->db->delete("DELETE FROM %%$table%% WHERE $column = :id;", [':id' => $id]);
            }
        }
        foreach ($this->acsIds as $id) {
            $this->db->delete('DELETE FROM %%USERS_TO_ACS%% WHERE acsID = :id;', [':id' => $id]);
            $this->db->delete('DELETE FROM %%AKS%% WHERE id = :id;', [':id' => $id]);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $target
     * @return array{0: array<string, mixed>, 1: int} sender, fleet id of an outgoing attack that has not arrived
     */
    private function attackFleet(array $target): array
    {
        [$user, $planet] = $this->createPlayer([], ['small_ship_cargo' => 20, 'deuterium' => 10000]);
        $now = $this->state->runtime->timestamp;
        $id = (new FleetLauncher($this->db, $this->configs, $this->state))->sendFleet(
            [202 => 5],
            Mission::Attack->value,
            (int) $user['id'],
            (int) $planet['id'],
            (int) $planet['galaxy'],
            (int) $planet['system'],
            (int) $planet['planet'],
            1,
            (int) $target['id_owner'],
            (int) $target['id'],
            (int) $target['galaxy'],
            (int) $target['system'],
            (int) $target['planet'],
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $now + 3600,
            0,
            $now + 7200,
        );
        $this->fleetIds[] = $id;

        return [$user, $id];
    }

    /**
     * @param array<mixed> $input
     * @param array<string, mixed> $user
     * @return array<string, mixed>|array{}
     */
    private function acsPage(array $user, int $fleetId, array $input): array
    {
        $this->state->USER = $user;
        $this->state->requestInput = $input;
        $controller = (new FleetTableController())->withGameServices($this->db, $this->configs, $this->state);

        return $controller->getACSPageData($fleetId);
    }

    public function testOnlyTheCreatorCanInviteIntoTheGroup(): void
    {
        [, $target] = $this->createPlayer();
        [$creator, $leadFleet] = $this->attackFleet($target);
        [$member] = $this->createPlayer();
        [$stranger] = $this->createPlayer();

        // the creator opens the group and invites the member
        $page = $this->acsPage($creator, $leadFleet, ['username' => $member['username']]);
        $this->assertArrayHasKey((int) $member['id'], $page['invitedUsers']);
        $acsId = (int) $this->db->selectSingle('SELECT `group` FROM %%FLEETS%% WHERE id = :id;', [':id' => $leadFleet], 'group');
        $this->acsIds[] = $acsId;

        // the member's own fleet joined the group (flies later, so it has the higher id)
        [, $memberFleet] = $this->attackFleet($target);
        $this->db->update('UPDATE %%FLEETS%% SET `owner` = :o, `group` = :g WHERE id = :id;', [':o' => $member['id'], ':g' => $acsId, ':id' => $memberFleet]);

        $invited = $this->acsPage($member, $memberFleet, ['username' => $stranger['username']]);

        $this->assertSame([], $invited, 'a member gets no management page');
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_TO_ACS%% WHERE acsID = :a AND userID = :u;', [':a' => $acsId, ':u' => $stranger['id']], 'c'));
    }
}
