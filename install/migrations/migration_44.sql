-- update jumpgate_factor to enable change of jumpgate wait times
ALTER TABLE `%PREFIX%config` CHANGE COLUMN `gate_wait_time` `jumpgate_factor` int(11) NOT NULL DEFAULT '1';
UPDATE `%PREFIX%config` set `jumpgate_factor` = 1;
