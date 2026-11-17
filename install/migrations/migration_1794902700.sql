UPDATE `%PREFIX%messages` SET `message_text` = REPLACE(`message_text`, 'game?page=raport&raport=', 'report/') WHERE `message_text` LIKE '%game?page=raport&raport=%';
UPDATE `%PREFIX%messages` SET `message_text` = REPLACE(`message_text`, 'CombatReport?raport=', 'report/') WHERE `message_text` LIKE '%CombatReport?raport=%';
UPDATE `%PREFIX%messages` SET `message_text` = REPLACE(`message_text`, '?page=alliance&mode=admin&action=manageApply', 'game/alliance/admin/applications') WHERE `message_text` LIKE '%?page=alliance&mode=admin&action=manageApply%';
UPDATE `%PREFIX%messages` SET `message_text` = REPLACE(`message_text`, '?page=alliance&mode=admin&action=diplomacy', 'game/alliance/admin/diplomacy') WHERE `message_text` LIKE '%?page=alliance&mode=admin&action=diplomacy%';
