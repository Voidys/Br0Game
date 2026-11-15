ALTER TABLE `%PREFIX%statpoints`
    ADD `honor_points` bigint(20) NOT NULL DEFAULT '0' AFTER `total_count`,
    ADD `honor_rank` int(11) unsigned NOT NULL DEFAULT '0' AFTER `honor_points`,
    ADD `honor_old_rank` int(11) unsigned NOT NULL DEFAULT '0' AFTER `honor_rank`,
    ADD `honorable_fights` int(11) unsigned NOT NULL DEFAULT '0' AFTER `honor_old_rank`,
    ADD `dishonorable_fights` int(11) unsigned NOT NULL DEFAULT '0' AFTER `honorable_fights`,
    ADD `honor_title` varchar(16) NOT NULL DEFAULT 'neutral' AFTER `dishonorable_fights`,
    ADD KEY `honor_rank_idx` (`universe`, `stat_type`, `honor_rank`);

ALTER TABLE `%PREFIX%statpoints_history`
    ADD `honor_points` bigint(20) NOT NULL DEFAULT '0' AFTER `total_count`,
    ADD `honor_rank` int(11) unsigned NOT NULL DEFAULT '0' AFTER `honor_points`,
    ADD `honorable_fights` int(11) unsigned NOT NULL DEFAULT '0' AFTER `honor_rank`,
    ADD `dishonorable_fights` int(11) unsigned NOT NULL DEFAULT '0' AFTER `honorable_fights`,
    ADD `honor_title` varchar(16) NOT NULL DEFAULT 'neutral' AFTER `dishonorable_fights`;
