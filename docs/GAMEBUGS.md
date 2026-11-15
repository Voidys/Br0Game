# Game bugs

Findings of the game logic review (2026-11-14) plus bugs noticed during other work. Each entry has a stable id, a severity, the evidence (file:line, scenario) and a fix idea; the status column is updated as the list is worked off.

Method: four read-only reviews of the game code (fleets/missions, combat, economy/queues, players/alliances/market/cron) by reading the code path from the request to the database, with SELECT checks against the schema/data and small PHP checks of formulas; plus bugs found by browser-driven end-to-end runs. Confidence is given per entry: *confirmed* (by running or by unambiguous code), *likely*, or *suspicion*.

Severity: **critical** = exploit, duplication or data loss · **high** = wrong results players notice · **medium** · **low**.

GB-78 and GB-80 are regressions from the Symfony port (the move to `src/Game/` and the CLI cron), not bugs of the original pr0game. They are fixed and listed for completeness, but not counted as game bugs (81 game bugs + 2 port regressions).

## Overview

| ID | Severity | Area | Bug | Status |
| --- | --- | --- | --- | --- |
| GB-01 | critical | Fleets and missions | Jump gate: SQL injection through ship amounts (arbitrary column write on own planets) | fixed |
| GB-02 | critical | Combat and reports | Repair dock: stale pre-lock read allows double deploy (ship duplication) | fixed |
| GB-03 | critical | Players, alliances, market, cron | Cleaner deletes every player when `del_user_automatic = 0` | fixed |
| GB-04 | high | Fleets and missions | ACS: joiner's target is never matched to the ACS target (teleport, cheap fuel, protection bypass) | fixed |
| GB-05 | high | Combat and reports | Repair dock: floor() in wreck consumption gives up to 100% repair at any dock level | fixed |
| GB-06 | high | Combat and reports | Repair rate is uncapped: dock lvl >= 18 repairs more than 100% of wrecks | fixed |
| GB-07 | high | Combat and reports | SteemNova engine: checkMemoryUsage() misparses memory_limit (G, lowercase, bytes) and cancels every battle | fixed |
| GB-08 | high | Economy and queues | Queued research is paid from stale planet resources (second queue slot often dropped) | fixed |
| GB-09 | high | Economy and queues | Demolish promoted from the queue gets the build duration (x2.77 too long) | fixed |
| GB-10 | high | Players, alliances, market, cron | Alliance admin overview has no ADMIN right check (any member can rewrite the alliance) | fixed |
| GB-11 | high | Players, alliances, market, cron | Poison marketplace offer breaks the market page for all players | fixed |
| GB-12 | high | Players, alliances, market, cron | Alliance leader can be kicked, and a leader can leave via `mode=close` (alliance without owner) | fixed |
| GB-13 | high | Found during other work | Missile attack crashes (503) — missiles have no element class | fixed |
| GB-14 | medium | Fleets and missions | Jump gate does not require a gate on the source planet (and silo check missing for missile destroy) | fixed |
| GB-15 | medium | Fleets and missions | Hold limits (max fleets / participants) count only status 0 (bound list string) | fixed |
| GB-16 | medium | Fleets and missions | Fleets arriving at a target whose owner entered vacation still act (attack, spy, hold, MIP, destroy) | fixed |
| GB-17 | medium | Fleets and missions | Interplanetary missile fleets can be recalled, which leaves a zombie event | fixed |
| GB-18 | medium | Fleets and missions | Foreign fleets en route to a deleted planet deliver goods/ships into the "destroyed" row | fixed |
| GB-19 | medium | Fleets and missions | AJAX spy/recycle shortcuts ignore the universe | fixed |
| GB-20 | medium | Fleets and missions | ACS arrival delay is unbounded | fixed |
| GB-21 | medium | Combat and reports | Memory-cancel fallback lets a defender make a planet unattackable | fixed |
| GB-22 | medium | Combat and reports | Rust engine: attacker attack value rounded to 1 decimal, defender attack rounded to integer | fixed |
| GB-23 | medium | Combat and reports | calculate_honorpoints_decrease(): int truncates 1.2/1.6 to 1, so the 60% and 80% loot tiers never apply | fixed |
| GB-24 | medium | Economy and queues | Re-timing of the queue after a failed item uses the queue index as level | fixed |
| GB-25 | medium | Economy and queues | Research queue removal compares against the wrong element (can skip a level) | fixed |
| GB-26 | medium | Economy and queues | Shipyard: multiple ship types in one order are not checked against total cost | fixed |
| GB-27 | medium | Economy and queues | Overview delete-planet research migration is inverted and never saved | fixed |
| GB-28 | medium | Economy and queues | Moon resource-cache rebuild zeroes basic income on the shared Config object | fixed |
| GB-29 | medium | Players, alliances, market, cron | Buyer pays but loses resources when the purchase transaction rolls back | fixed |
| GB-30 | medium | Players, alliances, market, cron | Alliance leader transfer accepts any user id | fixed |
| GB-31 | medium | Players, alliances, market, cron | Leaving an alliance keeps the rank id (typo), and leader succession ignores alliance membership | fixed |
| GB-32 | medium | Players, alliances, market, cron | Alliance leader succession picks inactive players | fixed |
| GB-33 | medium | Players, alliances, market, cron | Applying: accept path with full alliance crashes (undefined `$subject`) | fixed |
| GB-34 | medium | Players, alliances, market, cron | Diplomacy: notifications are sent before validation, and `getTargetAlliance($id)` is not scoped to the caller | fixed |
| GB-35 | medium | Players, alliances, market, cron | Notes: any player can overwrite any other player's note | fixed |
| GB-36 | medium | Players, alliances, market, cron | Tickets: reply to a ticket without owning it | fixed |
| GB-37 | medium | Found during other work | Techtree branches cannot be expanded | fixed |
| GB-38 | low-medium | Fleets and missions | Recycling debris read without a lock (double collection race) | fixed |
| GB-39 | low-medium | Combat and reports | Statistics for ACS/hold fleets are incremented once per fleet and with side totals | fixed |
| GB-40 | low-medium | Players, alliances, market, cron | Marketplace: a seller can "buy" their own offer | fixed |
| GB-41 | low-medium | Players, alliances, market, cron | Buddy list: `delete()` sends a false "request rejected" message | fixed |
| GB-42 | low-medium | Players, alliances, market, cron | Messages: `settings_blockPM`, target existence and universe are not enforced on send | fixed |
| GB-43 | low-medium | Players, alliances, market, cron | Daily cron: old battle reports and lower battle-hall entries are never deleted | fixed |
| GB-44 | low-medium | Players, alliances, market, cron | Account deletion/ban: player rows can be missed | fixed |
| GB-45 | low | Fleets and missions | userBashCheck counts group 0 as an "ACS attack" | fixed |
| GB-46 | low | Fleets and missions | Recycling mission available for any debris-less target (`>= 0` always true) | fixed |
| GB-47 | low | Fleets and missions | Moon destruction: redirect of fleets to the main planet is dead code | fixed |
| GB-48 | low | Fleets and missions | Station (deploy) refund recomputed at arrival instead of using fuel_cost | fixed |
| GB-49 | low | Fleets and missions | Step3 lock is effectively a no-op, slot/expedition limits race across planets | fixed |
| GB-50 | low | Fleets and missions | ACS can be created on any fleet, not only attacks; recall treats the group by the leader's times | fixed |
| GB-51 | low | Fleets and missions | Trade parameters not whitelisted at dispatch | fixed |
| GB-52 | low | Combat and reports | Fleet loot rounded per fleet (ACS): small resource creation | fixed |
| GB-53 | low | Combat and reports | Moon destruction: dead/contradictory second redirect, ACS destruction counts only the lead fleet's RIPs | fixed |
| GB-54 | low | Combat and reports | Moon destruction hall-of-fame row has no galaxy | fixed |
| GB-55 | low | Combat and reports | Spy report: unreachable energy class | fixed |
| GB-56 | low | Combat and reports | Spy: moon wreck/repair info inconsistent with attack locking | fixed |
| GB-57 | low | Combat and reports | wreckfield_min_units = 0 can divide by zero | fixed |
| GB-58 | low | Combat and reports | Battle simulator: raw input, no limits on stored reports, crashes on missing slots | fixed |
| GB-59 | low | Combat and reports | Report endpoint: unbounded user comment insert | fixed |
| GB-60 | low | Economy and queues | Shipyard / missiles / one-shot defences: accepted by the server although the UI blocks them | fixed |
| GB-61 | low | Economy and queues | Rebuild protection: owner branch tests the owner's own id in the attacker list | fixed |
| GB-62 | low | Players, alliances, market, cron | Marketplace: no universe check on the offer being bought | fixed |
| GB-63 | low | Players, alliances, market, cron | Stats JSON export: "real" columns are always NULL | fixed |
| GB-64 | low | Players, alliances, market, cron | Overview: abandoning a planet moves the research queue even when the abandon is rejected | fixed |
| GB-65 | low | Players, alliances, market, cron | Missing server-side length validation: planet name, note title, rank names | fixed |
| GB-66 | low | Players, alliances, market, cron | Registration: copying settings from another universe is broken | fixed |
| GB-67 | low | Players, alliances, market, cron | Search autocomplete (planet name) lists owner names | fixed |
| GB-68 | low | Players, alliances, market, cron | Achievement 31 queries do not work | fixed |
| GB-69 | low | Found during other work | Note title longer than varchar(32) after HTML encoding → 503 | fixed |
| GB-70 | low | Found during other work | Admin log detail / message search notices | fixed |
| GB-71 | low | Found during other work | Alliance info for a nonexistent id → 503 | fixed |
| GB-72 | low | Found during other work | Phalanx labels scanned fleets "Your fleets" | fixed |
| GB-73 | low | Found during other work | Alliance application accept/reject shows no confirmation | fixed |
| GB-74 | medium | Found while fixing | Missile attacks on a moon read/updated the parent planet's interceptors | fixed |
| GB-75 | low | Found while fixing | Fleet step 2 loads the target as a planet even for moons | fixed |
| GB-76 | low | Found while fixing | Moon destruction report shows raw sys_* translation keys | fixed |
| GB-77 | low | Found while fixing | Alliance tag/name length not validated server-side | fixed |
| GB-78 | low | Port regression | Game log entries need a web session (CLI processing of missions fails) | fixed (port regression, not a pr0game bug) |
| GB-79 | low | Found while fixing | Resource stock clamp leaves 1 + fraction instead of 0 | fixed |
| GB-80 | high | Port regression | Rust battle engine never loaded (wrong library path) | fixed (port regression, not a pr0game bug) |
| GB-81 | medium | Found while fixing | In-game team page showed a hard-coded team | fixed |
| GB-82 | low | Found while fixing | Closing an alliance left its ranks behind | fixed |
| GB-83 | high | Found while fixing | "Delete unmarked messages" also deleted the marked ones | fixed |

