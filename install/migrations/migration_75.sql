-- Fix crystal boost percentages
ALTER TABLE `%PREFIX%planets` MODIFY `crystal_bonus_percent` float(4,3) unsigned NOT NULL DEFAULT 0;
ALTER TABLE `%PREFIX%planets` MODIFY `metal_bonus_percent` float(4,3) unsigned NOT NULL DEFAULT 0;
ALTER TABLE `%PREFIX%planets` MODIFY `deuterium_bonus_percent` float(4,3) unsigned NOT NULL DEFAULT 0;
UPDATE `%PREFIX%planets` SET `crystal_bonus_percent` = 1.93 where `crystal_bonus_percent` = 2;
UPDATE `%PREFIX%planets` SET `crystal_bonus_percent` = 3.90 where `crystal_bonus_percent` = 4;
UPDATE `%PREFIX%planets` SET `crystal_bonus_percent` = 5.92 where `crystal_bonus_percent` = 6;
UPDATE `%PREFIX%planets` SET `crystal_bonus_percent` = 7.97 where `crystal_bonus_percent` = 8;
UPDATE `%PREFIX%planets` SET `crystal_bonus_percent` = 14.68 where `crystal_bonus_percent` = 15;
UPDATE `%PREFIX%planets` SET `crystal_bonus_percent` = 25.44 where `crystal_bonus_percent` = 25;
UPDATE `%PREFIX%planets` SET `crystal_bonus_percent` = 37.47 where `crystal_bonus_percent` = 37;