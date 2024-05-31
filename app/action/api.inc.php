<?php
//------------------------------------------------------------------
// API for nako3storage
//------------------------------------------------------------------

// for clickjacking
header('X-Frame-Options: SAMEORIGIN');
define('KEY_ASTORAGE', 'astorage');

function n3s_web_api()
{
    echo "not supported";
}
function n3s_api_api()
{
    // get parametes
    $api_token = isset($_GET['token']) ? $_GET['token'] : '';
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    $user_id = intval(isset($_GET['user_id']) ? $_GET['user_id'] : '0');

    // echo $app_id.":". $api_token . ":" . $page;
    // check token
    if ($api_token === '') {
        api_error('token is empty'); exit;
    }

    // is_logined
    if ($page === 'is_logined') {
        $r = n3s_is_login();
        n3s_api_output($r, ["logined"=>$r]);
        exit;
    }
    // username_get
    if ($page === 'username_get') {
        $r = n3s_getUserInfo($user_id);
        if ($r) {
            n3s_api_output(true, ["name" => $r['name']]);
        } else {
            n3s_api_output(false, ["reason"=>"invalid user_id"]);
        }
        exit;
    }

    // get common info
    $app_id = isset($_SESSION["api_token::$api_token"]) ? $_SESSION["api_token::$api_token"] : -1;
    if ($app_id === -1) {
        api_error('invalid token');
        exit;
    }

    // call method
    $method = "n3s_api__{$page}__";
    if (function_exists($method)) {
        call_user_func_array($method, [[
            'app_id' => $app_id,
            'api_token' => $api_token,
            'page' => $page,
        ]]);
        exit;
    }
    api_error("no page");
}

function n3s_api_astorage_db()
{
    $dir_sql = n3s_get_config('dir_sql', dirname(__DIR__)."/sql");

    // get db path for dir_astorage
    $user_id = n3s_get_user_id();
    $dir_astorage = n3s_get_config('dir_astorage', '');
    if (!file_exists($dir_astorage)) {
        api_error('[SYSTEM ERROR] dir_astorage could not write...');
        exit;
    }
    $user_id_pad = str_pad($user_id, 4, '0', STR_PAD_LEFT);
    $dbPath = $dir_astorage . "/{$user_id_pad}.sqlite3";
    database_set("sqlite:$dbPath", "$dir_sql/astorage.sql", KEY_ASTORAGE);
    return database_get(KEY_ASTORAGE);
}

function n3s_api__astorage_keys__($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db();
    $kv = db_get("SELECT * FROM items WHERE app_id=?", [$app_id], KEY_ASTORAGE);
    $keys = [];
    foreach ($kv as $row) {
        $keys[] = $row["key"];
    }
    n3s_api_output(true, ['keys' => $keys]);
    exit;
}

function n3s_api__astorage_put__($params)
{
    $app_id = $params['app_id'];
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    $val = isset($_GET['value']) ? $_GET['value'] : '';
    n3s_api_astorage_db();
    db_exec("DELETE FROM items WHERE app_id=? AND key=?", [$app_id, $key], KEY_ASTORAGE);
    db_exec("INSERT INTO items (app_id, key, value, mtime) VALUES (?, ?, ?, ?)", [$app_id, $key, $val, time()], KEY_ASTORAGE);
    n3s_api_output(true, ['message' => "saved."]);
    exit;

}

function n3s_api__astorage_get__($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db();
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    $default = isset($_GET['default']) ? $_GET['default'] : '';
    $r = db_get1("SELECT * FROM items WHERE app_id=? AND key=?", [$app_id, $key], KEY_ASTORAGE);
    if ($r === false || $r === null) {
        n3s_api_output(true, ['value' => $default]);
    } else {
        n3s_api_output(true, [
            'value' => $r['value'],
            'mtime' => $r['mtime'],
        ]);
    }
}

function api_error($msg)
{
    n3s_api_output(false, ['reason' => $msg]);
    exit;
}

function file_save($filename, $data)
{
    // ファイルを書き込みモードで開く
    $file = fopen($filename, 'w');

    // ファイルロックの取得（排他ロック）
    if (flock($file, LOCK_EX)) {
        // データをファイルに書き込む
        fwrite($file, $data);

        // ロックを解除
        flock($file, LOCK_UN);
    } else {
        // ロックが取得できなかった場合はエラーを表示
        n3s_api_output(false, ['reason' => 'ロックを取得できませんでした。']);
    }

    // ファイルを閉じる
    fclose($file);
}

function file_load($filename)
{
    // ファイルを読み込みモードで開く
    $file = fopen($filename, 'r');

    // ファイルロックの取得（共有ロック）
    if (flock($file, LOCK_SH)) {
        // ファイルからデータを読み込む
        $data = fread($file, filesize($filename));

        // ロックを解除
        flock($file, LOCK_UN);
    } else {
        // ロックが取得できなかった場合はエラーを表示
        n3s_api_output(false, ['reason' => 'ロックを取得できませんでした。']);
        $data = false;
    }

    // ファイルを閉じる
    fclose($file);

    return $data;
}
