-- added uni prepare text
ALTER TABLE `%PREFIX%config_universe` ADD `prepare_text` TEXT NOT NULL AFTER `close_reason`;