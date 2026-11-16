CREATE TABLE IF NOT EXISTS `%PREFIX%honor_fights` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `attacker` int(11) unsigned NOT NULL,
  `defender` int(11) unsigned NOT NULL,
  `time` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pair` (`attacker`, `defender`, `time`),
  KEY `time` (`time`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
