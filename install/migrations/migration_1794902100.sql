-- balance switches default to the safe values: update universes still on the old defaults, keep explicit choices
UPDATE `%PREFIX%config_universe` SET `value` = '1' WHERE `key` = 'transport_push_check' AND `value` IN ('', '0');
UPDATE `%PREFIX%config_universe` SET `value` = '100000' WHERE `key` = 'transport_push_limit' AND `value` = '0';
UPDATE `%PREFIX%config_universe` SET `value` = '10' WHERE `key` = 'market_push_tolerance' AND `value` = '30';
UPDATE `%PREFIX%config_universe` SET `value` = '3' WHERE `key` = 'honor_pair_cap' AND `value` = '0';
UPDATE `%PREFIX%config_universe` SET `value` = '0' WHERE `key` = 'bash_war_exempt' AND `value` = '1';
UPDATE `%PREFIX%config_universe` SET `value` = '1' WHERE `key` = 'depot_cost_real_consumption' AND `value` IN ('', '0');
