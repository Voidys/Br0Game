-- Pirate bases
CREATE TABLE `%PREFIX%pirate_bases` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_owner` int(11) unsigned NOT NULL DEFAULT '0',
  `universe` tinyint(3) unsigned NOT NULL,
  `galaxy` tinyint(3) NOT NULL DEFAULT '0',
  `system` smallint(5) NOT NULL DEFAULT '0',
  `planet_type` smallint(5) NOT NULL DEFAULT '4',
  `metal` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `crystal` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `deuterium` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `fleet_array` text,
  `der_metal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `der_crystal` double(50,0) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `%PREFIX%cronjobs` (`cronjobID`, `name`, `isActive`, `min`, `hours`, `dom`, `month`, `dow`, `class`, `nextTime`, `lock`) VALUES
(NULL, 'pirates', 0, '0', '2', '*', '*', '0', 'PirateSpawnerCronjob', 0, NULL);