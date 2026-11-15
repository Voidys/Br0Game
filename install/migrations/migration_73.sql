-- add keep flag to battle reports

ALTER TABLE `%PREFIX%raports` ADD `keep` TINYINT(1) unsigned NOT NULL DEFAULT '0';