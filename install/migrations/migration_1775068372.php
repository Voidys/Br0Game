<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Game\Core\Database;

define('MODE', 'UPGRADE');
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)) . '/');
set_include_path(ROOT_PATH);

\App\Legacy\GameBootstrap::run();

try {
    $db = Database::get();

    $db->nativeQuery("ALTER TABLE `%%AKS%%` ADD `public_id` char(32) NULL AFTER `id`;");

    $acsRows = $db->select("SELECT `id` FROM `%%AKS%%` WHERE `public_id` IS NULL OR `public_id` = '';");
    $usedPublicIds = [];

    foreach ($acsRows as $acsRow) {
        do {
            $publicId = bin2hex(random_bytes(16));
        } while (isset($usedPublicIds[$publicId]));

        $usedPublicIds[$publicId] = true;

        $db->update("UPDATE `%%AKS%%` SET `public_id` = :publicId WHERE `id` = :acsId;", [
            ':publicId' => $publicId,
            ':acsId' => $acsRow['id'],
        ]);
    }

    $db->nativeQuery("ALTER TABLE `%%AKS%%` MODIFY `public_id` char(32) NOT NULL;");
    $db->nativeQuery("ALTER TABLE `%%AKS%%` ADD UNIQUE KEY `public_id` (`public_id`);");
} catch (\Throwable $th) {
    error_log('Error: ' . $th->getMessage() . '.');
}
