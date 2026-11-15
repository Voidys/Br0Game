ALTER TABLE `%PREFIX%news`
  MODIFY `title` varchar(160) NOT NULL,
  ADD `summary` varchar(300) NOT NULL DEFAULT '' AFTER `title`,
  ADD `status` tinyint NOT NULL DEFAULT 1,
  ADD `pinned` tinyint NOT NULL DEFAULT 0,
  ADD `updated_at` int NOT NULL DEFAULT 0,
  ADD `format` varchar(8) NOT NULL DEFAULT 'html',
  ADD `image` varchar(255) NOT NULL DEFAULT '',
  ADD KEY `status_pinned_date` (`status`, `pinned`, `date`);
UPDATE `%PREFIX%news` SET `format` = 'text';
