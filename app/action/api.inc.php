<?php
//------------------------------------------------------------------
// API for nako3storage
//------------------------------------------------------------------

// for clickjacking
header('X-Frame-Options: SAMEORIGIN');
define('AS_USER', 'as_user');
define('AS_APP', 'as_app');

function n3s_web_api()
{
    echo "not supported";
}
function n3s_api_api()
{
    // get parametes
    $api_token = isset($_REQUEST['token']) ? $_REQUEST['token'] : '';
    $page = isset($_REQUEST['page']) ? $_REQUEST['page'] : '';
    $user_id = intval(isset($_REQUEST['user_id']) ? $_REQUEST['user_id'] : '0');

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
    // get_user
    if ($page === 'get_user') {
        if ($user_id <= 0) {
            $user_id = n3s_get_user_id();
        }
        $r = n3s_getUserInfo($user_id);
        if ($r) {
            n3s_api_output(true, [
                "user_id" => $r['user_id'],
                "name" => $r['name'],
            ]);
        } else {
            n3s_api_output(false, ["reason"=>"無効なユーザーID"]);
        }
        exit;
    }

    // get common info
    $user_id = n3s_get_user_id();
    if ($user_id <= 0) {
        api_error('トークンが無効です。ログインしてください。');
        exit;
    }
    $app_id = isset($_SESSION["api_token::$api_token"]) ? $_SESSION["api_token::$api_token"] : -1;
    if ($app_id === -1) {
        api_error('トークンが無効です。ログインしてください。');
        exit;
    }

    // call method
    $method = "n3s_api__{$page}";
    if (function_exists($method)) {
        call_user_func_array($method, [[
            'app_id' => $app_id,
            'api_token' => $api_token,
            'page' => $page,
            'user_id' => $user_id
        ]]);
        exit;
    }
    api_error("no page");
}

function n3s_api_astorage_db($app_id)
{
    $dir_sql = n3s_get_config('dir_sql', dirname(__DIR__)."/sql");

    // get db path for dir_astorage
    $user_id = n3s_get_user_id();
    $dir_astorage = n3s_get_config('dir_astorage', '');
    if (!file_exists($dir_astorage)) {
        api_error('[SYSTEM ERROR] dir_astorage could not write...');
        exit;
    }
    // check dir
    $dir_user = $dir_astorage."/users";
    if (!file_exists($dir_user)) {
        mkdir($dir_user, 0777, true);
    }
    $dir_apps = $dir_astorage."/apps";
    if (!file_exists($dir_apps)) {
        mkdir($dir_apps, 0777, true);
    }
    // create user db
    $user_id_pad = str_pad($user_id, 6, '0', STR_PAD_LEFT);
    $dbPathUser = $dir_user . "/user{$user_id_pad}.sqlite3";
    database_set("sqlite:$dbPathUser", "$dir_sql/astorage_user.sql", AS_USER);
    // create app db
    $app_id_pad = str_pad($app_id, 6, '0', STR_PAD_LEFT);
    $dbPathApp = $dir_apps . "/app{$app_id_pad}.sqlite3";
    database_set("sqlite:$dbPathApp", "$dir_sql/astorage_app.sql", AS_APP);
    return database_get(AS_USER);
}

// as user key
function n3s_api__list_key_as_user($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $kv = db_get("SELECT key FROM keys WHERE app_id=?", [$app_id], AS_USER);
    $keys = [];
    foreach ($kv as $row) {
        $keys[] = $row["key"];
    }
    n3s_api_output(true, [
        'keys' => $keys,
    ]);
    exit;
}

function n3s_api__set_key_as_user($params)
{
    $app_id = $params['app_id'];
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $val = isset($_REQUEST['value']) ? $_REQUEST['value'] : '';
    n3s_api_astorage_db($app_id);
    db_exec("DELETE FROM keys WHERE app_id=? AND key=?", [$app_id, $key], AS_USER);
    db_exec("INSERT INTO keys (app_id, key, value, mtime) VALUES (?, ?, ?, ?)", [$app_id, $key, $val, time()], AS_USER);
    n3s_api_output(true, ['message' => "saved."]);
    exit;

}