## Fleets and missions

### GB-01 · Jump gate: SQL injection through ship amounts (arbitrary column write on own planets)

- Status: **fixed** — amounts cast to int before min/max, ids cast, non-array input ignored (InformationController); exploit payload now rejected
- Area / files: Controller/Game/InformationController.php:107-121 (sendFleet), Game/Core/HTTP.php:73-97,130-145
- Severity: critical · Confidence: confirmed (reasoning + PHP 8.4 check of the min/max behaviour)
- What happens: `$Ships = _GP('ship', [])` returns quoted STRINGS. `$ShipArray[$Ship] = max(0, min($Ships[$Ship], $PLANET[...]))` is not cast to int. In PHP 8 a non-numeric string compared with an int is compared as string, so `min("0, metal = 99999999999999", 500)` returns the string and `max(0, thatString)` returns it again. `empty()` is false, then it is concatenated into SQL: `$SubQueryOri .= $resource[$Ship] . " = " . $resource[$Ship] . " - " . $ShipArray[$Ship] . ", "`. `_quote` only html-escapes quotes and strips non-ASCII, so letters, digits, commas, `=` and spaces survive.
- Scenario: player owns two moons with jump gates. POST page=information&mode=sendFleet with `jmpto=<other gate moon>` and `ship[204]=0, metal = 99999999999999`. Executed: `UPDATE planets SET light_hunter = light_hunter - 0, metal = 99999999999999, last_jump_time = :t WHERE id = :src` and the same with `+ 0, metal = ...` on the target moon. Any column (resources, building levels, id_owner, destruyed, ...) can be set. `$PLANET[x] -= "0, metal..."` only raises a suppressed warning (leading-numeric string), so it does not abort. I ran `max(0,min($v,500))` in php 8.4: returns the injected string, `empty()` false.
- Even without injection: `ship[204]=0.5` is a string "0.5" that passes, so the SQL is `src - 0.5` (10 becomes 9.5, stored rounded to 10, nothing lost) and `dst + 0.5` (10 becomes 10.5, stored 11). One free ship per jump per cooldown.
- Fix idea: cast `(int) floor(...)` (or whitelist numeric) before min/max, and use bound parameters for the amounts. Same pattern should be checked wherever `_GP('x', [])` arrays are used in SQL.

### GB-04 · ACS: joiner's target is never matched to the ACS target (teleport, cheap fuel, protection bypass)

