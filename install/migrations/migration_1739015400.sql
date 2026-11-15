-- Add wreckfield_cooldown_until field to users table
ALTER TABLE `%PREFIX%users` ADD `wreckfield_cooldown_until` INT(11) unsigned NOT NULL DEFAULT 0;