function n3s_api__get_key_as_user($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $r = db_get1("SELECT * FROM keys WHERE app_id=? AND key=?", [$app_id, $key], AS_USER);
    if ($r === false || $r === null) {
        n3s_api_output(true, ['value' => null, 'mtime' => 0]);
    } else {
        n3s_api_output(true, [
            'value' => $r['value'],
            'mtime' => $r['mtime'],
        ]);
    }
}

function n3s_api__delete_key_as_user($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $r = db_exec("DELETE FROM keys WHERE app_id=? AND key=?", [$app_id, $key], AS_USER);
    if ($r) {
        n3s_api_output(true, ["message"=>"deleted."]);
    } else {
        n3s_api_output(false, ["message" => "error"]);
    }
}

function n3s_api__deleteall_key_as_user($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $r = db_exec("DELETE FROM keys WHERE app_id=?", [$app_id], AS_USER);
    if ($r) {
        n3s_api_output(true, ["message" => "deleted all."]);
    } else {
        n3s_api_output(false, ["message" => "error"]);
    }
}

// as user items
function n3s_api__insert_item_as_user($params)
{
    $app_id = $params['app_id'];
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $val = isset($_REQUEST['value']) ? $_REQUEST['value'] : '';
    n3s_api_astorage_db($app_id);
    $item_id = db_insert(
        "INSERT INTO items (app_id, key, value, ctime, mtime) VALUES (?, ?, ?, ?, ?)", [
            $app_id, $key, $val, time(), time()], AS_USER);
    n3s_api_output(true, ['item_id' => $item_id]);
    exit;
}

function n3s_api__select_items_as_user($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $offset = isset($_REQUEST['offset']) ? intval($_REQUEST['offset']) : 0;
    $limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 30;
    if ($limit > 30) { $limit = 30; }
    $sort = isset($_REQUEST['sort']) ? strtoupper($_REQUEST['sort']) : 'ASC';
    if ($sort == 'ASC' || $sort == 'DESC') {
        $sort = " ORDER BY item_id $sort";
    } else {
        $sort = " ORDER BY item_id ASC";
    }
    $items = [];
    $r = db_get("SELECT * FROM items WHERE app_id=? AND key=? LIMIT ?,?", [$app_id, $key, $offset, $limit], AS_USER);
    if ($r === false || $r === null) {
        // no data
    } else {
        foreach ($r as $row) {
            $items[] = [
                'item_id' => $row['item_id'],
                'value' => $row['value'],
                'mtime' => $row['mtime'],
            ];
        }
    }
    n3s_api_output(true, ["values" => $items]);
}

function n3s_api__delete_item_as_user($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $item_id = isset($_REQUEST['item_id']) ? intval($_REQUEST['item_id']) : 0;
    if ($item_id <= 0) {
        n3s_api_output(false, ["message" => "item_id is invalid"]);
        exit;
    }
    $r = db_exec("DELETE FROM items WHERE app_id=? AND key=? AND item_id=?", [$app_id, $key, $item_id], AS_USER);
    if ($r) {
        n3s_api_output(true, ["message" => "deleted."]);
    } else {
        n3s_api_output(false, ["message" => "error"]);
    }
}

function n3s_api__update_item_as_user($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $item_id = isset($_REQUEST['item_id']) ? intval($_REQUEST['item_id']) : 0;
    $value = isset($_REQUEST['value']) ? $_REQUEST['value'] : '';
    if ($item_id <= 0) {
        n3s_api_output(false, ["message" => "item_id is invalid"]);
        exit;
    }
    $r = db_exec("UPDATE items SET value=? WHERE app_id=? AND key=? AND item_id=?", [$value, $app_id, $key, $item_id], AS_USER);
    if ($r) {
        n3s_api_output(true, ["message" => "deleted."]);
    } else {
        n3s_api_output(false, ["message" => "error"]);
    }
}

// as app key
function n3s_api__list_key_as_app($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $kv = db_get("SELECT key FROM keys WHERE app_id=?", [$app_id], AS_APP);
    $keys = [];
    foreach ($kv as $row) {
        $keys[] = $row["key"];
    }
    n3s_api_output(true, [
        'keys' => $keys,
    ]);
    exit;
}

