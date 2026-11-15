-- Voting feature
ALTER TABLE `%PREFIX%voting_sites` ADD COLUMN `check_url` VARCHAR(100) NOT NULL DEFAULT '' AFTER `url`;
UPDATE `%PREFIX%voting_sites` SET `check_url` = 'https://private-server.ws/index.php?a=in&u=Pr0gamePfeffi&ipc={IP}', `interval` = 0 WHERE `id` = 2; 