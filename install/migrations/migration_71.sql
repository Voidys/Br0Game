-- switch ressource multiplier to same logic as the other speeds
ALTER TABLE `%PREFIX%config_universe` MODIFY `resource_multiplier` bigint(20) unsigned NOT NULL DEFAULT 2500;
UPDATE `%PREFIX%config_universe` SET `resource_multiplier` = `resource_multiplier` * 2500;