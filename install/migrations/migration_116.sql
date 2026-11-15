-- add decimal_separator column to users_settings for number formatting
ALTER TABLE `%PREFIX%users_settings`
    ADD `decimal_separator` tinyint(1) NOT NULL DEFAULT '0';
