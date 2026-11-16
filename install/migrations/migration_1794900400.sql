-- referral id of a verified registration, applied to the account's first player
ALTER TABLE `%PREFIX%accounts` ADD COLUMN `referralID` int(11) unsigned NOT NULL DEFAULT 0;
