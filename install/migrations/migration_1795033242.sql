-- rename br0game -> v0game: default theme folder, game name and project links of existing installs.
UPDATE `%PREFIX%accounts` SET `dpath` = 'v0game' WHERE `dpath` = 'br0game';
UPDATE `%PREFIX%accounts` SET `css_dpath` = 'v0game' WHERE `css_dpath` = 'br0game';
UPDATE `%PREFIX%users_settings` SET `dpath` = 'v0game' WHERE `dpath` = 'br0game';
UPDATE `%PREFIX%users_settings` SET `css_dpath` = 'v0game' WHERE `css_dpath` = 'br0game';
UPDATE `%PREFIX%config` SET `value` = 'v0game' WHERE `key` = 'default_theme' AND `value` = 'br0game';
UPDATE `%PREFIX%config` SET `value` = 'V0Game' WHERE `key` = 'game_name' AND `value` = 'Br0Game';
UPDATE `%PREFIX%config` SET `value` = REPLACE(`value`, 'github.com/Voidys/Br0Game', 'github.com/Voidys/V0Game') WHERE `key` IN ('source_url', 'git_issues_link');
ALTER TABLE `%PREFIX%accounts` ALTER `dpath` SET DEFAULT 'v0game', ALTER `css_dpath` SET DEFAULT 'v0game';
ALTER TABLE `%PREFIX%users_settings` ALTER `dpath` SET DEFAULT 'v0game', ALTER `css_dpath` SET DEFAULT 'v0game';
