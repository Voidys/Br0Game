ALTER TABLE `%PREFIX%statpoints`
    ADD `units_points` bigint(20) NOT NULL DEFAULT '0' AFTER `honor_title`,
    ADD `units_rank` int(11) unsigned NOT NULL DEFAULT '0' AFTER `units_points`,
    ADD `units_old_rank` int(11) unsigned NOT NULL DEFAULT '0' AFTER `units_rank`;
