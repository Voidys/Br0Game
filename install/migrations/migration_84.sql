-- adds catch up flag to universe settings
ALTER TABLE `%PREFIX%config_universe` ADD `rebuild_protection` tinyint NOT NULL DEFAULT 0 AFTER `noob_protection_multi`;
ALTER TABLE `%PREFIX%users` ADD `rebuild_protection` int(11) unsigned NOT NULL DEFAULT 0 AFTER `onlinetime`;
ALTER TABLE `%PREFIX%users` ADD `rebuild_protection_attackers` text AFTER `rebuild_protection`;
ALTER TABLE `%PREFIX%users` ADD `rebuild_protection_locked_until` int(11) unsigned NOT NULL DEFAULT 0 AFTER `rebuild_protection_attackers`;