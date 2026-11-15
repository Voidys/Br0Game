ALTER TABLE `%PREFIX%users_settings`
    ADD `spy_report_fresh_hours` smallint(5) unsigned NOT NULL DEFAULT '24' AFTER `settings_rep`,
    ADD `spy_report_recent_hours` smallint(5) unsigned NOT NULL DEFAULT '168' AFTER `spy_report_fresh_hours`;
