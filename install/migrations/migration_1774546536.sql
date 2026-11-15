-- add tcg_token column to users_settings for cross-game reward integration
ALTER TABLE `%PREFIX%raports`
    ADD `battleengine` varchar(20) NOT NULL DEFAULT '' AFTER `keep`;
