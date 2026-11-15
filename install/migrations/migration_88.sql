-- Fix cost of storages to match original game
-- metlager
UPDATE `%PREFIX%vars` SET `cost901` = '1000' WHERE `elementID` = 22 AND `universe` = 0;

-- krislager
UPDATE `%PREFIX%vars` SET `cost901` = '1000', `cost902` = '500' WHERE `elementID` = 23 AND `universe` = 0;

-- deutlager
UPDATE `%PREFIX%vars` SET `cost901` = '1000', `cost902` = '1000' WHERE `elementID` = 24 AND `universe` = 0;

-- update metal store
update `%PREFIX%planets` SET `metal` = `metal` + 1000 WHERE `universe` = 5 AND (`metal_store` > 0 OR `b_building_id` LIKE '%i:0;a:5:{i:0;i:22%');

-- update crystal store
update `%PREFIX%planets` SET `metal` = `metal` + 1000, `crystal` = `crystal` + 500 WHERE `universe` = 5 AND (`crystal_store` > 0 OR `b_building_id` LIKE '%i:0;a:5:{i:0;i:23%');

-- update deut store
update `%PREFIX%planets` SET `metal` = `metal` + 1000, `crystal` = `crystal` + 1000 WHERE `universe` = 5 AND (`deuterium_store` > 0 OR `b_building_id` LIKE '%i:0;a:5:{i:0;i:24%');
