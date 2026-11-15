-- Add onlinetime for Account

ALTER TABLE `%PREFIX%accounts` ADD COLUMN `onlinetime` int(11) NOT NULL DEFAULT '0' after `register_time`;