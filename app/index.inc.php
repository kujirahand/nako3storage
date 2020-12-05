<?php

include_once dirname(__FILE__) . '/n3s_lib.inc.php';

n3s_main();

function n3s_main()
{
    global $n3s_config;
    n3s_check_config();
    n3s_init_db();
    n3s_parseURI();
    n3s_action();
}

function n3s_check_config()
{
    global $n3s_config;
    $root = dirname(dirname(__FILE__));
    $def_values = array(
        "dir_data" => "{$root}/data",
        "dir_app" => "{$root}/app",
        "dir_template" => "{$root}/app/template",
        'dir_cache' => $root.'/cache',
        "dir_action" => "{$root}/app/action",
        "dir_sql" => "{$root}/app/sql",
        "file_db_main" => "sqlite:{$root}/data/n3s_main.sqlite",
        "file_db_material" => "sqlite:{$root}/data/n3s_material.sqlite",
        "admin_password" => "hoge",
        "size_source_max" => 1024 * 1024 * 5, // 最大保存サイズ3MB
        "size_field_max" => 1024 * 5,        // 最大フィールドサイズ3KB
        "page_title" => "nako3storage v".N3S_APP_VERSION,
        "search_word" => "",
        "n3s_css_mtime" => filemtime("$root/skin/def/n3s.css"),
    );
    foreach ($def_values as $key => $def) {
        if (empty($n3s_config[$key])) $n3s_config[$key] = $def;
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
    if (file_exists($file_action)) {
        include_once $file_action;
        if (function_exists($func_action)) {
            call_user_func($func_action);
            return;
        }
    }
    echo $file_action;
    echo 'action error';
    exit;
}

function n3s_init_db()
{
    global $n3s_config;
    $file_db_version = $n3s_config['dir_data'] . '/db_version.conf';
    $flag_init = !file_exists($file_db_version);
    if (!$flag_init) {
        // Check version
        $ver = file_get_contents($file_db_version);
        if ($ver != N3S_DB_VERSION) {
            throw new Exception('Sorry, nako3storage.db version not match.');
        }
        return;
    }
    // Initialize Database
    $dblist = array("main", "material");
    foreach ($dblist as $type) {
        $db = n3s_get_db($type);
        $file_init_sql = $n3s_config['dir_sql'] . "/init-{$type}.sql";
        $init_sql = file_get_contents($file_init_sql);
        $sqls = explode(';', $init_sql);
        foreach ($sqls as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                echo "[DB ERROR] " . $e->getMessage();
                exit;
            }
        }
    }
    file_put_contents($file_db_version, N3S_DB_VERSION);
    echo "Initialized database ... please reload page.";
    exit;
}
