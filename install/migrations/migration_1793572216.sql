-- Adds void titan and behemoth
ALTER TABLE `%PREFIX%planets` ADD `bunker` bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `graviton_canyon`;
ALTER TABLE `%PREFIX%planets` ADD `titan_laser` bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `bunker`;
ALTER TABLE `%PREFIX%planets` ADD `decoy` bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `titan_laser`;