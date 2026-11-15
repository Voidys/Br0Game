-- adds analytics function to users
ALTER TABLE `%PREFIX%users` ADD `enable_analytics` tinyint NOT NULL DEFAULT 0;

CREATE TABLE `%PREFIX%users_analytics` (
  `id` int(11) unsigned NOT NULL,
  `onlinetime` int(11) NOT NULL DEFAULT '0',
  `url` varchar(255) DEFAULT NULL,
  CONSTRAINT `PK_Analytics` PRIMARY KEY (`id`, `onlinetime`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;