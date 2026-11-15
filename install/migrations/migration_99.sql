-- Change user messages
ALTER TABLE `%PREFIX%messages` MODIFY `message_group` varchar(255) NULL;

RENAME TABLE `%PREFIX%users_messages` TO `%PREFIX%users_to_messages`;