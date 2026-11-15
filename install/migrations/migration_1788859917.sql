-- Rename and reorder existing fleet columns without copying data.
ALTER TABLE `%PREFIX%fleets`
  CHANGE COLUMN `fleet_id` `id` bigint(11) unsigned NOT NULL AUTO_INCREMENT FIRST,
  CHANGE COLUMN `fleet_group` `group` int(10) unsigned NOT NULL DEFAULT '0' AFTER `id`,
  CHANGE COLUMN `fleet_universe` `universe` tinyint(3) unsigned NOT NULL AFTER `group`,
  CHANGE COLUMN `fleet_mission` `mission` tinyint(3) unsigned NOT NULL DEFAULT '3' AFTER `universe`,
  CHANGE COLUMN `fleet_owner` `owner` int(11) unsigned NOT NULL DEFAULT '0' AFTER `mission`,
  CHANGE COLUMN `fleet_target_owner` `target_owner` int(11) unsigned NOT NULL DEFAULT '0' AFTER `owner`,
  
  CHANGE COLUMN `fleet_amount` `ship_amount` bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `target_owner`,
  CHANGE COLUMN `fleet_array` `ships` text AFTER `ship_amount`,
  CHANGE COLUMN `fleet_start_array` `start_ships` text AFTER `ships`,
  CHANGE COLUMN `start_time` `start_time` int(11) NOT NULL DEFAULT '0' AFTER `start_ships`,
  CHANGE COLUMN `fleet_start_time` `arrival_time` int(11) NOT NULL DEFAULT '0' AFTER `start_time`,
  CHANGE COLUMN `fleet_end_stay` `stay_time` int(11) NOT NULL DEFAULT '0' AFTER `arrival_time`,
  CHANGE COLUMN `fleet_end_time` `return_time` int(11) NOT NULL DEFAULT '0' AFTER `stay_time`,
  
  CHANGE COLUMN `fleet_start_id` `start_id` int(11) unsigned NOT NULL AFTER `return_time`,
  CHANGE COLUMN `fleet_start_galaxy` `start_galaxy` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `start_id`,
  CHANGE COLUMN `fleet_start_system` `start_system` smallint(5) unsigned NOT NULL DEFAULT '0' AFTER `start_galaxy`,
  CHANGE COLUMN `fleet_start_planet` `start_planet` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `start_system`,
  CHANGE COLUMN `fleet_start_type` `start_type` tinyint(3) unsigned NOT NULL DEFAULT '1' AFTER `start_planet`,
  
  CHANGE COLUMN `fleet_end_id` `end_id` int(11) unsigned NOT NULL AFTER `start_type`,
  CHANGE COLUMN `fleet_end_galaxy` `end_galaxy` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `end_id`,
  CHANGE COLUMN `fleet_end_system` `end_system` smallint(5) unsigned NOT NULL DEFAULT '0' AFTER `end_galaxy`,
  CHANGE COLUMN `fleet_end_planet` `end_planet` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `end_system`,
  CHANGE COLUMN `fleet_end_type` `end_type` tinyint(3) unsigned NOT NULL DEFAULT '1' AFTER `end_planet`,
  
  CHANGE COLUMN `fleet_target_obj` `missile_target` smallint(3) unsigned NOT NULL DEFAULT '0' AFTER `end_type`,
  ADD COLUMN `fuel_cost` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `missile_target`,
  CHANGE COLUMN `fleet_resource_metal` `resource_metal` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `fuel_cost`,
  CHANGE COLUMN `fleet_resource_crystal` `resource_crystal` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `resource_metal`,
  CHANGE COLUMN `fleet_resource_deuterium` `resource_deuterium` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `resource_crystal`,
  CHANGE COLUMN `fleet_wanted_resource` `wanted_resource` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `resource_deuterium`,
  CHANGE COLUMN `fleet_wanted_resource_amount` `wanted_resource_amount` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `wanted_resource`,
  
  CHANGE COLUMN `fleet_mess` `status` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `wanted_resource_amount`,
  CHANGE COLUMN `fleet_no_m_return` `cannotCancel` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `status`,
  CHANGE COLUMN `hasCanceled` `hasCanceled` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `cannotCancel`;

ALTER TABLE `%PREFIX%fleets`
  DROP COLUMN `fleet_busy`;

ALTER TABLE `%PREFIX%fleets`
  DROP INDEX `fleet_target_owner`,
  DROP INDEX `fleet_owner`,
  DROP INDEX `fleet_group`,
  ADD KEY `target_owner` (`target_owner`,`mission`),
  ADD KEY `owner` (`owner`,`mission`),
  ADD KEY `group` (`group`);

