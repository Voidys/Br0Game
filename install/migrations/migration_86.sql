-- adds tutorial functions
-- define tutorial steps
CREATE TABLE `%PREFIX%tutorials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL, -- human readable name/description
  `translation_key` varchar(255) DEFAULT NULL, -- key for translation files
  `order` int(11) NOT NULL, -- order by number, hint: use 10 steps to add steps in between later
  `support_class` varchar(255) DEFAULT 'DefaultTutorialSupport', -- class for checking goals and rewarding, overwrite for non vars goals/rewards
  `skippable` tinyint unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

--  disable a tutorial step per universe, if no enty it can be enabled depending on tutorials table entry
CREATE TABLE `%PREFIX%tutorial_universe` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tutorialID` int(11) NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- add goals to tutorial steps
CREATE TABLE `%PREFIX%tutorial_goals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tutorialID` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `elementID` smallint(5) unsigned NOT NULL, -- does not need to be in table vars, if non standard support_class is used
  `ammount` smallint(5) unsigned NOT NULL,
  `skippable` tinyint unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- add rewards to tutorial steps
CREATE TABLE `%PREFIX%tutorial_rewards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tutorialID` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `elementID` smallint(5) unsigned NOT NULL, -- does not need to be in table vars, if non standard support_class is used
  `ammount` smallint(5) unsigned NOT NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- users can skipp or claim rewards of tutorial steps
CREATE TABLE `%PREFIX%users_tutorial` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userID` int(11) NOT NULL,
  `tutorialID` int(11) NOT NULL,
  `completed` tinyint unsigned NOT NULL DEFAULT 0,
  `claimed` tinyint unsigned NOT NULL DEFAULT 0,
  `skipped` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- users can skipp tutorial step goals
CREATE TABLE `%PREFIX%users_tutorial_goal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userID` int(11) NOT NULL,
  `tutorialGoalID` int(11) NOT NULL,
  `fullfilled` tinyint unsigned NOT NULL DEFAULT 0,
  `skipped` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


INSERT INTO `%PREFIX%tutorials` (`name`, `translation_key`, `order`, `support_class`, `skippable`, `enabled`) VALUES
('Tutorial intro', 'step0', 5, 'DefaultTutorialSupport', 0, 1),
('Mines 1', 'step1', 10, 'DefaultTutorialSupport', 0, 1),
('Discord', 'step2', 20, 'LinkTutorialSupport', 1, 1),
('Defence', 'step3', 30, 'DefaultTutorialSupport', 0, 1),
('Mines 2', 'step4', 40, 'DefaultTutorialSupport', 0, 1),
('Ships', 'step5', 50, 'DefaultTutorialSupport', 0, 1),
('Name & friends', 'step6', 60, 'RenameTutorialSupport', 0, 1),
('Spionage', 'step7', 70, 'MissionTutorialSupport', 0, 1),
('Expedition', 'step8', 80, 'MissionTutorialSupport', 0, 1),
('Kolonies', 'step9', 90, 'MissionTutorialSupport', 0, 1),
('Attack', 'step10', 100, 'MissionTutorialSupport', 0, 1),
('Recycle', 'step11', 110, 'MissionTutorialSupport', 0, 1),
('Outro', 'step12', 120, 'DefaultTutorialSupport', 0, 1);

INSERT INTO `%PREFIX%tutorial_goals` (`tutorialID`, `name`, `elementID`, `ammount`, `skippable`, `enabled`) VALUES
(2, 'MetMine', 1, 5, 0, 1),
(2, 'KrisMine', 2, 2, 0, 1),
(2, 'SolPower', 4, 4, 0, 1),
(3, 'Discord', 1, 1, 1, 1),
(4, 'DeutMine', 3, 2, 0, 1),
(4, 'Robo', 14, 2, 0, 1),
(4, 'Shipyard', 21, 1, 0, 1),
(4, 'ML', 401, 1, 0, 1),
(5, 'MetMine', 1, 10, 0, 1),
(5, 'KrisMine', 2, 7, 0, 1 ),
(5, 'DeutMine', 3, 5, 0, 1),
(6, 'ResearchLab', 31, 1, 0, 1),
(6, 'Combustion', 115, 2, 0, 1),
(6, 'KT', 202, 1, 0, 1),
(7, 'Plani Rename', 1, 1, 0, 1),
(7, 'Buddy', 2, 1, 1, 1),
(7, 'Ally', 3, 1, 1, 1),
(8, 'spionieren', 6, 1, 0, 1),
(9, 'expedition', 15, 1, 0, 1),
(10, 'Kolo setzen', 7, 1, 0, 1),
(11, 'att', 1, 1, 1, 1),
(12, 'recycle', 8, 1, 1, 1);

INSERT INTO `%PREFIX%tutorial_rewards` (`tutorialID`, `name`, `elementID`, `ammount`, `enabled`) VALUES
(2, 'Met', 901, 150, 1),
(2, 'Cris', 902, 75, 1),
(4, 'ML', 401, 1, 1),
(5, 'Met', 901, 2000, 1),
(5, 'Cris', 902, 500, 1),
(6, 'Deut', 903, 200, 1),
(8, 'Spio', 210, 2, 1),
(9, 'SJ', 205, 2, 1),
(9, 'KT', 202, 5, 1),
(12, 'Rec', 209, 1, 1);