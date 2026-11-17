DROP TABLE IF EXISTS `%PREFIX%users_voting`;
DROP TABLE IF EXISTS `%PREFIX%voting_sites`;
CREATE TABLE IF NOT EXISTS `%PREFIX%vote_sites` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `universe` int(11) unsigned NOT NULL DEFAULT 1,
  `name` varchar(80) NOT NULL,
  `vote_url` varchar(500) NOT NULL,
  `image_url` varchar(500) NOT NULL DEFAULT '',
  `cooldown_hours` int(11) unsigned NOT NULL DEFAULT 24,
  `reward` json NOT NULL,
  `verification` enum('none','postback') NOT NULL DEFAULT 'none',
  `active` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `sort` int(11) NOT NULL DEFAULT 0,
  `secret` varchar(64) NOT NULL,
  `created` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `universe` (`universe`, `active`, `sort`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `%PREFIX%vote_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `token` char(32) NOT NULL,
  `clicked_at` int(11) unsigned NOT NULL,
  `verified_at` int(11) unsigned DEFAULT NULL,
  `rewarded_at` int(11) unsigned DEFAULT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `site_user` (`site_id`, `user_id`, `rewarded_at`),
  KEY `clicked_at` (`clicked_at`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
