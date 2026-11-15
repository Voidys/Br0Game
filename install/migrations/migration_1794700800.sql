-- clean URLs: stored messages and admin comments link /game, /admin, ... instead of the former *.php
-- (plain and URL-encoded links; the old URLs still redirect, this only tidies stored content)
UPDATE `%PREFIX%messages` SET `message_text` = REGEXP_REPLACE(`message_text`, '(^|[^A-Za-z0-9_])(game|admin|account|CombatReport)\\.php', '$1$2') WHERE `message_text` LIKE '%.php%';
UPDATE `%PREFIX%users_comments` SET `comment` = REGEXP_REPLACE(`comment`, '(^|[^A-Za-z0-9_])(game|admin|account|CombatReport)\\.php', '$1$2') WHERE `comment` LIKE '%.php%';
