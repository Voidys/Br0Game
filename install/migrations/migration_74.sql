-- Adds diffrent planet style icons
ALTER TABLE `%PREFIX%planets` ADD `planet_style` tinyint(2) NOT NULL DEFAULT '0' AFTER `image`;
ALTER TABLE `%PREFIX%users` ADD COLUMN `enable_planet_styles` tinyint NOT NULL DEFAULT 1;
