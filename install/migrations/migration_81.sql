-- adds catch up flag to universe settings
ALTER TABLE `%PREFIX%config_universe` ADD `catch_up` tinyint NOT NULL DEFAULT 0 AFTER `lang`;