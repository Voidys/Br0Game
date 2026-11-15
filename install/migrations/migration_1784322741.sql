ALTER TABLE `%PREFIX%users_settings`
    ADD COLUMN `spyReportsCollapsed` tinyint(1) NOT NULL DEFAULT 0 AFTER `spyMessagesMode`;
