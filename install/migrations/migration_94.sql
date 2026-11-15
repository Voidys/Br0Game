-- Modify vars for recycler
-- Add cols speedTech2, speedTech3, consumption3

ALTER TABLE `%PREFIX%vars` RENAME COLUMN `speedTech` TO `speedTech1`;
ALTER TABLE `%PREFIX%vars` 
    ADD COLUMN `consumption3` int(11) unsigned DEFAULT NULL AFTER `consumption2`,
    ADD COLUMN `speedTech2` int(11) unsigned DEFAULT NULL AFTER `speedTech1`,
    ADD COLUMN `speedTech3` int(11) unsigned DEFAULT NULL AFTER `speedTech2`;

UPDATE `%PREFIX%vars` SET `speedTech1` = 1, `speedTech2` = 17, `speedTech3` = 15, 
    `consumption1` = 300, `consumption2` = 300, `consumption3` = 300, 
    `speed1` = 2000, `speed2` = 4000, `speed3` = 6000
    WHERE `speedTech1` = 6 AND `speedTech2` IS NULL AND `speedTech3` IS NULL;

UPDATE `%PREFIX%vars` SET `speedTech1` = 0, `speedTech2` = 1, `speedTech3` = 8, 
    `consumption1` = 0, `consumption2` = 1000, `consumption3` = 700, 
    `speed1` = 0, `speed2` = 4000, `speed3` = 5000
    WHERE `speedTech1` = 5 AND `speedTech2` IS NULL AND `speedTech3` IS NULL;

UPDATE `%PREFIX%vars` SET `speedTech1` = 1, `speedTech2` = 5, `speedTech3` = 0, 
    `consumption1` = 10, `consumption2` = 20, `consumption3` = 0, 
    `speed1` = 5000, `speed2` = 10000, `speed3` = 0
    WHERE `speedTech1` = 4 AND `speedTech2` IS NULL AND `speedTech3` IS NULL;

UPDATE `%PREFIX%vars` SET `speedTech1` = 0, `speedTech2` = 0, `speedTech3` = 1, 
    `consumption3` = `consumption1`, `consumption2` = 0, `consumption1` = 0, 
    `speed3` = `speed1`, `speed2` = 0, `speed1` = 0
    WHERE `speedTech1` = 3 AND `speedTech2` IS NULL AND `speedTech3` IS NULL;

UPDATE `%PREFIX%vars` SET `speedTech1` = 0, `speedTech2` = 1, `speedTech3` = 0, 
    `consumption2` = `consumption1`, `consumption1` = 0, `consumption3` = 0, 
    `speed2` = `speed1`, `speed1` = 0, `speed3` = 0
    WHERE `speedTech1` = 2 AND `speedTech2` IS NULL AND `speedTech3` IS NULL;

UPDATE `%PREFIX%vars` SET `speedTech1` = 1, `speedTech2` = 0, `speedTech3` = 0, 
    `consumption2` = 0, `consumption3` = 0, 
    `speed2` = 0, `speed3` = 0
    WHERE `speedTech1` = 1 AND `speedTech2` IS NULL AND `speedTech3` IS NULL;

UPDATE `%PREFIX%vars` SET `speedTech1` = 0, `speedTech2` = 0, `speedTech3` = 0, 
    `consumption1` = 0, `consumption2` = 0, `consumption3` = 0, 
    `speed1` = 0, `speed2` = 0, `speed3` = 0
    WHERE `speedTech1` = 0 AND `speedTech2` IS NULL AND `speedTech3` IS NULL;

-- Recycler-Speed-Buff, will be added manualy to database
--INSERT INTO `%PREFIX%vars`
--    (`elementID`,`universe`,`name`,`class`,`onPlanetType`,`onePerPlanet`,`factor`,
--    `cost901`,`cost902`,`cost903`,`cost911`,
--    `consumption1`,`consumption2`,`consumption3`,
--    `speedTech1`,`speedTech2`,`speedTech3`,
--    `speed1`,`speed2`,`speed3`,
--    `capacity`,`attack`,`defend`) 
--VALUES (209,5,'recycler',200,'1,3',0,1,
--    10000,6000,2000,0,
--    300,300,300,
--    1,13,12,
--    2000,4000,6000,
--    20000,1,10);