ALTER TABLE `%PREFIX%users_to_achievements`
  ADD INDEX idx_user_achievement (userID, achievementID);

DELETE ua1 FROM `%PREFIX%users_to_achievements` ua1
    INNER JOIN `%PREFIX%users_to_achievements` ua2
        ON ua1.userID = ua2.userID
        AND ua1.achievementID = ua2.achievementID
        AND ua1.id > ua2.id;

ALTER TABLE `%PREFIX%users_to_achievements`
    DROP INDEX idx_user_achievement,
    ADD UNIQUE KEY `user_achievement` (`userID`, `achievementID`);