- Status: **fixed** — ACS joiners must target the ACS target planet (FleetStep3); crafted join rejected
- Area / files: Controller/Game/FleetStep2Controller.php:83-92, FleetStep3Controller.php:197-229, 566-589; Game/Missions/MissionCaseAttack.php:~120-135
- Severity: high (critical in PvP terms) · Confidence: confirmed by reading
- What happens: Step2 resolves `$fleetGroup` from `public_id` only by membership (`USERS_TO_ACS.userID`), Step3 verifies membership/limits and reads `ankunft`, but nowhere compares `aks.target` (planet id) with the chosen target coordinates. getAvailableMissions only needs "some other player's used planet" for IsAKS. The fleet is inserted with `end_id = Y`, `group = G`, mission ACS. At arrival MissionCaseACS only returns the fleet; the leader's MissionCaseAttack pulls every fleet with `group = G` into the battle at X regardless of its `end_id`.
- Scenario: invited participant picks an ACS that targets victim X (galaxy 3), but enters target Y = a neighbour planet in his own system, mission 2. Duration, fuel and noob/vacation/bash/diplomacy checks are all computed for Y (`checkBash($targetPlanetData['id'])`, `checkNoobProtection` against Y's owner). `fleetStartTime = ACSTime` when it is not later, `fleetStayTime = start`, `fleetEndTime = stay + duration(Y)`: the fleet fights at X after a short, cheap flight and comes back after the short Y duration. Protected targets of X (noob, ally, vacation) are only checked against the leader.
- Fix idea: in Step2/Step3 require `aks.target == $targetPlanetData['id']` (and same end type) when `fleetGroup != 0`.

### GB-14 · Jump gate does not require a gate on the source planet (and silo check missing for missile destroy)

- Status: **fixed** — jump gate requires a gate on the source planet (InformationController)
- Area / files: Controller/Game/InformationController.php:58-70, 172-190
- Severity: medium · Confidence: confirmed by reading
- What happens: `sendFleet()` only checks the cooldown of `$PLANET['last_jump_time']` using `getNextJumpWaitTime($last, $PLANET['sprungtor'])`. For level 0 that function returns the 60 minute branch, so a source planet/moon without a gate (level 0, last_jump_time 0) passes. Only the target is checked for `sprungtor > 0`. The UI only offers the gate when `$PLANET[sprungtor] > 0` (show(), line ~360), the server does not.
- Scenario: player with one gated moon POSTs mode=sendFleet from any other planet (non-moon too) with `jmpto=<gated moon>` and teleports ships from every planet to that moon, one jump per 60 min per planet. `destroyMissiles()` likewise has no silo check (harmless).
- Fix idea: require `$PLANET[$resource[43]] > 0` (and planet_type 3 if intended) in sendFleet.

### GB-15 · Hold limits (max fleets / participants) count only status 0 (bound list string)

- Status: **fixed** — hold limits bind both states as separate placeholders (FleetStep3)
- Area / files: Controller/Game/FleetStep3Controller.php:455-486
- Severity: medium · Confidence: confirmed (checked in MySQL: `select 0 in ('0, 2'), 2 in ('0, 2')` returns 1, 0)
- What happens: `':fleetState' => FLEET_OUTWARD . ', ' . FLEET_HOLD` is bound as ONE string, `status in ('0, 2')` equals `status = 0`. Fleets already holding (status 2) are never counted.
- Scenario: `max_fleets_per_acs` / `max_participants_per_acs` only limit fleets still in flight; once the first ones arrived (status HOLD), unlimited further hold fleets/players can be sent to the same planet, stacking defenders.
- Fix idea: `status IN (0,2)` literal or two placeholders.

### GB-16 · Fleets arriving at a target whose owner entered vacation still act (attack, spy, hold, MIP, destroy)

- Status: **fixed** — hostile missions (attack, ACS, spy, missile, moon destruction, hold at another player) return when the target owner is in vacation (MissionFunctions::returnIfTargetInVacation); tested per mission
- Area / files: Game/Missions/* (no `urlaubs_modus` check anywhere, grep empty), Controller/Game/SettingsController.php:280-298 (checkVMode)
- Severity: medium · Confidence: likely
- What happens: vacation mode can be activated whenever the player has no OWN fleets (`owner = userID`); incoming hostile fleets (target_owner = user) and missiles are not checked. Dispatch checks `IsVacationMode($targetPlayerData)`, arrival does not re-check.
- Scenario: attacker launches at X, X sees the attack, activates vacation (no own fleets); at arrival MissionCaseAttack/Spy/MIP/Destruction/Hold execute fully, loot and debris happen on a protected player.
- Fix idea: refuse vacation while foreign hostile fleets (end_id on own planets, mission attack/spy/destroy/missile) are en route, or return the fleet at TargetEvent when the target is in vacation.

### GB-17 · Interplanetary missile fleets can be recalled, which leaves a zombie event

- Status: **fixed** — missile fleets sent with cannotCancel, sendFleetBack refuses mission 10
- Area / files: Controller/Game/FleetMissileController.php (sendFleet call: cannotCancel stays 0), Game/Core/FleetFunctions.php:456-564, Game/Missions/MissionCaseMIP.php:ReturnEvent (empty)
- Severity: medium/low · Confidence: confirmed by reading
- What happens: `sendFleet(..., 0, $primaryTarget)` passes missileTarget but not cannotCancel, so cannotCancel=0. FleetTable hides mission 10 from the list, but `page=fleetTable&action=sendfleetback&fleetID=<id>` only checks owner/cannotCancel/status. After recall status=RETURN; MissionCaseMIP::ReturnEvent does nothing (no RestoreFleet/KillFleet), so the fleet row and event stay with `time <= now` and are claimed again on EVERY request (processFleetEvents) forever; missiles are never restored.
- Scenario: player recalls own missile fleet (id guessable, owner-checked only) to avoid impact after seeing the target react; row stays, per-request event run forever (also counts nowhere in slots as mission != 10). Griefing/performance, plus inconsistent state.
- Fix idea: pass cannotCancel=1 for missiles and/or make sendFleetBack refuse MISSION_MISSILE.

### GB-18 · Foreign fleets en route to a deleted planet deliver goods/ships into the "destroyed" row

- Status: **fixed** — transport/transfer/stay treat a destroyed target as missing; fleet returns with cargo (tested)
- Area / files: Game/Core/PlayerUtil.php:1233-1285 (deletePlanet), Game/Missions/MissionCaseTransport.php:27-40, MissionCaseTransfer.php, MissionCaseStay*.php
- Severity: medium · Confidence: likely
- What happens: deletePlanet sets `destruyed = now+86400` (row stays 24 h) and recalls fleets via `sendFleetBack(['id' => owner of planet], ...)`, which refuses fleets whose `owner != planet owner`. Mission TargetEvents only test `== false`, never `destruyed != 0`; the comment in Transport ("If it is a destroyed moon, avoid StoreGoodsToPlanet") is not implemented. The Overview delete check only blocks own fleets with start_id = planet/moon.
- Scenario: A sends 1M metal transport to B's colony; B abandons the colony meanwhile; transport arrives, `StoreGoodsToPlanet` credits the destroyed row, fleet returns empty; row deleted by CleanerCronjob. Same for Transfer (ships donated into the dead row) and MIP/Attack still act on it.
- Fix idea: in TargetEvents treat `destruyed != 0` like a missing target (return fleet with cargo); make deletePlanet recall foreign fleets too.

### GB-19 · AJAX spy/recycle shortcuts ignore the universe

- Status: **fixed** — universe condition in the AJAX spy/recycle target queries
- Area / files: Controller/Game/FleetAjaxController.php:getTargetData (WHERE p.id = :planetID), recycle branch (`FROM planets WHERE id = :planetID`)
- Severity: medium (multi-universe installs) · Confidence: confirmed by reading
- What happens: unlike Step1-3, no `universe` condition. The universe system exists (Universe::add/remove). Spy sends a probe fleet to a planet id of another universe, the report returns that universe's data; recycle fleets collect debris of another universe.
- Fix idea: add `p.universe = :universe` (and for recycle).

### GB-20 · ACS arrival delay is unbounded

- Status: **fixed** — a joiner may delay an ACS by at most 30 % of its remaining time (tested)
- Area / files: Controller/Game/FleetStep3Controller.php:552-561, FleetFunctions::setACSTime
- Severity: medium/low · Confidence: confirmed by reading
- What happens: a joiner whose own arrival is later pushes the whole ACS (all fleets, events, log rows) back by `fleetStartTime - ACSTime` with no cap (OGame caps at 30% of remaining time). Combined with the target mismatch finding a joiner can pick a far Y and delay everyone indefinitely; a participant can sabotage the attack timing of the leader.
- Fix idea: reject joins that would delay by more than a percentage/absolute limit.

### GB-38 · Recycling debris read without a lock (double collection race)

- Status: **fixed** — debris read FOR UPDATE in recycling missions
- Area / files: Game/Missions/MissionCaseRecycling.php:~43-50, MissionCasePiratebaseRecycling.php
- Severity: low-medium · Confidence: likely
- What happens: `SELECT der_metal, der_crystal ... WHERE id = :planetId` is a plain consistent read, then `der = GREATEST(0, der - collected)`. Events are processed per request in separate transactions (processFleetEvents), two recyclers of different players due in the same second but claimed by different requests both read the full debris and both load it, then GREATEST floors to 0: debris collected twice. Attack uses FOR UPDATE on the planet, recycling does not.
- Fix idea: `FOR UPDATE` on the planet row.

### GB-45 · userBashCheck counts group 0 as an "ACS attack"

- Status: **fixed** — bash check ignores group 0
- Area / files: Game/Core/FleetFunctions.php:779-786 (planetBashCheck has `AND group != 0` at 838, user variant does not)
- Severity: low · Confidence: confirmed by reading
- What happens: `COUNT(DISTINCT group) ... AND group != :fleetGroup` includes solo attacks (group 0) as one distinct group when the current fleet is an ACS fleet. Any recent solo attack on that user adds +1, so ACS joiners hit `bash_per_user` one attack too early.
- Fix idea: add `AND group != 0`.

### GB-46 · Recycling mission available for any debris-less target (`>= 0` always true)

- Status: **fixed** — recycling only offered with debris (> 0)
- Area / files: Game/Core/FleetFunctions.php:632
- Severity: low · Confidence: confirmed
- What happens: `($der_metal + $der_crystal) >= 0 || tf_active == 1` is always true, so Step2/Step3 offer RECYCLING on empty fields (Step3 has no emptiness check, only Step1 JSON/Step2 message do). Wastes fuel, spawns empty reports. Presumably `> 0` intended.

### GB-47 · Moon destruction: redirect of fleets to the main planet is dead code

- Status: **fixed** — dead second redirect removed (same code as GB-53)
- Area / files: Game/Missions/MissionCaseDestruction.php:~395-440
- Severity: low · Confidence: confirmed by reading
- What happens: first UPDATE moves every `start_id = moon` fleet to the parent planet (start_type, start_id), so the second "Redirect fleets from moon to player's main planet" UPDATE (WHERE start_id = :destroyed) matches nothing. Behaviour differs from its comment (parent planet, not main planet; start_galaxy/system/planet keep the same coords, ok). Pick one.

### GB-48 · Station (deploy) refund recomputed at arrival instead of using fuel_cost

- Status: **fixed** — deploy refund uses half of the stored fuel_cost
- Area / files: Game/Missions/MissionCaseStay.php:42-63
- Severity: low · Confidence: confirmed by reading
- What happens: `getFleetConsumption(...)` is recomputed with current techs/honor boost and current game speed, then half is added to the cargo, while the real paid fuel is stored in `fleets.fuel_cost`. Researching fuel/drive tech or a changed honor boost mid-flight changes the refund; can exceed what was paid (e.g. launched while a penalty applied).
- Fix idea: `fuel_cost / 2`.

### GB-49 · Step3 lock is effectively a no-op, slot/expedition limits race across planets

- Status: **fixed** — user row locked first in step 3, slot/expedition counts read after the lock
- Area / files: Controller/Game/FleetStep3Controller.php:66-70, 186-193, 302-310
- Severity: low · Confidence: likely
- What happens: `$pPlanet` (FOR UPDATE) is never used, all checks use the stale `$PLANET`. Safety only comes from UNSIGNED columns making the `ship = ship - n` UPDATE fail (500 rather than duplicate). Slot and expedition counts are read after locking only ONE planet, so two parallel requests from different planets/sessions of the same user both see a free slot and exceed `getMaxFleetSlots` / expedition limit.
- Fix idea: lock the user row first, recheck counts inside.

### GB-50 · ACS can be created on any fleet, not only attacks; recall treats the group by the leader's times

- Status: **fixed** — ACS only for outgoing attacks; group recall uses each fleet's own start time (tested)
- Area / files: Controller/Game/FleetTableController.php:114-140; FleetFunctions.php:495-525
- Severity: low · Confidence: confirmed by reading
- What happens: getACSPageData only checks owner/status/arrival, so transport/hold/expedition fleets get a `group`. Joiners then fly a mission ACS whose battle never happens (leader is not an attack), they just return. When the leader (mission ATTACK) is recalled, all fleets of the group get return_time = now + (now - leader.start_time) though joiners started later, so their return time is wrong.
- Fix idea: restrict to MISSION_ATTACK/status OUTWARD; compute each fleet's recall time from its own start_time.

### GB-51 · Trade parameters not whitelisted at dispatch

- Status: **fixed** — trade parameters whitelisted at dispatch (resEx 1-3, markettype 0/1); crafted dispatch rejected
- Area / files: Controller/Game/FleetStep3Controller.php:76-79, 606-629; FleetStep2Controller::checkResources
- Severity: low · Confidence: confirmed by reading
- What happens: `markettype`, `resEx`, `visibility`, `maxFlightTime` are taken as arbitrary ints. markettype 2+ skips both resource checks but is stored as `transaction_type`; resEx 0/4+ makes an offer that can never be bought (MarketPlace refuses) but locks the fleet (cannotCancel=1) for the full stay. Self-inflicted only, but should be validated server-side.


## Combat and reports

### GB-02 · Repair dock: stale pre-lock read allows double deploy (ship duplication)

- Status: **fixed** — deploy/queue re-read the order from the FOR UPDATE row; three parallel deploys consume the order once (tested)
- Area / files: Controller/Game/RepairdockController.php:49-100 (deploy branch)
- Severity: critical · Confidence: likely (by reading; needs two parallel sessions)
- What happens: `$wreckfield`, `$ElementQueue` and `$ships` are read at line 49, outside any transaction. The deploy branch then opens a transaction and runs `SELECT ... FOR UPDATE` (lines 66-69), but throws the result away. It still adds `$ElementQueue` from the stale read to the planet and clears the order.
- Scenario: a finished order of 500 Cruisers. Two deploy requests (`cmd=deploy`, two sessions/tabs/curl) both read the order before either takes the lock. Request 1 deploys 500 and clears `repair_order`. Request 2 gets the lock, still holds the stale 500 and adds another 500 ships. Net +500 ships for free.
- Fix idea: do the SELECT ... FOR UPDATE first, then read `ships`/`repair_order`/`repair_order_end` from that locked row. Re-check the order and its end time inside the lock. Apply the same to the queue branch.

### GB-05 · Repair dock: floor() in wreck consumption gives up to 100% repair at any dock level

- Status: **fixed** — wrecks consumed with ceil(amount/rate) per repaired order, same helper for display and deploy
- Area / files: Controller/Game/RepairdockController.php:82-90 (and the display copy at 152-160)
- Severity: high · Confidence: confirmed (simulated)
- What happens: the queue amount is `a = floor(s*r)`, but deploy removes only `floor(a/r)` wrecks. Ordering 1 ship per cycle removes `floor(1/r)` wrecks per ship. For r > 0.5 that is 1 wreck per repaired ship. The minimum queue time is only `min_build_time` once 30 min have passed since creation (line ~121), so cycling is cheap.
- Scenario (simulated, 100 wrecks, queue 1 ship per cycle): dock lvl 5 (r=0.352) repairs 49 (fair 35). Lvl 8 repairs 49 (fair 46). Lvl 10 (r=0.556) repairs 98 (fair 55). Lvl 15 repairs 99 (fair 82).
- Fix idea: track repaired fractions, or consume `ceil(a/r)` wrecks. Or disallow more than one order per wreck field (take the whole `floor(s*r)` at once and clear the field).

### GB-06 · Repair rate is uncapped: dock lvl >= 18 repairs more than 100% of wrecks

- Status: **fixed** — repair rate capped at 100 %
- Area / files: Controller/Game/RepairdockController.php:215 `getRepairRate()` = (24 + level^1.5)/100
- Severity: high · Confidence: confirmed (formula; `uni1_vars` maxLevel for 35 is 255)
- What happens: no min(1, ...). Lvl 18 gives 1.0036, lvl 19 gives 1.068, lvl 100 gives 10.24. `maxBuildable = floor(ships*rate)` then exceeds the wreck count, so ships are created from nothing. The `$ships -= floor(amount/rate)` bookkeeping makes it worse.
- Scenario: 1000 wrecked Battleships at dock lvl 19: `floor(1000*1.068)` = 1068 Battleships repairable.
- Fix idea: `min(1.0, ...)`, or a documented max.

### GB-07 · SteemNova engine: checkMemoryUsage() misparses memory_limit (G, lowercase, bytes) and cancels every battle

- Status: **fixed** — memory check uses ini_parse_quantity(), <= 0 = unlimited
- Area / files: BattleEngine/AbstractBattleEngine.php:35-52, used in BattleEngineSteemNova.php (calculateAttack/fight) and MissionCaseAttack::cancelFight
- Severity: high (config dependent) · Confidence: confirmed (php check)
- What happens: only the suffixes `M` and `K` (upper case) are converted. `1G`, `2G`, `512m` and plain byte values stay strings. `memory_get_usage()/"1G"` gives ~2e8 percent, which is > 90, so it returns false. `calculateAttack` returns false and `cancelFight` just returns the fleets.
- Scenario: php.ini `memory_limit=1G` (a common production value) with Rust/Julia unavailable. Every attack, destruction and expedition fight is cancelled with "attack cancelled" and nobody loses anything. The check is still bypassable by design (see next item).
- Fix idea: use `ini_parse_quantity()` (PHP 8.2+) and handle -1 as unlimited.

### GB-21 · Memory-cancel fallback lets a defender make a planet unattackable

- Status: **fixed** — the PHP engine raises memory_limit for the fight (up to 2 GiB) and, if still too large, fights on proportionally scaled unit counts and applies the survival share to the real amounts; Rust/Julia handle large fights natively. Tested with 3M ships in the simulator and a real attack on 1.5M defences (no cancel).
- Area / files: AbstractBattleEngine::checkMemoryUsage, MissionCaseAttack.php:~252 (`cancelFight`), MissionCaseDestruction.php
- Severity: medium · Confidence: likely
- What happens: the PHP engine builds one array per ship/defence (`initCombatValues`). When memory use is > 90% the fight is aborted and the attackers return unharmed.
- Scenario: a defender stacks several hundred thousand cheap defences or ships so the arrays exceed the limit. Every attack is aborted without losses or a report. Only the PHP engine is affected.
- Fix idea: fall back to an aggregated engine, or resolve the fight with a loss, or reject at send time.

### GB-22 · Rust engine: attacker attack value rounded to 1 decimal, defender attack rounded to integer

- Status: **fixed** — Rust engine rounds defender attack like the attacker's
- Area / files: BattleEngine/BattleEngineRust.php:76 vs :96
- Severity: medium · Confidence: confirmed (reading)
- What happens: attackers use `round(attack*(1+0.1*tech), 1)`. Defenders use `round(attack*(1+0.1*tech))` with no precision, and the PHP engine uses unrounded values.
- Scenario: small cargo (attack 5), military tech 1: attacker 5.5, the same ship as a defender 6 (+9%). Solar/recycler (attack 1, tech 1): attacker 1.1, defender 1. Techs 3-9 give similar systematic skews. Defender values are rounded half up while attackers keep the fractions, so defenders get free damage on low-attack units and the other direction is possible.
- Fix idea: use the same rounding for both sides, ideally matching the PHP engine.

### GB-23 · calculate_honorpoints_decrease(): int truncates 1.2/1.6 to 1, so the 60% and 80% loot tiers never apply

- Status: **fixed** — calculate_honorpoints_decrease returns float, 1.2/1.6 tiers apply (tested)
- Area / files: Core/PlayerUtil.php:2266-2294 (declared `: int`)
- Severity: medium · Confidence: confirmed (php test: `function f(): int { return 1.6; }` gives int(1))
- What happens: the factors 1.2 and 1.6 are returned through an `int` return type. Under weak typing they become 1 (the deprecation is hidden by error_reporting). Only 2 survives.
- Scenario: a user at top-20 "bandit" honor (intended 60% loot) is raided at the normal 50% cap; `calculateSteal` multiplies by 1.
- Fix idea: return type `float`.

### GB-39 · Statistics for ACS/hold fleets are incremented once per fleet and with side totals

- Status: **fixed** — ACS/hold stats incremented once per user with the user's own losses (tested with a 2-fleet attack)
- Area / files: BattleEngine/BattleEngineSteemNova.php:~262-275, BattleEngineRust.php (parseRustJson, same block)
- Severity: low-medium · Confidence: confirmed (reading)
- What happens: `foreach ($attackers as $fleetID => $attacker)` / `foreach ($defenders ...)` increment KBMETAL, KBCRYSTAL, DESUNITS and LOSTUNITS per fleet entry. A player with 5 ACS fleets gets 5x, and the planet owner plus their own hold fleets count twice. Each user receives the whole side's `totalLost`, not their own share.
- Fix idea: iterate unique users and use `unitLostPerSideAndUser`.

### GB-52 · Fleet loot rounded per fleet (ACS): small resource creation

- Status: **fixed** — per-fleet loot floored with the remainder to the first fleet; loot sums equal the planet's loss (tested)
- Area / files: BattleEngine/BattleHandler.php:~315-330 (`UPDATE FLEETS ... + '$steal*$slotFactor'`), fleets columns `resource_*` double(50,0)
- Severity: low · Confidence: confirmed
- What happens: the planet loses `floor(steal)` but each fleet column rounds its fractional share (round half up), so total gained can exceed the stolen amount by up to 0.5 per fleet per resource.
- Fix idea: floor each share, give the remainder to the first fleet.

### GB-53 · Moon destruction: dead/contradictory second redirect, ACS destruction counts only the lead fleet's RIPs

- Status: **fixed** — moon destruction chance counts RIPs of all surviving fleets; stats call guarded (tested)
- Area / files: Missions/MissionCaseDestruction.php:~392-445
- Severity: low · Confidence: confirmed (reading)
- What happens: (1) the first `UPDATE FLEETS SET start_id = parentPlanet WHERE start_id = moonId` already moves every fleet; the later "redirect to main planet" `WHERE start_id = moon` can no longer match. (2) `$rips` is taken only from `$fleetAttack[$this->_fleet['id']]` (line ~364). If the lead fleet died in the fight but others survive, `$rips` = 0 with ATTACKER win. If it survives, RIPs of other group fleets are ignored. A later `updateLostAdvancedStats(..., $fleetAttack[id]['unit'])` can receive null when the lead entry was unset.
- Fix idea: sum RIPs over `$fleetAttack`; guard the lead lookup.

### GB-54 · Moon destruction hall-of-fame row has no galaxy

- Status: **fixed** — moon destruction hall-of-fame row stores the galaxy (tested)
- Area / files: Missions/MissionCaseDestruction.php:~600 (INSERT TOPKB without `galaxy`), column `uni1_topkb.galaxy` is NULL-able
- Severity: low · Confidence: confirmed
- What happens: `MissionCaseAttack` inserts `galaxy`, destruction does not, so rows get NULL. The battle hall galaxy filter (`BattleHallFilter::assembleLocationFilter`) never shows them.
- Fix idea: insert `end_galaxy`.

### GB-55 · Spy report: unreachable energy class

- Status: **fixed** — spy energy class thresholds checked from the highest
- Area / files: Missions/MissionCaseSpy.php:~236 `if ($energy > 100000) {midRess} elseif ($energy > 250000) {lowRess}`
- Severity: low · Confidence: confirmed
- What happens: `> 250000` implies `> 100000`, so `lowRess` is never used.
- Fix idea: test the larger threshold first.

### GB-56 · Spy: moon wreck/repair info inconsistent with attack locking

- Status: **fixed** — repair docks are planet-only (element 35, onPlanetType 1), so wrecks of moon battles belong to the parent planet by design; spy report, battle and dock now consistently use the parent planet (comments added).
- Area / files: MissionCaseSpy.php (repair_order read uses `end_id`) vs MissionCaseAttack.php:~72 (moon fights store/lock wreck field under parent planet id)
- Severity: low · Confidence: likely
- What happens: wrecks from moon fights are attached to the parent planet, but the spy report on the moon (`end_id` = moon) looks up the moon id and shows nothing. The repair dock on the moon cannot repair them either.

### GB-57 · wreckfield_min_units = 0 can divide by zero

- Status: **fixed** — both engines guard totalUnits <= 0
- Area / files: BattleEngineSteemNova.php:~232 and BattleEngineRust.php (end of parseRustJson): `$defendingPlayer['lost'] / $defenders[0]['currentTotalFleetState']['totalUnits']`
- Severity: low · Confidence: likely (config 0 and a shipless defender; default is 150000)
- What happens: with `wreckfield_min_units = 0` the `lost < min` guard passes for lost = 0, and `0 / 0` throws DivisionByZeroError, so the whole cron batch fails on the first fight against a shipless planet with repair dock on.
- Fix idea: guard `totalUnits > 0`.

### GB-58 · Battle simulator: raw input, no limits on stored reports, crashes on missing slots

- Status: **fixed** — simulator input sanitised and stored simulator reports limited to 10 per user per minute (tested)
- Area / files: Controller/Game/BattleSimulatorController.php:33-150
- Severity: low · Confidence: confirmed
- What happens: `battleinput` is used unsanitised. `$BattleSlot[0]` amounts are stored as the raw strings and fed to the engine, while limits use `getNumber()`. `$fleetDefend[0]` or `$fleetAttack` can be unset if a slot lacks [0]/[1], giving a TypeError/500. Every request inserts a serialized report into `uni1_raports` with no rate limit (135M element cap but unbounded rows and size).
- Fix idea: cast and clamp amounts/techs, validate slots, rate-limit or expire simulator reports.

### GB-59 · Report endpoint: unbounded user comment insert

- Status: **fixed** — report comments limited to 20 per user per minute, fields capped at 1000 chars (tested)
- Area / files: Controller/Game/ReportController.php:30-44
- Severity: low · Confidence: confirmed
- What happens: any logged-in user can insert unlimited `USERS_COMMENTS` rows with arbitrary length, with no throttling.


## Economy and queues

### GB-08 · Queued research is paid from stale planet resources (second queue slot often dropped)

- Status: **fixed** — queued research advances the planet to each end time before the next starts (ResourceUpdate); tested
- Area / files: src/Game/Core/ResourceUpdate.php:728-776 (`CheckUserTechQueue`) with 778-823 (`SetNextQueueTechOnTop`), and CalcResource loop 124-135
- Severity: high · Confidence: likely (by reading; not executed)
- What happens: when a research finishes, `CheckUserTechQueue` only calls `UpdateResource($BuildEndTime)` for the techs 113/131/132/133. For every other tech the planet still holds resources as of `last_update` (the last visit). `SetNextQueueTechOnTop` then checks `isElementBuyable` against those stale resources, before production up to the end time is added. Building queues do not have this problem, because `CheckPlanetBuildingQueue` always calls `UpdateResource($BuildEndTime)`.
- Scenario: the player queues research A and B, then goes offline. At the last visit the planet had 10k metal, B costs 500k, and production would have covered it by the time A ended. On return, B is judged against 10k, fails, is removed with the "not enough resources" message, and the queue is left empty. This universe has `max_elements_tech = 2`, so it hits every two-slot queue. It only works correctly when the research planet differs from the current one, because that path calls `CalcResource(..., b_tech)` for that planet.
- Fix idea: in `CheckUserTechQueue`, always call `$this->UpdateResource($BuildEndTime, !in_array(...))` for the current planet before the next item starts, as the building queue does.

### GB-09 · Demolish promoted from the queue gets the build duration (x2.77 too long)

- Status: **fixed** — demolish promoted from the queue keeps its destroy time; tested
- Area / files: ResourceUpdate.php:606
- Severity: high · Confidence: confirmed
- What happens: `getBuildingTime($this->USER, $this->PLANET, $Element, $costResources)` is called without `$ForDestroy`. The start path (BuildingsController:185) and the queue-add path (214) pass the destroy flag. Without it the time is `floor($time*3600)` on the halved cost instead of `floor($time*1300)`.
- Scenario: queue [build X, destroy Y]. The UI shows Y as e.g. 1h 20m. When X ends, Y starts with `$BuildTime` about 3.6h/1.3h = 2.77 times longer, and `b_building` is set to that.
- Fix idea: pass `$ForDestroy` as the 5th argument.

### GB-24 · Re-timing of the queue after a failed item uses the queue index as level

- Status: **fixed** — queue re-timing passes the element level; tested with a failed middle entry
- Area / files: ResourceUpdate.php:705-712
- Severity: medium · Confidence: confirmed
- What happens: the call is `getBuildingTime($USER, $PLANET, $ListIDArray[0], null, destroy?, $ID)`. The 6th parameter is `forLevel`, but `$ID` is the array index (0, 1, 2...). The price is therefore computed for "level 0/1/2", giving tiny times. The same pattern appears in ResearchController:211 and ResourceUpdate:909, which ignore the stored level.
- Scenario: queue [A (unaffordable), B (running), C, D]. After A is dropped, C and D get stored end times of "base + a few seconds". `getQueueData` skips entries whose end time is in the past (BuildingsController:236 / ResearchController:329), so C and D disappear from the queue UI while they are still queued. `addBuildingToQueue` also chains new end times from the wrong last entry.
- Fix idea: pass `$ListIDArray[1]` as `forLevel` and the index as the 7th parameter (`maxQueueIndex`).

### GB-25 · Research queue removal compares against the wrong element (can skip a level)

- Status: **fixed** — research removal compares against the removed element; stale remove no longer cancels the running job; tested
- Area / files: ResearchController.php:186-196
- Severity: medium · Confidence: confirmed by reading. Needs `max_elements_tech` of 3 or more (this DB has 2).
- What happens: `$elementId` is the element of the item before the removed one (`QueueID-2`). The loop drops later items that share that element. It should compare against the removed item's element.
- Scenario: queue [A lvl5 running, B lvl3, B lvl4]. Remove B lvl3 (`QueueID=2`). B lvl4 is kept because `A != B`. When it starts, the player pays the level-4 cost and `CheckUserTechQueue` sets `B = 4`, so level 3 was skipped at no cost. In the opposite case, [B3 running, C, B4]: removing C wrongly drops B4.
- Fix idea: capture the removed item's element before `unset` and compare against that.

### GB-26 · Shipyard: multiple ship types in one order are not checked against total cost

- Status: **fixed** — shipyard orders deduct cost per entry from a working copy and check the queue limit per entry; tested
- Area / files: src/Controller/Game/ShipyardController.php:102-155, 195-198
- Severity: medium · Confidence: confirmed by reading. Outcome is a SQL error under strict mode.
- What happens: `$MaxElements` for each element is computed from the full planet resources. `$PLANET` is not decremented between elements, and `removeResources` runs once at the end (155) with the summed cost.
- Scenario: planet with 100k metal and 100k crystal. POST `fmenge[202]=max` and `fmenge[203]=max` (each affordable alone). Total cost is about 2x the stock. `UPDATE planets SET metal = metal - X` hits a `double(50,6) unsigned` column under `STRICT_ALL_TABLES` (Database::INIT_COMMAND) and fails with error 1264, so the player gets a 500 and the whole order is lost. If the column or sql_mode ever accepted negatives, this would duplicate ships.
- Related: `max_elements_ships` is only checked once (before the lock, line 197) against the existing queue length. One POST with N types adds N entries, so the limit is bypassed.
- Fix idea: decrement a working copy of the resources per element, and re-check queue length per added entry.

### GB-27 · Overview delete-planet research migration is inverted and never saved

- Status: **fixed** — abandoned planet's research queue re-homed to the main planet and saved; tested
- Area / files: src/Controller/Game/OverviewController.php:354-367
- Severity: medium · Confidence: likely
- What happens: the loop keeps only entries whose planet equals the abandoned planet (re-homed to the main planet) and drops entries queued on other planets. The comment and intent are the opposite. The modified `$USER['b_tech_*']` is never written to the DB; no `saveTechQueue` or update follows before `sendJSON` ends the request.
- Scenario: research running on colony P (`b_tech_planet = P`), the player abandons P. `b_tech_planet` still points to P (`destruyed` set, row deleted by CleanerCronjob after 24h). Cancelling the running research refunds to a dead row (`addResources` updates 0 rows, so the cost is lost). Queued items still carrying P are started via `SetNextQueueTechOnTop` against a destroyed or deleted planet: payment from the abandoned planet's resources, or a `false` planet passed to `getElementPrice(array ...)` once the row is gone.
- Fix idea: keep items for other planets, re-home items for P to the main planet, persist with `saveTechQueue`, and cancel or refund properly.

### GB-28 · Moon resource-cache rebuild zeroes basic income on the shared Config object

- Status: **fixed** — moon cache rebuild no longer mutates basic income on the shared config
- Area / files: ResourceUpdate.php:273-277
- Severity: medium · Confidence: confirmed by reading
- What happens: `ReBuildCache` for a moon does `$this->config->metal_basic_income = 0` (and crystal and deuterium). `Config::__set` changes the single per-request instance from ConfigRegistry and records the key in `updateRecords`.
- Scenario (a): in the same request or process, any planet handled after a moon rebuild (a fleet event touching a moon and then planets, or the cron) gets `ExecCalc` with `basic_income = 0`, so it loses its basic income for the whole elapsed interval.
- Scenario (b): any later `$config->save()` in that request, for example Statbuilder/PlayerUtil registration (`PlayerUtil:622`), persists `metal_basic_income=0`, `crystal_basic_income=0`, `deuterium_basic_income=0` for the universe permanently (the keys are universe keys).
- Fix idea: do not mutate config. Skip basic income by planet type inside `ExecCalc` and `CreateHash`.

### GB-60 · Shipyard / missiles / one-shot defences: accepted by the server although the UI blocks them

- Status: **fixed** — owned one-shot defences skipped, missile capacity counted once per order; tested
- Area / files: ShipyardController.php:112-139
- Severity: low · Confidence: confirmed
- What happens (a): for items in `reslist['one']` (shield domes) that are already owned, `getMaxConstructibleElements` returns 1 (`BuildFunctions:301`). The `Count=1` override at 136 only fires when the owned count is 0, so `Count` stays 1 and the order is accepted and charged. `SavePlanetToDB` sets the column to '1' (not additive), so the resources are wasted.
- What happens (b): for missiles, the just-queued units are counted twice. They are in `$Missiles[$Element] += $Count` and also in `b_hangar_id`, which `getMaxConstructibleRockets` re-adds. The second missile type in one POST gets less capacity than it should.

### GB-61 · Rebuild protection: owner branch tests the owner's own id in the attacker list

- Status: **fixed** — rebuild protection owner branch uses the target's id
- Area / files: src/Game/Core/PlayerUtil.php:2733-2737 (`checkNoobProtection`)
- Severity: low · Confidence: suspicion (likely copy-paste)
- What happens: `in_array($OwnerPlayer['id'], $attackers)` where `$attackers` is the owner's own `rebuild_protection_attackers` (people who attacked the owner). The target branch correctly checks `$OwnerPlayer['id']` against the target's list. The owner branch should check `$TargetPlayer['id']`. As written, the 6h vs 3h revenge distinction for a protected player is almost never applied. It only triggers if the owner appeared in his own attacker list.
- Fix idea: use `$TargetPlayer['id']`.


## Players, alliances, market, cron

### GB-03 · Cleaner deletes every player when `del_user_automatic = 0`

- Status: **fixed** — del_user_automatic = 0 compares against 0 (nobody matches); cleaner run for real: no deletions when disabled, only the inactive test player when enabled
- Area / files: src/Game/Cronjob/CleanerCronjob.php:36-40 vs 102-110
- Severity: critical · Confidence: confirmed by reading
- What happens: the "0 = disabled" guard (`if ($del_inactive === timestamp) $del_inactive = 2147483647`, lines 39-41) is applied to a variable that is never used. The per-universe loop recomputes `$del_inactive = now - del_user_automatic*86400` without the guard. The setting is documented as "0 = disabled" (translations/admin.en.yaml:737, ContentPages.php:204) and allowed by `min => 0`. With 0, the query `onlinetime < now` matches every non-admin player in every non-REG_ONLY universe and `deletePlayer` runs for all of them each cron run.
- Scenario: an admin sets "Delete inactive after" to 0 to disable it; the next cleaner run deletes all players.
- Fix idea: skip the `onlinetime` condition when `del_user_automatic` is 0 (and similar for `del_user_manually`) inside the loop.

### GB-10 · Alliance admin overview has no ADMIN right check (any member can rewrite the alliance)

- Status: **fixed** — alliance settings require membership and the ADMIN right (tested)
- Area / files: src/Controller/Game/AllianceController.php:1201-1360 (`admin()`, `adminOverview()`); `'ADMIN'` only appears in `$availableRanks` (line 52)
- Severity: high · Confidence: confirmed (grep: `rights['ADMIN']` is never read anywhere)
- What happens: `admin()` dispatches `?page=alliance&mode=admin&action=overview` to `adminOverview()`. That method checks no right and not even `hasAlliance`. With `send=1` it runs an UPDATE of ally_tag, ally_name, ally_owner_range, image, web, request_notallow, max_members, request_min_points, texts, ally_stats and ally_diplo.
- Scenario: a player gets accepted into an alliance (rank 0, no rights), POSTs `page=alliance&mode=admin&action=overview&send=1&ally_name=X&ally_tag=Y&request_notallow=1&text=...`. The alliance is renamed, recruiting is closed or the min-points requirement is changed, and the descriptions are replaced. Every other admin sub-action has a right check, so only this one is open.
- Fix idea: `if (!$this->hasAlliance || !$this->rights['ADMIN']) redirectToHome();` at the top of `adminOverview()`.

### GB-11 · Poison marketplace offer breaks the market page for all players

- Status: **fixed** — market tolerates offers with an invalid resource type (not buyable); dispatch validation in GB-51
- Area / files: src/Controller/Game/FleetStep3Controller.php:76,606-626 (no validation of `resEx`), src/Controller/Game/MarketPlaceController.php:652,787 (`checkPush` in `show()`), src/Game/Core/MarketManager.php:395-411
- Severity: high · Confidence: confirmed by reading
- What happens: FleetStep3 stores `ex_resource_type = (int) resEx` unchecked. The checks in FleetStep2 are only an AJAX pre-check; a crafted POST to fleetStep3 skips them. `show()` calls `checkPush()` for every offer. `checkPush` calls `convertExpResTypeToDeut($type, ...)`, which throws `Exception('unexpected resource')` for any type outside 1-3. `show()` has no try/catch around that loop. The safe `$askType >= 1 && <= 3` guard at line 806 comes after the throwing call.
- Scenario: send a fleet with cargo to the market planet with `mission=TRADE&resEx=0` (the default when the field is missing) or `resEx=9`. Once it arrives and holds (6 h), every other player opening the market page gets an exception.
- Fix idea: validate `resEx in {1,2,3}` and `markettype in {0,1}` in FleetStep3, and make `checkPush` and `convertExpResTypeToDeut` safe for invalid types.
- Related (low): FleetStep3 does not repeat the FleetStep2 checks (only one cargo resource, wanted resource differs from offered resource, visibility range). An offer with metal and crystal stores only the first type in `resource_amount`. `MissionCaseTrade::TargetEvent` hands the buyer only that one type, so the rest vanishes.

### GB-12 · Alliance leader can be kicked, and a leader can leave via `mode=close` (alliance without owner)

- Status: **fixed** — owner cannot leave via close; kick rejects the owner, oneself and higher-ranked members
- Area / files: src/Controller/Game/AllianceController.php:1974-2012 (`adminMembersKick`), 1075-1127 (`close`)
- Severity: high (kick) / medium (leave) · Confidence: confirmed by reading
- What happens: `adminMembersKick` needs only the `KICK` right. It does not exclude `ally_owner` or members with higher rank. The founder gets `ally_id = 0`, `ALLIANCE.ally_owner` still points to him, and nobody has the owner rights any more (rights are checked as `ally_owner == USER['id']` AND `USER['ally_id'] == alliance`). `adminClose` and `adminTransfer` are owner-only, so the alliance can never be deleted or transferred. The hidden "leave" button in the template only hides it for the owner (home template line 131), `close()` itself has no owner check.
- Scenario: a member with a KICK rank kicks the founder, or the founder calls `game?page=alliance&mode=close`. The alliance stays with a stale owner id and no one can manage it.
- Fix idea: reject kicking `ally_owner` and users with ranks above your own; in `close()` force a transfer or alliance deletion for the owner.

### GB-29 · Buyer pays but loses resources when the purchase transaction rolls back

- Status: **fixed** — buyer's planet restored after a failed purchase (tested with a forced failure)
- Area / files: src/Controller/Game/MarketPlaceController.php:431-433 and 683-692
- Severity: medium · Confidence: likely
- What happens: `doBuy()` subtracts the payment and fuel from the in-memory `$PLANET` before `sendFleet`. If a later query throws (the comment names deadlock or FOR UPDATE contention), `show()` catches `\Throwable`, rolls back the DB and shows "purchase failed". `$PLANET` still holds the reduced values, and the normal planet save at the end of the request writes them. The DB changes are undone but the resource loss is persisted.
- Scenario: two buyers click the same offer. One gets a lock wait or deadlock error and ends up with payment gone and no fleet.
- Fix idea: work on a copy of `$PLANET` and assign it back after commit, or reload the planet after rollback.

### GB-30 · Alliance leader transfer accepts any user id

- Status: **fixed** — leader transfer only to alliance members with TRANSFER right (tested)
- Area / files: src/Controller/Game/AllianceController.php:1413-1470 (`adminTransfer`)
- Severity: medium · Confidence: confirmed by reading
- What happens: `newleader` is not checked to be a member of this alliance or to hold the TRANSFER right (that filter is only used for the dropdown). The code reads `ally_rank_id` of any user, copies it to the old owner, sets the target's rank to 0, and sets `ally_owner` to the target.
- Scenario: owner POSTs `newleader=<id of a non-member or non-existent user>`. The alliance gets an owner outside it (or id 0/unknown, `$Rank['ally_rank_id']` on false), and the old owner receives a foreign `ally_rank_id`.
- Fix idea: require `ally_id` of target equals own alliance, TRANSFER right, and not self.

### GB-31 · Leaving an alliance keeps the rank id (typo), and leader succession ignores alliance membership

- Status: **fixed** — leaving resets ally_rank_id; succession scoped to the alliance
- Area / files: src/Controller/Game/AllianceController.php:1104 (`ally_register_time = 0, ally_register_time = 5`), src/Game/Core/PlayerUtil.php:905-917 (`removeFromAlliance`)
- Severity: medium · Confidence: confirmed (typo) / likely (impact)
- What happens: `close()` sets `ally_register_time` twice (second assignment wins: 5) instead of `ally_rank_id = 0`. A leaving member keeps their old `ally_rank_id`. The query that picks the new leader when the owner is removed (`JOIN USERS u ON u.ally_rank_id = r.rankID`) has no `u.ally_id = :allianceId`, so an ex-member who still carries a rank id of that alliance can become its new leader without being in it. The same join excludes members with `ally_rank_id = 0`, so an alliance whose remaining members have no rank gets no new leader (stays owned by the deleted user).
- Scenario: owner is deleted or goes inactive; ex-member with a leftover rank (or nobody at all) is chosen.
- Fix idea: reset rank in `close()`, add `u.ally_id = r.allianceID` to both succession queries and fall back to any member.

### GB-32 · Alliance leader succession picks inactive players

- Status: **fixed** — succession picks the most recently active member
- Area / files: src/Game/Core/PlayerUtil.php:909-914
- Severity: medium · Confidence: likely
- What happens: the "respect rank settings" query uses `u.onlinetime < now - 72h`, which selects only members inactive for 72 h and orders them by `onlinetime DESC`. It looks inverted (`>` intended). Active members with rights are skipped; the fallback query then takes any ranked member.
- Fix idea: use `>` or drop the condition.

### GB-33 · Applying: accept path with full alliance crashes (undefined `$subject`)

- Status: **fixed** — accepting into a full alliance no longer crashes; request removed
- Area / files: src/Controller/Game/AllianceController.php:1649-1690 (`adminSendAnswerToApply`)
- Severity: medium · Confidence: confirmed by reading
- What happens: when the alliance is full the code rolls back, sets `$answer = 'no'` and `$text`, but never defines `$subject` (it is only set in the accepted branch and in the `else` of the outer `if`). `sendMessage(string $subject, ...)` receives null, which throws a TypeError for a user-defined function. The pending application is also not removed, so the recipient is never told.
- Scenario: alliance at `ally_max_members`, manager accepts an application, gets an error page.
- Fix idea: set `$subject` in the full branch.

### GB-34 · Diplomacy: notifications are sent before validation, and `getTargetAlliance($id)` is not scoped to the caller

- Status: **fixed** — diplomacy: target scoped to the caller's pacts, validation before messages, level whitelisted, delete notice typo fixed (tested end to end)
- Area / files: src/Controller/Game/AllianceController.php:2075-2150, 2156-2205, 2207-2290, 2460-2490
- Severity: medium · Confidence: confirmed by reading
- What happens: (1) `getTargetAlliance($id)` without `ally_id` selects `DIPLO d JOIN ALLIANCE a ON a.id IN (owner_1, owner_2) WHERE d.id = :id AND a.id != :myAlliance` and does not require the pact to involve the caller. A diplomat can pass any diplo id; the DELETE/UPDATE statements are scoped, but the messages (accept, reject, delete, "war ended") are built from other alliances and sent to their members. (2) In `adminDiplomacyAccept` the messages go out before the PEACE `canAccept` check and before the UPDATE (which only affects `owner_2 = me`). An initiator can "accept" their own request and send "pact accepted" to both alliances without anything changing. (3) In `adminDiplomacyDelete` the accepted-pact branch passes `$targetAlliance['diplo'] == $receiver['lang']['al_diplo_delete']` (a `==` typo for `,`) as subject and the sprintf format, so the message has an empty subject and text (line ~2260). (4) `level` in `adminDiplomacyCreateProcessor` is not validated (any int is stored; `al_diplo_level[$level]` may be undefined).
- Fix idea: scope by `owner_1 = me OR owner_2 = me`, validate and perform the change first, then send messages; fix the `==`; whitelist levels.

### GB-35 · Notes: any player can overwrite any other player's note

- Status: **fixed** — notes update requires ownership
- Area / files: src/Controller/Game/NotesController.php:126
- Severity: medium · Confidence: confirmed
- What happens: `UPDATE NOTES SET ... WHERE id = :noteID` has no `owner = :userID` (the SELECT in `detail()` and DELETE do). POST `page=notes&mode=insert&id=<victim note id>` replaces title/text/priority/time.
- Fix idea: add `AND owner = :userID`.

### GB-36 · Tickets: reply to a ticket without owning it

- Status: **fixed** — ticket replies require ownership
- Area / files: src/Controller/Game/TicketController.php:110-124
- Severity: medium · Confidence: confirmed
- What happens: `send()` with `id` selects the ticket by `ticketID` only. Any player can append an answer to any other player's ticket (spoofed as that conversation), including debug-category tickets (hidden in `view()`). A non-existent id gives `$ticket = false` and still calls `createAnswer`.
- Fix idea: require `ownerID = :userID` and category != 9.

### GB-40 · Marketplace: a seller can "buy" their own offer

- Status: **fixed** — own offers cannot be bought; reference ratios ignore self-trades
- Area / files: src/Controller/Game/MarketPlaceController.php:287-349 (doBuy), 782 (only the UI blocks `$buyer == $seller`)
- Severity: low-medium · Confidence: confirmed by reading
- What happens: `doBuy()` never rejects `$USER['id'] == owner`. The diplomacy check is skipped for the owner, and `isPush` with equal scores only requires `ask >= 0.7 * offer`. A seller can cancel a listed offer (hold fleets have `cannotCancel = 1`) just by paying the fuel. Self-buys also write `TRADES` rows (`buy_time`), which feed the 7-day `getMetSales`/`getCrysSales`/`getDeutSales` volumes that set the reference ratios.
- Scenario: wash-trade your own offers at extreme ratios (metal for 1 deut) to push `metDeutRatio` towards the clamp 0.5 or 5. The ratios are then used by `isPush` for every player's push check.
- Fix idea: reject `owner == buyer` in `doBuy()` and exclude same-user trades from the ratio queries.

### GB-41 · Buddy list: `delete()` sends a false "request rejected" message

- Status: **fixed** — buddy delete binds the id correctly; no false rejection message
- Area / files: src/Controller/Game/BuddyListController.php:149-151
- Severity: low-medium · Confidence: confirmed
- What happens: `SELECT COUNT(*) FROM BUDDY_REQUEST WHERE :id;` binds the id as a bare condition, which is true for any non-zero id and counts all rows. If any buddy request exists anywhere, `$isRequest` is true even when deleting an accepted friendship or cancelling your own request. The other party gets a "request rejected" message every time. Also `request()/send()` do not check the target exists or is in the same universe (`$row['lang']` on false then `new Language`).
- Fix idea: `WHERE id = :id`, validate the target user and universe.

### GB-42 · Messages: `settings_blockPM`, target existence and universe are not enforced on send

- Status: **fixed** — message send/forward check target existence, universe and the block setting; recipients are stored per user in users_to_messages, and deleting a player now removes only that player's copies (shared messages stay for the other recipients)
- Area / files: src/Controller/Game/MessagesController.php:350-410, 304-345; src/Game/Core/PlayerUtil.php:1407-1535
- Severity: low-medium · Confidence: confirmed by reading
- What happens: only the recipient search (`loadUser`) filters `settings_blockPM = 0`. `send()` and `sendForward()` accept any `to[]` ids, check only the per-user blocklist, not PM blocking, not existence, not universe. A crafted POST messages players who disabled PMs or users in other universes.
- Related (low): `MESSAGES.message_owner` stores only `$userId[0]`; `deletePlayer` deletes messages by `message_owner`, which removes shared rows for the other recipients and the sender's copy of a message to that user.

### GB-43 · Daily cron: old battle reports and lower battle-hall entries are never deleted

- Status: **fixed** — daily cron deletes old reports and lower battle-hall entries (tested)
- Area / files: src/Game/Cronjob/DailyCronjob.php:~170-196 (`cleanReports`)
- Severity: low-medium · Confidence: confirmed by reading
- What happens: `$del_before` is already `now - del_oldstuff*86400`, but the queries bind `':oldStuff' => timestamp - $del_before`, which equals `del_oldstuff*86400` (~2.6M, i.e. 1970). `time < :oldStuff` is never true, so `RW` rows with `defender != ''` and the TOPKB trimming never delete anything. Only the 72 h clause works. Storage grows without bound.
- Fix idea: bind `$del_before` directly.

### GB-44 · Account deletion/ban: player rows can be missed

- Status: **fixed** — get_player_universes condition moved into the join, banAccount uses the player's universe
- Area / files: src/Game/Core/AccountUtil.php:183-195 (`get_player_universes`), 277-340 (`banAccount`)
- Severity: low-medium · Confidence: confirmed by reading
- What happens: (a) `get_player_universes` has `LEFT JOIN statpoints ... WHERE s.stat_type = 1`, which makes it an inner join. A player without a stat row is not returned, so `deleteAccount` deletes the account but not that player. (b) `banAccount` updates `USERS SET bana = 1 ... AND universe = getEmulated()` instead of `$user['universe']`; in an account with players in several universes only the one in the current/emulated universe is flagged banned (USERS_BANNED row is written for all). For the automatic forbidden-message ban this means the ban misses other universes.
- Fix idea: move the stat condition into the JOIN; use `$user['universe']`.

### GB-62 · Marketplace: no universe check on the offer being bought

- Status: **fixed** — market buy filtered by universe
- Area / files: src/Controller/Game/MarketPlaceController.php:309-316
- Severity: low · Confidence: confirmed by reading
- What happens: the SELECT filters `f.id` and `status`, but not `f.universe = current universe`. The list in `show()` is filtered by universe, but `fleetID` can be any id. In a multi-universe setup a user can buy offers from another universe through a crafted POST.
- Fix idea: add `AND f.universe = :universe`.

### GB-63 · Stats JSON export: "real" columns are always NULL

- Status: **fixed** — stats JSON join aliases fixed
- Area / files: src/Game/Core/Statbuilder.php (archiveStats / JSON export), joins `admr`, `adkr`, `addur`, `adlur`
- Severity: low · Confidence: confirmed by reading
- What happens: `LEFT JOIN ... admr ON adm.userId = u.id AND admr.timestamp = ... AND adm.statkey = :kbmetalr` uses the alias of the other join (`adm`, `adk`, `addu`, `adlu`) instead of its own. `adm.statkey` is `KBMETAL`, so `= KBMETAL_REAL` is never true. realDebrisMetal, realDebrisCrystal, realUnitsDestroyed and realUnitsLost are always null in the JSON.
- Fix idea: use the joined alias in those three conditions.

### GB-64 · Overview: abandoning a planet moves the research queue even when the abandon is rejected

- Status: **fixed** — queue rewrite only after a successful abandon; tested with the wrong-password path
- Area / files: src/Controller/Game/OverviewController.php:347-357
- Severity: low · Confidence: confirmed
- What happens: the tech-queue rewrite (`b_tech_planet`, `ListIDArray[4]`) runs before the checks for running fleets, main planet and name. If `$password != $PLANET['name']` or fleets exist, the user data in memory (saved afterwards) already has the queue moved to the home planet. The loop also keeps only entries with `[4] == $PLANET['id']` and drops the rest.
- Fix idea: do it only in the success branch.

### GB-65 · Missing server-side length validation: planet name, note title, rank names

- Status: **fixed** — note titles cut to 32 chars after encoding, rank names validated; planet names see economy fix
- Area / files: src/Controller/Game/OverviewController.php:316-335, NotesController.php:102-124, AllianceController.php:1760-1800
- Severity: low · Confidence: confirmed (schema checked)
- What happens: `PLANETS.name` is varchar(20), `NOTES.title` varchar(32); only HTML `maxlength` limits them. With STRICT_TRANS_TABLES a longer value throws "Data too long" (500). Rank names are validated on create but not on edit (`$rowData['rankName']`).

### GB-66 · Registration: copying settings from another universe is broken

- Status: **fixed** — registration settings copy works (tested with a second universe)
- Area / files: src/Controller/Account/RegisterController.php:128-143; src/Game/Core/PlayerUtil.php:~455-520
- Severity: low · Confidence: confirmed by reading
- What happens: the lookup selects `u.uni` but reads column `'id'`, so `$colorSettingsUni` etc. are null (the copy is silently skipped). If it did return a value, the `UPDATE ... dest SET ... FROM (...)` is not valid MySQL, uses `u.user_id` (users has no such column), lacks the `:userId` parameter, and the colors statement has a trailing comma before FROM.
- Fix idea: read `uni`, rewrite as a MySQL multi-table UPDATE with the missing parameter.

### GB-67 · Search autocomplete (planet name) lists owner names

- Status: **fixed** — planet autocomplete shows planet names
- Area / files: src/Controller/Game/SearchController.php (autocomplete, `case 'planetname'`)
- Severity: low · Confidence: confirmed
- What happens: label uses `$data['username']` instead of `$data['planetname']`, so suggestions for planet names show owner names.

### GB-68 · Achievement 31 queries do not work

- Status: **fixed** — achievement 31 queries run (CONCAT, quoted time)
- Area / files: src/Game/Achievements/PlayerUtilAchievement.php:~160-200
- Severity: low · Confidence: confirmed by reading
- What happens: `defender LIKE '%,:user'` and `AND 'time' > :sixHours` have the placeholders inside quotes (not bound by PDO) and `'time'` as a string literal. First query never matches; second only matches single-attacker rows. Achievement 31 is mostly never granted.


## Found during other work

### GB-13 · Missile attack crashes (503) — missiles have no element class

- Status: **fixed** — migration_1794787211 sets class 500 for 502/503 (+install.sql), int cast in MissionCaseMIP; planet and moon missile attacks tested
- Files: install/install.sql (uni1_vars rows 502, 503: class NULL/0), src/Game/Cache/VarsBuildCache (reslist['missile'] empty), src/Game/Missions/MissionCaseMIP.php:140
- Severity: high · Confidence: confirmed (e2e run, 503 on missile arrival)
- What happens: `reslist['missile']` stays empty, the interceptor column is never selected, `calculateMIPAttack(..., $defenseMissles)` receives null → TypeError → 503 for the page that processes the arrival.
- Fix idea: migration setting class = 500 for elements 502/503 (+ install.sql), defensive `(int)` cast at the call.

### GB-37 · Techtree branches cannot be expanded

- Status: **fixed** — techtree branches toggle again (onPageInit handler + CSS); tested in Chromium
- Files: templates/game/page.techTree.default.html.twig (.techb buttons, .techi hidden), no JS handler in public/scripts
- Severity: medium (UI, page unusable) · Confidence: confirmed (no click handler exists; probably lost with the jQuery removal)
- Fix idea: small ES-module/inline onPageInit handler toggling the `hidden` state of the branch.

### GB-69 · Note title longer than varchar(32) after HTML encoding → 503

- Status: **fixed** — covered by the note title cut
- Files: notes table (title varchar(32)), src/Controller/Game/NotesController.php
- Severity: low · Confidence: confirmed (e2e run)
- What happens: input is HTML-encoded by `_GP` before insert, so `&` → `&amp;` grows the string; the DB rejects it (strict mode) → exception.
- Fix idea: validate/trim length after encoding (mb_substr) or widen the column.

### GB-70 · Admin log detail / message search notices

- Status: **fixed** — admin log detail handles flat data/missing id; admin search ignores unknown types
- Files: src/Controller/Admin/LogController.php (type=detail, undefined array key), src/Controller/Admin/SearchController.php (search=messages, undefined variable)
- Severity: low (admin only) · Confidence: confirmed (503 in crawl)

### GB-71 · Alliance info for a nonexistent id → 503

- Status: **fixed** — unknown alliance id shows "alliance does not exist"
- Files: src/Controller/Game/AllianceController.php (setAllianceData)
- Severity: low · Confidence: confirmed (503 in baseline and now)
- Fix idea: show "alliance does not exist" message.

### GB-72 · Phalanx labels scanned fleets "Your fleets"

- Status: **fixed** — phalanx uses neutral cff_mission_other_* texts for the scanned player's fleets (de/en)
- Files: phalanx template/FlyingFleetsTable
- Severity: low · Confidence: suspicion (e2e observation)

### GB-73 · Alliance application accept/reject shows no confirmation

- Status: **fixed** — application accept/reject show a confirmation
- Severity: low (UX) · Confidence: confirmed (e2e)

## Found while fixing

### GB-74 · Missile attacks on a moon read/updated the parent planet's interceptors

- Status: **fixed** — missile attacks on a moon read/updated the parent planet's interceptors (id_luna filter) — MissionCaseMIP
- Severity: medium

### GB-75 · Fleet step 2 loads the target as a planet even for moons

- Status: **fixed** — fleet step 2 loads moons with planet_type 3
- Severity: low

### GB-76 · Moon destruction report shows raw sys_* translation keys

- Status: **fixed** — cause: a missing language pack file silently loaded nothing; Language now rebuilds the pack once when its source exists (tested with the cache removed)
- Severity: low

### GB-77 · Alliance tag/name length not validated server-side

- Status: **fixed** — alliance tag/name length validated server-side (8/30)
- Severity: low

### GB-78 · Game log entries need a web session (CLI processing of missions fails)

> Regression from the port (cron moved to the CLI); the original pr0game was not affected.

- Status: **fixed** — `Log` takes the acting user from the request session and falls back to 0 (system) outside a web request.
- Severity: low · Confidence: confirmed (found by the performance benchmark, which had to mock the session to process fleets from the CLI)
- Files: `src/Game/Core/Log.php:33` (`(new SessionManager(...))->load()->userId` for the admin id)
- What happens: code paths that write a game log entry outside a web request (CLI, a future fleet-processing worker) fail because there is no session.
- Fix idea: pass the acting user id in, or fall back to 0/system when no session is available.

### GB-79 · Resource stock clamp leaves 1 + fraction instead of 0

- Status: **fixed** — consumption larger than the stock now empties it exactly.
- Severity: low · Confidence: confirmed (reading; found while testing the fusion fix)
- Files: `src/Game/Core/ResourceUpdate.php` (`setProduction`)
- What happened: `$produced = 1 + (floor($stock) * -1)` left the stock at `1 + fraction` (e.g. 800.5 → 1.5) when a negative production (fusion deuterium burn) exceeded it.

### GB-80 · Rust battle engine never loaded (wrong library path)

> Regression from the port (the library path broke with the move to `src/Game/`); the original pr0game was not affected.

- Status: **fixed** — the engine now runs through the launcher `bin/battlerust`, located via `App\Runtime\ProjectPaths::ROOT`; verified end to end (simulator report stored with engine `rust`).
- Severity: high (performance, battle results come from the slower PHP engine) · Confidence: confirmed
- Files: `src/Game/BattleEngine/BattleEngineRust.php` (`isAvailable()`)
- What happened: since the game code moved to `src/Game/BattleEngine/`, `__DIR__ . '/../../bin/'` pointed to `src/bin/`, which does not exist, so `isAvailable()` always returned false and `BattleHandler` fell through to the PHP engine (Julia is not running either).

### GB-81 · In-game team page showed a hard-coded team

- Status: **fixed** — `TeamController` (game) now reads `team_members` like the public team page, so the admin team section is the only source.
- Severity: medium · Confidence: confirmed
- Files: `src/Controller/Game/TeamController.php`, `templates/game/page.team.default.html.twig`
- What happened: the page listed ten fixed usernames and looked up their ids; members added in the admin team section never appeared in the game.

### GB-82 · Closing an alliance left its ranks behind

- Status: **fixed** — rank rows are deleted when a player closes the alliance, when the cleaner removes empty alliances and when an admin deletes one in the account editor.
- Severity: low · Confidence: confirmed
- Files: `src/Controller/Game/AllianceController.php`, `src/Game/Cronjob/CleanerCronjob.php`, `src/Controller/Admin/AccountEditorController.php`
- What happened: orphaned `alliance_ranks` rows accumulated (found while testing the alliance pages).

### GB-83 · "Delete unmarked messages" also deleted the marked ones

- Status: **fixed** — the marked ids are used as given (same filter as the other bulk actions); the redirect after a bulk action keeps the page (`site`, was `side`).
- Severity: high · Confidence: confirmed (code path)
- Files: `src/Controller/Game/MessagesController.php` (`action()`)
- What happened: the branch turned the marked ids into a list and then excluded the list positions (`NOT IN (1, 2, …)`) instead of the ids, so every non-favourite message of the category was deleted, the marked ones included.

## Smaller issues

Lower-impact notes collected by the reviews; fixed opportunistically when the surrounding code is touched.

### Small robustness and logic issues (Economy and queues)

- Status: **fixed**, including the two ReBuildCache items: fusion deuterium consumption is no longer skipped at stock 0 (the stock is not part of the eco hash, so the wrong rate stayed frozen) and `*_perhour` is no longer zeroed when nothing consumes energy (production level 1); tested with planet states set by SQL. Fixed: stale `remove` no longer cancels the running job, `watch` clamped (no 500 for `watch=-1`), fleet dealer refuses vacation mode, `$$BuildEnergy` typo, dead `destroy` check.

- ResearchController:402-407 / BuildingsController:91-102: a stale `remove` with `listid>=2` while the queue has 1 entry calls `cancelXFromQueue()`, which cancels the running job (refunded, but unintended). Severity low.
- BuildingsController:270-281 / ResearchController:377-387: `?watch=-1` writes `-1` into `watch_id int unsigned` (STRICT). SQL error 1264 gives a 500 for any logged-in player. Severity low.
- FleetDealerController::send and RepairdockController deploy (66) have no vacation-mode check, unlike shipyard/buildings/research/resources. A player in vacation mode can convert ships into resources. Severity low.
- ResourcesController.php:180: `$$BuildEnergy = ...` is a variable-variable (`${0}`), so `$BuildEnergy` is never restored after the fusion energy bonus. It is masked only because energy is the last id in `$ressIDs`. Dead or incorrect code. Severity low.
- ResourceUpdate::ReBuildCache 336-339 and 351-354: fusion deuterium consumption is skipped when the stock is exactly 0 at rebuild, and all `perhour` values are zeroed when `energy_used == 0`. In both cases the fusion plant keeps producing full energy while consuming no deuterium until the next hash change. Setting all mines to 0% makes `energy_used` 0. Only affects energy requirements (terraformer, graviton, orbital station check gross `energy`). Severity low.
- ResearchController::getQueueData:346 compares the planet id to `'destroy'` (dead check, always false). Severity low.

## Summary
The most serious finding is the repair-dock deploy race: state is read before the row lock and reused after it, so parallel deploy requests duplicate ships. Repair-dock rounding (`floor(amount/rate)`) is a second, simpler abuse path that recovers up to the whole wreck field. In the queue engine, queued research is paid from stale resources (the second slot is likely dropped for offline players), promoted demolitions take 2.77x too long, and queue re-timing passes the queue index as the level, hiding entries from the UI. Research queue removal compares against the wrong element and can skip a level once the queue allows 3 or more entries. Shipyard multi-type orders ignore the combined cost (SQL error under strict mode) and bypass the queue limit. Moon cache rebuilds mutate the shared universe config, losing basic income and risking a persisted zero. Costs, production formulas, storage, requirements, building and research time formulas and the cancel/refund paths for buildings and research (locked and refunded from stored costs) look correct and race-safe. The fleet dealer is protected against double selling.

### Minor (Players, alliances, market, cron)

- Status: **fixed** `createPlanet` semicolon and `getMainPlanet` (`planet_type = 1`); `deletePlayer`/`deleteAccount` also clean the secondary tables (blocklist, alliance requests, comments, analytics, achievements, ACS, tutorials, expedition deck, multi, shortcuts, sessions, records, phalanx log, tickets, trades, lost-password/recaptcha rows); ban list, battle reports, hall of fame and logs are kept on purpose because other players appear in them (tested with a throwaway player).

- `PlayerUtil::createPlanet` image query: `WHERE id_owner = :userId; and destruyed = 0` has a stray semicolon (destroyed planets are not excluded, trailing part ignored).
- `PlayerUtil::getMainPlanet` (used by tutorials) lacks `planet_type = 1`, so a moon at the home coordinates can match.
- `deletePlayer` leaves rows in users_to_messages, users_to_acs, users_to_achievements, tutorial tables, expedition deck, banned etc. (ids are not reused, so mostly harmless).

## Summary
Highest impact: missing ADMIN right check on the alliance settings (any member can edit), the cleaner deleting all players if `del_user_automatic = 0`, the crafted market offer that breaks the market page for everyone, and the alliance owner being kickable or leaving (alliances with no manager). Medium: note overwrite and ticket reply without ownership, transfer to arbitrary users, wrong diplomacy notifications, buyer resource loss on transaction rollback, leader succession picking inactive or non-member users, and alliance accept crash. Many low items are copy/typo bugs (`ally_register_time` twice, `==` for `,`, wrong alias in joins, wrong date arithmetic in report cleanup). The achievements/tutorial reward claims are guarded atomically (`completed = 0` check), so I found no double claim.

## Fixed during the template port (before this review)

- Admin message list overwrote the admin's timezone (misplaced parenthesis).
- Statistics update: the Discord failure log was unreachable after the message page.
- Lost password: misspelled translation key `passwordUsernameEmpty`.
- Combat report header: winner/loser colours used invalid CSS (`color:colorPositive`).
- `text-right!`-style class leftovers without CSS in the fleet table.
