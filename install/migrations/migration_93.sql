-- Add fk id to banned tables instead of names

ALTER TABLE `%PREFIX%accounts_banned` ADD COLUMN `account_id` int(11) unsigned NOT NULL AFTER `id`;
ALTER TABLE `%PREFIX%users_banned` ADD COLUMN `user_id` int(11) unsigned NOT NULL AFTER `id`;