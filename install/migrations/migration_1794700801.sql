-- clean URLs, part 2: URL-encoded links in admin comments (%2Fgame.php%3F...)
UPDATE `%PREFIX%users_comments` SET `comment` = REGEXP_REPLACE(`comment`, '%2F(game|admin|account|CombatReport)\\.php', '%2F$1') WHERE `comment` LIKE '%.php%';
