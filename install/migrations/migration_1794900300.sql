UPDATE `%PREFIX%alliance` SET `ally_web` = '' WHERE `ally_web` <> '' AND LOWER(`ally_web`) NOT LIKE 'http://%' AND LOWER(`ally_web`) NOT LIKE 'https://%';
UPDATE `%PREFIX%alliance` SET `ally_image` = '' WHERE `ally_image` <> '' AND LOWER(`ally_image`) NOT LIKE 'http://%' AND LOWER(`ally_image`) NOT LIKE 'https://%';
