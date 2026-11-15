-- add factor for phalanx and missile range
ALTER TABLE `%PREFIX%config_universe` ADD COLUMN `missile_range_factor` float(4,3) unsigned NOT NULL DEFAULT '1.000' AFTER `energy_multiplier`;
ALTER TABLE `%PREFIX%config_universe` ADD COLUMN `phalanx_range_factor` float(4,3) unsigned NOT NULL DEFAULT '1.000' AFTER `missile_range_factor`;