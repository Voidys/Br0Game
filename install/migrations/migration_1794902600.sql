UPDATE `%PREFIX%accounts` SET `dpath` = 'br0game' WHERE `dpath` IN ('gow', 'EpicBlueXIII', 'community pr0gress');
UPDATE `%PREFIX%accounts` SET `css_dpath` = 'br0game' WHERE `css_dpath` IN ('gow', 'EpicBlueXIII', 'community pr0gress');
UPDATE `%PREFIX%users_settings` SET `dpath` = 'br0game' WHERE `dpath` IN ('gow', 'EpicBlueXIII', 'community pr0gress');
UPDATE `%PREFIX%users_settings` SET `css_dpath` = 'br0game' WHERE `css_dpath` IN ('gow', 'EpicBlueXIII', 'community pr0gress');
UPDATE `%PREFIX%config` SET `value` = 'br0game' WHERE `key` = 'default_theme' AND `value` IN ('gow', 'EpicBlueXIII', 'community pr0gress');