ALTER TABLE `%PREFIX%log_fleets`
  CHANGE COLUMN `fleet_id` `id` bigint(11) unsigned NOT NULL AUTO_INCREMENT FIRST,
  CHANGE COLUMN `fleet_group` `group` int(10) unsigned NOT NULL DEFAULT '0' AFTER `id`,
  CHANGE COLUMN `fleet_universe` `universe` tinyint(3) unsigned NOT NULL AFTER `group`,
  CHANGE COLUMN `fleet_mission` `mission` tinyint(3) unsigned NOT NULL DEFAULT '3' AFTER `universe`,
  CHANGE COLUMN `fleet_owner` `owner` int(11) unsigned NOT NULL DEFAULT '0' AFTER `mission`,
  CHANGE COLUMN `fleet_owner_name` `owner_name` varchar(32) NOT NULL DEFAULT '' AFTER `owner`,
  CHANGE COLUMN `fleet_owner_points` `owner_points` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `owner_name`,
  CHANGE COLUMN `fleet_target_owner` `target_owner` int(11) unsigned NOT NULL DEFAULT '0' AFTER `owner_points`,
  CHANGE COLUMN `fleet_target_owner_name` `target_owner_name` varchar(32) NOT NULL DEFAULT '' AFTER `target_owner`,
  CHANGE COLUMN `fleet_target_owner_points` `target_owner_points` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `target_owner_name`,
  ADD COLUMN `report_id` varchar(32) NOT NULL DEFAULT '' AFTER `target_owner_points`,

  CHANGE COLUMN `fleet_amount` `ship_amount` bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `report_id`,
  CHANGE COLUMN `fleet_array` `ships` text AFTER `ship_amount`,
  CHANGE COLUMN `fleet_start_array` `start_ships` text AFTER `ships`,
  CHANGE COLUMN `start_time` `start_time` int(11) NOT NULL DEFAULT '0' AFTER `start_ships`,
  CHANGE COLUMN `start_time_formated` `start_time_formated` datetime AFTER `start_time`,
  CHANGE COLUMN `fleet_start_time` `arrival_time` int(11) NOT NULL DEFAULT '0' AFTER `start_time_formated`,
  CHANGE COLUMN `fleet_start_time_formated` `arrival_time_formated` datetime AFTER `arrival_time`,
  CHANGE COLUMN `fleet_end_stay` `stay_time` int(11) NOT NULL DEFAULT '0' AFTER `arrival_time_formated`,
  CHANGE COLUMN `fleet_end_stay_formated` `stay_time_formated` datetime AFTER `stay_time`,
  CHANGE COLUMN `fleet_end_time` `return_time` int(11) NOT NULL DEFAULT '0' AFTER `stay_time_formated`,
  CHANGE COLUMN `fleet_end_time_formated` `return_time_formated` datetime AFTER `return_time`,

  CHANGE COLUMN `fleet_start_id` `start_id` int(11) unsigned NOT NULL AFTER `return_time_formated`,
  CHANGE COLUMN `fleet_start_galaxy` `start_galaxy` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `start_id`,
  CHANGE COLUMN `fleet_start_system` `start_system` smallint(5) unsigned NOT NULL DEFAULT '0' AFTER `start_galaxy`,
  CHANGE COLUMN `fleet_start_planet` `start_planet` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `start_system`,
  CHANGE COLUMN `fleet_start_type` `start_type` tinyint(3) unsigned NOT NULL DEFAULT '1' AFTER `start_planet`,

  CHANGE COLUMN `fleet_end_id` `end_id` int(11) unsigned NOT NULL AFTER `start_type`,
  CHANGE COLUMN `fleet_end_galaxy` `end_galaxy` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `end_id`,
  CHANGE COLUMN `fleet_end_system` `end_system` smallint(5) unsigned NOT NULL DEFAULT '0' AFTER `end_galaxy`,
  CHANGE COLUMN `fleet_end_planet` `end_planet` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `end_system`,
  CHANGE COLUMN `fleet_end_type` `end_type` tinyint(3) unsigned NOT NULL DEFAULT '1' AFTER `end_planet`,

  CHANGE COLUMN `fleet_target_obj` `missile_target` smallint(3) unsigned NOT NULL DEFAULT '0' AFTER `end_type`,
  ADD COLUMN `fuel_cost` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `missile_target`,
  CHANGE COLUMN `fleet_resource_metal` `resource_metal` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `fuel_cost`,
  CHANGE COLUMN `fleet_resource_crystal` `resource_crystal` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `resource_metal`,
  CHANGE COLUMN `fleet_resource_deuterium` `resource_deuterium` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `resource_crystal`,
  CHANGE COLUMN `fleet_gained_metal` `gained_metal` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `resource_deuterium`,
  CHANGE COLUMN `fleet_gained_crystal` `gained_crystal` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `gained_metal`,
  CHANGE COLUMN `fleet_gained_deuterium` `gained_deuterium` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `gained_crystal`,
  CHANGE COLUMN `fleet_wanted_resource` `wanted_resource` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `gained_deuterium`,
  CHANGE COLUMN `fleet_wanted_resource_amount` `wanted_resource_amount` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `wanted_resource`,
  CHANGE COLUMN `current_market_rate` `current_market_rate` text AFTER `wanted_resource_amount`,
  CHANGE COLUMN `current_market_value_based_on_DSE` `current_market_value_based_on_DSE` double(50,0) unsigned NOT NULL DEFAULT '0' AFTER `current_market_rate`,

  CHANGE COLUMN `fleet_mess` `status` tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER `current_market_value_based_on_DSE`,
  CHANGE COLUMN `fleet_no_m_return` `cannotCancel` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `status`,
  CHANGE COLUMN `hasCanceled` `hasCanceled` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `cannotCancel`;

