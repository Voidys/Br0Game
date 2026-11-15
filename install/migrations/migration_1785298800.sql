ALTER TABLE `%PREFIX%pirate_bases_history`
    ADD `timestamp` int(11) unsigned NOT NULL DEFAULT '0' AFTER `der_crystal`,
    ADD KEY `timestamp` (`timestamp`);
