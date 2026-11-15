<?php

require_once __DIR__.'/vendor/autoload.php';

use App\Game\Core\Database;

define('MODE', 'UPGRADE');
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)) . '/');
set_include_path(ROOT_PATH);

\App\Legacy\GameBootstrap::run();

try {
    $db = Database::get();

    $sql = "ALTER TABLE %%USERS_SETTINGS%%
        ADD COLUMN `css_dpath` varchar(20) NOT NULL DEFAULT 'nova' AFTER `dpath`;";
    $db->nativeQuery($sql);

    $sql = "ALTER TABLE %%ACCOUNTS%%
        ADD COLUMN `css_dpath` varchar(20) NOT NULL DEFAULT 'nova' AFTER `dpath`;";
    $db->nativeQuery($sql);


    $userSettings = $db->select("SELECT user_id, dpath FROM %%USERS_SETTINGS%%;");

    foreach ($userSettings as $setting) {
        $sql = "UPDATE %%USERS_SETTINGS%% SET `dpath` = :dpath, `css_dpath` = :css_dpath WHERE `user_id` = :userId;";

        $setting['css_dpath'] = $setting['dpath'];

        if ($setting['dpath'] === 'community') {
            $setting['css_dpath'] = 'nova';
        }

        if ($setting['dpath'] === 'community pr0gress') {
            $setting['dpath'] = 'community';
            $setting['css_dpath'] = 'pr0gress';
        }

        $db->update($sql, [
            ':dpath' => $setting['dpath'],
            ':css_dpath' => $setting['css_dpath'],
            ':userId' => $setting['user_id'],
        ]);
    }

    $accountSettings = $db->select("SELECT id, dpath FROM %%ACCOUNTS%%;");

    foreach ($accountSettings as $setting) {
        $sql = "UPDATE %%ACCOUNTS%% SET `dpath` = :dpath, `css_dpath` = :css_dpath WHERE `id` = :id;";

        $setting['css_dpath'] = $setting['dpath'];

        if ($setting['dpath'] === 'community') {
            $setting['css_dpath'] = 'nova';
        }

        if ($setting['dpath'] === 'community pr0gress') {
            $setting['dpath'] = 'community';
            $setting['css_dpath'] = 'pr0gress';
        }
        $db->update($sql, [
            ':dpath' => $setting['dpath'],
            ':css_dpath' => $setting['css_dpath'],
            ':id' => $setting['id'],
        ]);
    }


} catch (\Throwable $th) {
    error_log('Error: ' . $th->getMessage() . '.');
}
