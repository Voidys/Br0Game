-- Add ship type column to vars table for recyclers

ALTER TABLE `%PREFIX%vars` 
  ADD COLUMN `ship_type` varchar(255) DEFAULT NULL AFTER `class`;

UPDATE `%PREFIX%vars`
  SET `ship_type` = 'unknown'
  WHERE `class` >= 200 AND `class` <= 299;

UPDATE `%PREFIX%vars`
  SET `ship_type` = 'recycler'
  WHERE `elementID` = 209 OR `elementID` = 219 OR `elementID` = 227 OR `elementID` = 228;