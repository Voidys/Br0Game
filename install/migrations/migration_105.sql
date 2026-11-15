-- Change universe config to add statsjson_delay
ALTER TABLE `%PREFIX%config_universe` ADD COLUMN `statsjson_delay` int unsigned NOT NULL DEFAULT '604800' AFTER `close_reason`;

INSERT INTO `%PREFIXcronjobs` (`cronjobID`, `name`, `isActive`, `min`, `hours`, `dom`, `month`, `dow`, `class`, `nextTime`, `lock`) VALUES
(NULL, 'statisticjson', 0, '0', '6', '*', '*', '6', 'StatisticJsonCronjob', 0, NULL);