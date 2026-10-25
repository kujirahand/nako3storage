<?php
// CLIからの実行専用 (Web経由で未認証に実行できないようにする, #284)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: command line only.');
}
// for command line
$_SERVER['REQUEST_URI'] = empty($_SERVER['REQUEST_URI']) ? __FILE__ : $_SERVER['REQUEST_URI'];

// デフォルトを読む
require_once __DIR__.'/app/n3s_config.def.php';
// デフォルトとの差分を指定する
$config_file = __DIR__."/n3s_config.ini.php";
if (file_exists($config_file)) include_once($config_file);
// ライブラリを読む
require_once __DIR__ . '/app/n3s_lib.inc.php';
// update all
n3s_db_init();
n3s_nadesiko3hub_update_all();
