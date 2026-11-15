ALTER TABLE `%PREFIX%users_settings`
    ADD `hangar_filters` varchar(512) NOT NULL DEFAULT '["civil","military","all"]' AFTER `hide_useless`;
