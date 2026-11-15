ALTER TABLE `%PREFIX%users_voting`
  ADD COLUMN `canVote` SMALLINT NOT NULL DEFAULT '1' AFTER `votingTime`,
  ADD COLUMN `checkTime` int(11) NOT NULL DEFAULT '0' AFTER `canVote`;
