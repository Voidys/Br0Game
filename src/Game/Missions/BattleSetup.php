<?php

namespace App\Game\Missions;

use App\Game\BattleEngine\BattleFleet;

/**
 * The fleets and players of one fight, collected by AbstractBattleMission::collectParticipants().
 */
final class BattleSetup
{
    /** @var array<int|string, BattleFleet> attacking fleets by fleet id (destroyed ones are removed after the fight) */
    public array $fleetAttack = [];
    /** @var array<int|string, BattleFleet> defending fleets by fleet id, 0 = the planet itself (destroyed stay fleets are removed after the fight) */
    public array $fleetDefend = [];
    /** @var array<int, string> attacking player id => name */
    public array $userAttack = [];
    /** @var array<int, string> defending player id => name */
    public array $userDefend = [];
    /** @var array<int, mixed> player id => fleet state before the fight (rebuild protection) */
    public array $currentTotalFleetState = [];
    /** @var int|string|null owner of the first attacking fleet */
    public mixed $mainAttacker = null;
}
