-- Add honorpoints to users table

ALTER TABLE `%PREFIX%users` DROP COLUMN `password`;
ALTER TABLE `%PREFIX%users` DROP COLUMN `email`;
ALTER TABLE `%PREFIX%users` DROP COLUMN `email_2`;
ALTER TABLE `%PREFIX%users` DROP COLUMN `failed_logins`;