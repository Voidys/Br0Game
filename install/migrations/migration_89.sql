-- Add honorpoints to users table

ALTER TABLE `%PREFIX%users` ADD COLUMN `honorpoints` int(11) signed NOT NULL DEFAULT '100';

ALTER TABLE `%PREFIX%advanced_stats` ADD COLUMN `honorable_fights`    int(11) unsigned NOT NULL DEFAULT '0';
ALTER TABLE `%PREFIX%advanced_stats` ADD COLUMN `dishonorable_fights` int(11) unsigned NOT NULL DEFAULT '0';

ALTER TABLE `%PREFIX%advanced_stats_history` ADD COLUMN `honorable_fights`    int(11) unsigned NOT NULL DEFAULT '0';
ALTER TABLE `%PREFIX%advanced_stats_history` ADD COLUMN `dishonorable_fights` int(11) unsigned NOT NULL DEFAULT '0';
