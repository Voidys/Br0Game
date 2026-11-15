-- Change comments
ALTER TABLE `%PREFIX%users_comments`
    DROP KEY `comment`,
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,
    ADD `user_id` int(11) unsigned NOT NULL after `id`,
    MODIFY `comment` text,
    ADD `info` text AFTER `comment`,
    ADD PRIMARY KEY `ID` (`id`);

ALTER TABLE `%PREFIX%config`
    ADD `session_handler` varchar(32) NOT NULL DEFAULT 'file' AFTER `ttf_file`,
    ADD `redis_connection_url` varchar(255) NOT NULL DEFAULT '' AFTER `session_handler`;