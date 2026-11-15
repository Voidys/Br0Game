ALTER TABLE `%PREFIX%users`
    ADD `outlaw_time` int(11) unsigned NOT NULL DEFAULT 0 AFTER honorpoints;

ALTER TABLE `%PREFIX%config_universe`
    ADD noob_protection_outlaw tinyint(1) NOT NULL DEFAULT 0 after `noob_protection_multi`;
