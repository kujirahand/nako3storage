<?php
include_once dirname(__FILE__) . '/n3s_lib.inc.php';

n3s_main();


function n3s_main()
{
    n3s_check_config();
    n3s_parseURI();
    n3s_action();
}

function n3s_check_config()
{
    global $n3s_config;
    $root = dirname(dirname(__FILE__));
    $url_root = dirname($_SERVER['REQUEST_URI']);
    $def_values = array(
        "admin_users" => [1],
        "admin_contact_link" => "(Please set admin_contact_link in config file.)",
        "dir_data" => "{$root}/data",
        "dir_images" => "{$root}/images",
        "url_images" => "{$url_root}/images",
        "dir_app" => "{$root}/app",
        "dir_template" => "{$root}/app/template",
        'dir_cache' => $root.'/cache',
        "dir_action" => "{$root}/app/action",
        "dir_sql" => "{$root}/app/sql",
        "file_db_main" => "sqlite:{$root}/data/n3s_main.sqlite",
        "file_db_material" => "sqlite:{$root}/data/n3s_material.sqlite",
        "size_source_max" => 1024 * 1024 * 3, // 最大保存サイズ3MB
        "size_field_max" => 1024 * 3,        // 最大フィールドサイズ3KB
        "size_upload_max" => 1024 * 1024 * 3, // 最大アップロードサイズ
        "page_title" => "nako3storage",
        "search_word" => "",
        "n3s_css_mtime" => filemtime("$root/skin/def/n3s.css"),
        "nako3storage_version" => N3S_APP_VERSION,
        // for twitter login
        "twitter_api_key" => "",
        "twitter_api_secret" => "",
        "twitter_acc_token" => "",
        "twitter_acc_secret" => "",
    );
    foreach ($def_values as $key => $def) {
        if (!isset($n3s_config[$key])) $n3s_config[$key] = $def;
    }
}

function n3s_action()
{
    global $n3s_config;
    $action = $n3s_config['action'];
    $action = preg_replace('/([a-zA-Z0-9_]+)/', '$1', $action);
    $file_action = $n3s_config['dir_action'] . "/$action.inc.php";
    $agent = $n3s_config['agent'];
    $func_action = "n3s_{$agent}_{$action}";

    // DBを初期化
    n3s_get_db();

    // WEBであればセッションを使う
    if ($agent === 'web') {
        session_start();
    }
    if (file_exists($file_action)) {
        include_once $file_action;
        if (function_exists($func_action)) {
            call_user_func($func_action);
            return;
        }
    }
    // アクションがなければエラーを表示
    n3s_error('不正なページ', 'アクションが見当たりません。');
    exit;
}
