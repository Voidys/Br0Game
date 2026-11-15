-- stats history keeps one snapshot per run (Statbuilder joins on `timestamp`, CleanerCronjob prunes old ones)
ALTER TABLE `%PREFIX%advanced_stats_history` DROP PRIMARY KEY, ADD PRIMARY KEY (`timestamp`, `userId`, `universe`, `statkey`);
