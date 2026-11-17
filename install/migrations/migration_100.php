<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Game\Core\Database;

define('MODE', 'UPGRADE');
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)) . '/');
set_include_path(ROOT_PATH);

\App\Legacy\GameBootstrap::run();

try {
    $db = Database::get();

    $sql = "SELECT * FROM %%MESSAGES%% where `message_time` < 1747079822";
    $messages = $db->select($sql);

    $sql = "INSERT INTO %%USERS_TO_MESSAGES%% SET 
        `message_id`        = :message_id,
        `user_id`           = :user_id,
        `sender_id`         = :sender_id,
        `forwarder_id`      = :forwarder_id,
        `message_unread`    = :message_unread,
        `created_at`        = :created_at,
        `deleted_at`        = :deleted_at;";

    foreach ($messages as $message) {
        // receiver
        $db->insert($sql, [
            ':message_id'       => $message['message_id'],
            ':user_id'          => $message['message_owner'],
            ':sender_id'        => $message['message_sender'] === 0 ? null : $message['message_sender'],
            ':forwarder_id'     => null,
            ':message_unread'   => $message['message_unread'],
            ':created_at'       => $message['message_time'],
            ':deleted_at'       => $message['message_deleted'],
        ]);

        // sender
        if ($message['message_type'] === MESSAGE_TYPE_USER and $message['message_sender'] != $message['message_owner']) {
            $db->insert($sql, [
                ':message_id'       => $message['message_id'],
                ':user_id'          => $message['message_sender'],
                ':sender_id'        => $message['message_sender'],
                ':forwarder_id'     => null,
                ':message_unread'   => 0,
                ':created_at'       => $message['message_time'],
                ':deleted_at'       => null,
            ]);
        }
    }
} catch (\Throwable $th) {
    error_log('Error: ' . $th->getMessage() . '.');
}
