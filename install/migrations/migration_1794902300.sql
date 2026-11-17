-- Drop the unused Google Analytics config keys and the unused cross-game token column
DELETE FROM `%PREFIX%config` WHERE `key` IN ('google_analytics_key', 'use_google_analytics');
ALTER TABLE `%PREFIX%users_settings` DROP COLUMN `tcg_token`;
