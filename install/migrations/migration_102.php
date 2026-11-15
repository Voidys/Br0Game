<?php

require_once __DIR__.'/vendor/autoload.php';

define('MODE', 'UPGRADE');
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)) . '/');
set_include_path(ROOT_PATH);

if (!is_dir(ROOT_PATH.'cache/languages/')) {
    mkdir(ROOT_PATH.'cache/languages/');
}
