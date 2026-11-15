-- Decouple fleet event processing per universe: the FleetHandler FOR UPDATE scan previously locked pending events of all
-- universes (universe filter only via join on fleets), so event processing in one universe stalled page loads in every universe.
ALTER TABLE `%PREFIX%fleet_event`
  ADD COLUMN `universe` tinyint(3) unsigned NOT NULL DEFAULT 1 AFTER `time`;

ALTER TABLE `%PREFIX%fleet_event`
  ADD KEY `universe` (`universe`, `lock`, `time`);

UPDATE `%PREFIX%fleet_event` fe
  INNER JOIN `%PREFIX%fleets` f ON f.fleet_id = fe.fleetID
  SET fe.universe = f.fleet_universe;
