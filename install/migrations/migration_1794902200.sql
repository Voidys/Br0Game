CREATE TABLE IF NOT EXISTS `%PREFIX%user_devices` (
  `user_id` int(11) unsigned NOT NULL,
  `device_hash` char(64) NOT NULL,
  `first_seen` int(11) unsigned NOT NULL,
  `last_seen` int(11) unsigned NOT NULL,
  PRIMARY KEY (`user_id`, `device_hash`),
  KEY `device` (`device_hash`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
