<?php

/*
 * Game pages that have been ported to dedicated Symfony controllers.
 *
 * entry script => [legacy ?page= value (lowercase) => Symfony path]
 *
 * Requests for these pages on the original URLs (e.g. index.php?page=rules) are redirected
 * (308, keeps POST) to the Symfony path by App\Controller\GameEntryController; the remaining
 * query parameters are kept.
 */

return [
    'index.php' => [
        'rules' => '/rules',
    ],
];