function n3s_api__set_key_as_app($params)
{
    $app_id = $params['app_id'];
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $val = isset($_REQUEST['value']) ? $_REQUEST['value'] : '';
    n3s_api_astorage_db($app_id);
    db_exec("DELETE FROM keys WHERE app_id=? AND key=?", [$app_id, $key], AS_APP);
    db_exec("INSERT INTO keys (app_id, key, value, mtime) VALUES (?, ?, ?, ?)", [$app_id, $key, $val, time()], AS_APP);
    n3s_api_output(true, ['message' => "saved."]);
    exit;
}

function n3s_api__get_key_as_app($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $r = db_get1("SELECT * FROM keys WHERE app_id=? AND key=?", [$app_id, $key], AS_APP);
    if ($r === false || $r === null) {
        n3s_api_output(true, ['value' => null, 'mtime' => 0]);
    } else {
        n3s_api_output(true, [
            'value' => $r['value'],
            'mtime' => $r['mtime'],
        ]);
    }
}

function n3s_api__delete_key_as_app($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $r = db_exec("DELETE FROM keys WHERE app_id=? AND key=?", [$app_id, $key], AS_APP);
    if ($r) {
        n3s_api_output(true, ["message" => "deleted."]);
    } else {
        n3s_api_output(false, ["message" => "error"]);
    }
}

function n3s_api__deleteall_key_as_app($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $r = db_exec("DELETE FROM keys WHERE app_id=?", [$app_id], AS_USER);
    if ($r) {
        n3s_api_output(true, ["message" => "deleted all."]);
    } else {
        n3s_api_output(false, ["message" => "error"]);
    }
}

// as app items
function n3s_api__insert_item_as_app($params)
{
    $app_id = $params['app_id'];
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $val = isset($_REQUEST['value']) ? $_REQUEST['value'] : '';
    n3s_api_astorage_db($app_id);
    $item_id = db_insert(
        "INSERT INTO items (app_id, key, value, ctime, mtime) VALUES (?, ?, ?, ?, ?)",
        [
            $app_id, $key, $val, time(), time()
        ],
        AS_APP
    );
    n3s_api_output(true, ['item_id' => $item_id]);
    exit;
}

function n3s_api__select_items_as_app($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $offset = isset($_REQUEST['offset']) ? intval($_REQUEST['offset']) : 0;
    $limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 30;
    if ($limit > 30) {
        $limit = 30;
    }
    $sort = isset($_REQUEST['sort']) ? strtoupper($_REQUEST['sort']) : 'ASC';
    if ($sort == 'ASC' || $sort == 'DESC') {
        $sort = " ORDER BY item_id $sort";
    } else {
        $sort = " ORDER BY item_id ASC";
    }
    $items = [];
    $r = db_get("SELECT * FROM items WHERE app_id=? AND key=? LIMIT ?,?", [$app_id, $key, $offset, $limit], AS_APP);
    if ($r === false || $r === null) {
        // no data
    } else {
        foreach ($r as $row) {
            $items[] = [
                'item_id' => $row['item_id'],
                'value' => $row['value'],
                'mtime' => $row['mtime'],
            ];
        }
    }
    n3s_api_output(true, ["values" => $items]);
}

function n3s_api__delete_item_as_app($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $item_id = isset($_REQUEST['item_id']) ? intval($_REQUEST['item_id']) : 0;
    if ($item_id <= 0) {
        n3s_api_output(false, ["message" => "item_id is invalid"]);
        exit;
    }
    $r = db_exec("DELETE FROM items WHERE app_id=? AND key=? AND item_id=?", [$app_id, $key, $item_id], AS_APP);
    if ($r) {
        n3s_api_output(true, ["message" => "deleted."]);
    } else {
        n3s_api_output(false, ["message" => "error"]);
    }
}

function n3s_api__update_item_as_app($params)
{
    $app_id = $params['app_id'];
    n3s_api_astorage_db($app_id);
    $key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
    $item_id = isset($_REQUEST['item_id']) ? intval($_REQUEST['item_id']) : 0;
    $value = isset($_REQUEST['value']) ? $_REQUEST['value'] : '';
    if ($item_id <= 0) {
        n3s_api_output(false, ["message" => "item_id is invalid"]);
        exit;
    }
    $r = db_exec("UPDATE items SET value=? WHERE app_id=? AND key=? AND item_id=?", [$value, $app_id, $key, $item_id], AS_APP);
    if ($r) {
        n3s_api_output(true, ["message" => "deleted."]);
    } else {
        n3s_api_output(false, ["message" => "error"]);
    }
}

// 
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
