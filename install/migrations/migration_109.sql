-- Voting feature
CREATE TABLE `%PREFIX%voting_sites` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL DEFAULT '',
  `url` VARCHAR(100) NOT NULL DEFAULT '',
  `interval` int(11) NOT NULL DEFAULT '0',
  `reward` int(11) NOT NULL DEFAULT '1',
  `active` SMALLINT NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%users_voting` (
  `userId` int(11) unsigned NOT NULL DEFAULT '0',
  `votingId` int(11) unsigned NOT NULL DEFAULT '0',
  `votingTime` int(11) NOT NULL DEFAULT '0',
  `active` SMALLINT NOT NULL DEFAULT '1',
  PRIMARY KEY (`userId`, `votingId`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `%PREFIX%voting_sites` (`name`, `url`, `interval`, `reward`, `active`) VALUES
('TOPG', 'https://topg.org/ogame-private-servers/server-676720-{ID}', 21600, 1, 1),
('PRIVATE_SERVER_WS', 'https://private-server.ws/index.php?a=in&u=Pr0gamePfeffi&id={ID}', 28800, 1, 1),
('ARENA_TOP100', 'https://www.arena-top100.com/index.php?a=in&u=Pr0game&id={ID}', 43200, 1, 1);