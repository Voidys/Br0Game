INSERT INTO `%PREFIX%cronjobs` (`cronjobID`, `name`, `isActive`, `min`, `hours`, `dom`, `month`, `dow`, `class`, `nextTime`, `lock`)
SELECT NULL, 'fleets', 1, '*', '*', '*', '*', '*', 'FleetEventCronjob', 0, NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `%PREFIX%cronjobs` WHERE `class` = 'FleetEventCronjob');
