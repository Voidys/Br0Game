-- Indexes for per-user lookups that scanned whole tables (account of a user, tutorial progress, unread messages)
ALTER TABLE `%PREFIX%accounts_to_users` ADD KEY `user_id` (`user_id`), ADD KEY `account_id` (`account_id`);
ALTER TABLE `%PREFIX%users_tutorial` ADD KEY `userID` (`userID`, `tutorialID`);
ALTER TABLE `%PREFIX%users_tutorial_goal` ADD KEY `userID` (`userID`, `tutorialGoalID`);
ALTER TABLE `%PREFIX%tutorial_goals` ADD KEY `tutorialID` (`tutorialID`);
ALTER TABLE `%PREFIX%tutorial_rewards` ADD KEY `tutorialID` (`tutorialID`);
ALTER TABLE `%PREFIX%tutorial_universe` ADD KEY `tutorialID` (`tutorialID`, `universe`);
-- unread count of the user loaded on every game request (user_id alone is the prefix of the new key)
ALTER TABLE `%PREFIX%users_to_messages` ADD KEY `user_unread` (`user_id`, `message_unread`, `deleted_at`), DROP KEY `user_id`;
