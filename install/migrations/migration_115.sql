-- add tcg_token column to users_settings for cross-game reward integration
ALTER TABLE `%PREFIX%users_settings`
    ADD `tcg_token` varchar(64) NOT NULL DEFAULT '' AFTER `noob_interface`;
