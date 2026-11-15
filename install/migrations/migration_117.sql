ALTER TABLE `%PREFIX%users_settings`
    ADD `settings_rep` tinyint(1) NOT NULL DEFAULT '1' AFTER `settings_mis`;
