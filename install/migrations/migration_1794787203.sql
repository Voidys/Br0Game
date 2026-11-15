CREATE TABLE IF NOT EXISTS `%PREFIX%board_posts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `flair` varchar(32) NOT NULL DEFAULT '',
  `title` varchar(200) NOT NULL,
  `body` mediumtext NOT NULL,
  `score` int NOT NULL DEFAULT 0,
  `comment_count` int NOT NULL DEFAULT 0,
  `pinned` tinyint NOT NULL DEFAULT 0,
  `locked` tinyint NOT NULL DEFAULT 0,
  `deleted` tinyint NOT NULL DEFAULT 0,
  `created_at` int NOT NULL DEFAULT 0,
  `edited_at` int NOT NULL DEFAULT 0,
  `last_activity` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `listing` (`deleted`, `pinned`, `created_at`),
  KEY `score` (`score`),
  KEY `last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%board_comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int unsigned NOT NULL,
  `parent_id` int unsigned NOT NULL DEFAULT 0,
  `account_id` int unsigned NOT NULL,
  `body` text NOT NULL,
  `score` int NOT NULL DEFAULT 0,
  `deleted` tinyint NOT NULL DEFAULT 0,
  `created_at` int NOT NULL DEFAULT 0,
  `edited_at` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `post_parent` (`post_id`, `parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%board_votes` (
  `account_id` int unsigned NOT NULL,
  `target_type` tinyint NOT NULL,
  `target_id` int unsigned NOT NULL,
  `value` tinyint NOT NULL,
  PRIMARY KEY (`account_id`, `target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
