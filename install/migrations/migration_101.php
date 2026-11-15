<?php

require_once __DIR__.'/vendor/autoload.php';

use App\Game\Core\Database;

define('MODE', 'UPGRADE');
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)) . '/');
set_include_path(ROOT_PATH);

\App\Legacy\GameBootstrap::run();

try {
    $db = Database::get();

    $sql = "CREATE TEMPORARY TABLE temp_table AS
        SELECT MIN(id) AS id
        FROM " . DB_PREFIX . "users_to_messages
        GROUP BY message_id, user_id;";
    $db->query($sql);

    $sql = "DELETE FROM %%USERS_TO_MESSAGES%%
        WHERE id NOT IN (SELECT id FROM temp_table);";
    $db->delete($sql);

    $sql = "DROP TEMPORARY TABLE temp_table;";
    $db->query($sql);

    $sql = "UPDATE %%MESSAGES%% SET `message_group` = CONCAT('u_', `message_id`)
        where (`message_group` = 'undefined' OR `message_group` IS NULL)
          AND `message_type` = 1";
    $db->update($sql);

    $sql = "UPDATE %%MESSAGES%% SET `message_group` = CONCAT('m_', `message_id`)
        where (`message_group` = 'undefined' OR `message_group` IS NULL)
          AND `message_type` IN (0, 7, 1, 3, 4, 5, 6)";
    $db->update($sql);
} catch (\Throwable $th) {
    error_log('Error: ' . $th->getMessage() . '.');
}
