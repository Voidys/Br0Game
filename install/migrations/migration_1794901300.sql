CREATE TABLE IF NOT EXISTS `%PREFIX%hall_of_fame` (
  `universe` int(11) unsigned NOT NULL,
  `data` mediumtext NOT NULL,
  `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`universe`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
