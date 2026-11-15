CREATE TABLE `%PREFIX%expedition_findable_ships` (
  `universe` INT UNSIGNED NOT NULL,
  `ship_id` SMALLINT UNSIGNED NOT NULL,
  `is_findable` TINYINT(1) NOT NULL DEFAULT 0,
  `findable_by` LONGTEXT NOT NULL,
  PRIMARY KEY (`universe`, `ship_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;