ALTER TABLE `%PREFIX%log_fleets`
  DROP COLUMN `fleet_busy`,
  DROP COLUMN `fleet_state`;

ALTER TABLE `%PREFIX%log_fleets`
  DROP INDEX `BashRule`,
  ADD KEY `BashRule` (`owner`,`end_id`,`start_time`,`mission`);

ALTER TABLE `%PREFIX%pirate_bases`
  CHANGE COLUMN `fleet_array` `ships` text;

ALTER TABLE `%PREFIX%pirate_bases_history`
  CHANGE COLUMN `fleet_array` `ships` text;

CREATE OR REPLACE ALGORITHM = UNDEFINED SQL SECURITY DEFINER VIEW `multi_fleet_overview` AS
SELECT
  `m`.`multiID` AS `multiID`,
  `m`.`universe` AS `universe`,
  `lf`.`id` AS `fleet_id`,
  `lf`.`mission` AS `mission`,
  `lf`.`owner_name` AS `owner_name`,
  `oa`.`ally_tag` AS `owner_ally`,
  `lf`.`owner_points` AS `owner_points`,
  `lf`.`target_owner_name` AS `target_owner_name`,
  `ta`.`ally_tag` AS `target_ally`,
  `lf`.`target_owner_points` AS `target_owner_points`,
  `lf`.`start_time_formated` AS `start_time_formated`,
  `lf`.`arrival_time_formated` AS `arrival_time_formated`,
  `lf`.`resource_metal` AS `resource_metal`,
  `lf`.`resource_crystal` AS `resource_crystal`,
  `lf`.`resource_deuterium` AS `resource_deuterium`,
  `lf`.`gained_metal` AS `gained_metal`,
  `lf`.`gained_crystal` AS `gained_crystal`,
  `lf`.`gained_deuterium` AS `gained_deuterium`,
  `lf`.`current_market_rate` AS `current_market_rate`,
  `lf`.`current_market_value_based_on_DSE` AS `current_market_value_based_on_DSE`,
  `lf`.`ships` AS `ships`,
  `lf`.`start_ships` AS `start_ships`,
  `lf`.`hasCanceled` AS `hasCanceled`
FROM `%PREFIX%multi` `m`
JOIN `%PREFIX%multi_to_users` `owner_user`
  ON `owner_user`.`multiID` = `m`.`multiID`
JOIN `%PREFIX%users` `ou`
  ON `ou`.`id` = `owner_user`.`userID`
LEFT JOIN `%PREFIX%alliance` `oa`
  ON `oa`.`id` = `ou`.`ally_id`
JOIN `%PREFIX%multi_to_users` `target_user`
  ON `target_user`.`multiID` = `m`.`multiID`
JOIN `%PREFIX%users` `tu`
  ON `tu`.`id` = `target_user`.`userID`
LEFT JOIN `%PREFIX%alliance` `ta`
  ON `ta`.`id` = `tu`.`ally_id`
JOIN `%PREFIX%log_fleets` `lf`
  ON `lf`.`owner` = `owner_user`.`userID`
  AND `lf`.`target_owner` = `target_user`.`userID`
  AND `lf`.`universe` = `m`.`universe`
WHERE `owner_user`.`userID` <> `target_user`.`userID`
  AND `m`.`allowed` = 0;
