ALTER TABLE `%PREFIX%users_settings`
    ADD `hangar_filters_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `hangar_filters`;
