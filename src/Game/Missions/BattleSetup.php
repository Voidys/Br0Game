<?php

namespace App\Game\Missions;

/**
 * The fleets and players of one fight, collected by AbstractBattleMission::collectParticipants().
 */
final class BattleSetup
{
    /** @var array<int, array<string, mixed>> attacking fleets: fleet id => player, fleetDetail, unit */
    public array $fleetAttack = [];
    /** @var array<int, array<string, mixed>> defending fleets: fleet id => data, 0 = the planet itself */
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
