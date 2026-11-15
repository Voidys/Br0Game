-- Add Warning for 3rd colony in a system

ALTER TABLE `%PREFIX%users_settings` ADD COLUMN `colonyWarn` int(1) unsigned NOT NULL DEFAULT 1;