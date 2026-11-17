ALTER TABLE `%PREFIX%statpoints`
    ADD KEY `total_rank_idx` (`universe`, `stat_type`, `total_rank`),
    ADD KEY `fleet_rank_idx` (`universe`, `stat_type`, `fleet_rank`),
    ADD KEY `tech_rank_idx` (`universe`, `stat_type`, `tech_rank`),
    ADD KEY `build_rank_idx` (`universe`, `stat_type`, `build_rank`),
    ADD KEY `defs_rank_idx` (`universe`, `stat_type`, `defs_rank`),
    ADD KEY `units_rank_idx` (`universe`, `stat_type`, `units_rank`);
