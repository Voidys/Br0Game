CREATE TABLE IF NOT EXISTS `%PREFIX%bot_relations` (
  `bot_id` INT UNSIGNED NOT NULL,
  `player_id` INT UNSIGNED NOT NULL,
  `universe` TINYINT UNSIGNED NOT NULL,
  `balance` INT NOT NULL DEFAULT 0,
  `interactions` INT UNSIGNED NOT NULL DEFAULT 0,
  `player_contacted` TINYINT(1) NOT NULL DEFAULT 0,
  `last_interaction` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_handled_message_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_bot_message_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`bot_id`, `player_id`),
  KEY `universe` (`universe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `%PREFIX%bot_players` ADD COLUMN `last_threat_check` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_message_id`;
