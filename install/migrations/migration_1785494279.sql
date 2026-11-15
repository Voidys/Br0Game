-- Adds void titan and behemoth
ALTER TABLE `%PREFIX%planets` ADD `behemoth` bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `INTERCEPTOR`;
ALTER TABLE `%PREFIX%planets` ADD `void_titan` bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `behemoth`;
