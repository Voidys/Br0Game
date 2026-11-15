CREATE TABLE `%PREFIX%team_members` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `position` varchar(128) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  `contact` varchar(128) NOT NULL DEFAULT '',
  `sort` int(11) NOT NULL DEFAULT 0,
  `created_at` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `sort` (`sort`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
