-- add peruser vs per planet bash protection
ALTER TABLE `%PREFIX%config_universe`
    ADD `bash_per_user` int(11) NOT NULL DEFAULT 0 AFTER `adm_attack`,
    ADD `bash_per_planet` int(11) NOT NULL DEFAULT 6 AFTER `bash_per_user`;