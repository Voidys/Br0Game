-- added user messages
ALTER TABLE `%PREFIX%messages` ADD `message_group` bigint(20) NULL AFTER `message_id`;

CREATE TABLE `%PREFIX%users_messages` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) UNSIGNED NOT NULL,
  `sender_id` int(11) UNSIGNED NULL,
  `forwarder_id` int(11) UNSIGNED NULL,
  `message_unread` tinyint(1) NOT NULL DEFAULT '1',
  `message_favorite` tinyint(1) NOT NULL DEFAULT '0',
  `message_forwarded` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NULL DEFAULT NULL,
  `deleted_at` int(11) unsigned NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE(`message_id`, `user_id`, `sender_id`, `forwarder_id`),
  FOREIGN KEY (`message_id`) REFERENCES `%PREFIX%messages`(`message_id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  FOREIGN KEY (`user_id`) REFERENCES `%PREFIX%users`(`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  FOREIGN KEY (`sender_id`) REFERENCES `%PREFIX%users`(`id`) ON DELETE SET NULL ON UPDATE NO ACTION,
  FOREIGN KEY (`forwarder_id`) REFERENCES `%PREFIX%users`(`id`) ON DELETE SET NULL ON UPDATE NO ACTION
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `%PREFIX%config` DROP `message_delete_behavior`;