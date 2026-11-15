-- add max useful level to vars
ALTER TABLE `%PREFIX%vars`
    ADD `max_useful_level` tinyint(3) NOT NULL DEFAULT '0' AFTER `storage903`;

UPDATE `%PREFIX%vars` SET `max_useful_level` =  8 WHERE `elementID` = 114; -- hyperspace_tech (without special ships its level  8)
UPDATE `%PREFIX%vars` SET `max_useful_level` = 12 WHERE `elementID` = 120; -- laser_tech      (without special ships its level 12)
UPDATE `%PREFIX%vars` SET `max_useful_level` =  5 WHERE `elementID` = 121; -- ionic_tech.     (without special ships its level  5)
UPDATE `%PREFIX%vars` SET `max_useful_level` =  7 WHERE `elementID` = 122; -- buster_tech.    (without special ships its level  7)
UPDATE `%PREFIX%vars` SET `max_useful_level` =  1 WHERE `elementID` = 199; -- graviton.       (without special ships its level  1)

ALTER TABLE `%PREFIX%users_settings`
    ADD `hide_useless` tinyint(1) NOT NULL DEFAULT '0' AFTER `missing_resources_opacity`;