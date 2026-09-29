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

// 貯蔵庫API (アプリ内ストレージ) --- 仕様と制限は docs/api.md を参照 (#194)
// 認証はセッションではなく、実行画面(widget/edit)が発行する署名付きトークンで行う。
// app_id と user_id は必ずトークンから取り出し、リクエストパラメータの値は信用しない。
function n3s_api_api()
{
    $api_token = isset($_REQUEST['token']) ? $_REQUEST['token'] : '';
    $page = isset($_REQUEST['page']) ? $_REQUEST['page'] : '';
    if (!is_string($api_token) || $api_token === '') {
        api_error('token is empty');
    }
    $ctx = n3s_astorage_token_verify($api_token);
    if ($ctx === null) {
        api_error('トークンが無効か期限切れです。作品ページから実行し直してください。');
    }
    if (!is_string($page) || !in_array($page, n3s_astorage_api_pages(), true)) {
        api_error('no page');
    }
    $method = "n3s_api__{$page}";
    call_user_func($method, $ctx);
    exit;
}

// 呼び出し可能なAPI名の一覧 (関数名 n3s_api__{$page} に対応する)
function n3s_astorage_api_pages()
{
    return [
        'is_logined', 'get_user',
        'list_key_as_user', 'get_key_as_user', 'set_key_as_user', 'delete_key_as_user', 'deleteall_key_as_user',
        'insert_item_as_user', 'select_items_as_user', 'delete_item_as_user', 'update_item_as_user',
        'list_key_as_app', 'get_key_as_app', 'set_key_as_app', 'delete_key_as_app', 'deleteall_key_as_app',
        'insert_item_as_app', 'select_items_as_app', 'delete_item_as_app', 'update_item_as_app',
    ];
}

// ログイン状態で発行されたトークン(user_id>0)か
function n3s_api__is_logined($ctx)
{
    $r = ($ctx['user_id'] > 0);
    n3s_api_output($r, ["logined" => $r]);
}

// user_id を指定すればそのユーザー名、省略(0)ならトークンのユーザーを返す
function n3s_api__get_user($ctx)
{
    $user_id = intval(isset($_REQUEST['user_id']) ? $_REQUEST['user_id'] : '0');
    if ($user_id <= 0) {
        $user_id = $ctx['user_id'];
    }
    $r = ($user_id > 0) ? n3s_getUserInfo($user_id) : null;
    if ($r) {
        n3s_api_output(true, [
            "user_id" => $r['user_id'],
            "name" => $r['name'],
        ]);
    } else {
        n3s_api_output(false, ["reason" => "無効なユーザーID"]);
    }
}

function n3s_api_astorage_db($app_id, $user_id)
{
    $dir_sql = n3s_get_config('dir_sql', dirname(__DIR__)."/sql");
    $dir_astorage = n3s_get_config('dir_astorage', '');
    if (!file_exists($dir_astorage)) {
        api_error('[SYSTEM ERROR] dir_astorage could not write...');
    }
    // create user db (ゲストには作らない)
    if ($user_id > 0) {
        $dir_user = $dir_astorage."/users";
        if (!file_exists($dir_user)) {
            mkdir($dir_user, 0777, true);
        }
        $user_id_pad = str_pad($user_id, 6, '0', STR_PAD_LEFT);
        $dbPathUser = $dir_user . "/user{$user_id_pad}.sqlite3";
        database_set("sqlite:$dbPathUser", "$dir_sql/astorage_user.sql", AS_USER);
    }
    // create app db
    $dir_apps = $dir_astorage."/apps";
    if (!file_exists($dir_apps)) {
        mkdir($dir_apps, 0777, true);
    }
    $app_id_pad = str_pad($app_id, 6, '0', STR_PAD_LEFT);
    $dbPathApp = $dir_apps . "/app{$app_id_pad}.sqlite3";
    database_set("sqlite:$dbPathApp", "$dir_sql/astorage_app.sql", AS_APP);
    n3s_astorage_migrate_app_db();
}

// 既存の app DB に書き込んだユーザー(user_id)の列を追加する (#194)
// 追加前の行は user_id=0 となり、作品の作者か管理者だけが変更・削除できる。
function n3s_astorage_migrate_app_db()
{
    foreach (['items', 'keys'] as $table) {
        $cols = db_get("PRAGMA table_info($table)", [], AS_APP);
        $names = array_map(function ($c) { return $c['name']; }, $cols ? $cols : []);
        if (!in_array('user_id', $names, true)) {
            db_exec("ALTER TABLE $table ADD COLUMN user_id INTEGER DEFAULT 0", [], AS_APP);
        }
    }
}

// astorage API の key/value がサイズ上限内かを判定する(副作用なしの純粋関数)。
// (todo-security.md #8: 以前は値サイズが無制限で、ログインユーザーが巨大な文字列を
// 繰り返し保存することでSQLiteファイルを際限なく肥大化させる、ストレージ枯渇DoSが可能だった)
function n3s_astorage_key_size_ok($key)
{
    return strlen((string)$key) <= n3s_get_config('size_astorage_key_max', 256);
}
function n3s_astorage_value_size_ok($value)
{
    return strlen((string)$value) <= n3s_get_config('size_astorage_value_max', 1024 * 64);
}

// key/valueが上限を超えていればJSONエラーを返してexitする。書き込み系API(set_key_*/
// insert_item_*/update_item_*)の先頭で呼ぶこと。
function n3s_astorage_check_size($key, $value)
{
    if (!n3s_astorage_key_size_ok($key)) {
        $key_max = n3s_get_config('size_astorage_key_max', 256);
        api_error("keyが長すぎます。{$key_max}バイト以内にしてください。");
    }
    if (!n3s_astorage_value_size_ok($value)) {
        $value_max = n3s_get_config('size_astorage_value_max', 1024 * 64);
        api_error("valueが長すぎます。{$value_max}バイト以内にしてください。");
    }
}

// ログイン状態で発行されたトークンでなければエラーにする
function n3s_astorage_require_user($ctx)
{
    if ($ctx['user_id'] <= 0) {
        api_error('この操作にはログインが必要です。貯蔵庫にログインしてから、作品ページの「プログラムを実行」で実行してください。');
    }
}

// 作品の作者か管理者か (app DB の全データを管理できる)
function n3s_astorage_is_app_manager($ctx)
{
    if ($ctx['user_id'] <= 0) {
        return false;
    }
    if (n3s_is_admin_user($ctx['user_id'])) {
        return true;
    }
    $app = db_get1("SELECT user_id FROM apps WHERE app_id=?", [$ctx['app_id']]);
    $owner_id = $app ? intval($app['user_id']) : 0;
    return ($owner_id > 0 && $owner_id === $ctx['user_id']);
}

// app DB の行を変更・削除できるか (書き込んだ本人・作品の作者・管理者)
function n3s_astorage_can_modify_app_row($ctx, $row_user_id)
{
    if ($ctx['user_id'] <= 0) {
        return false;
    }
    if (intval($row_user_id) === $ctx['user_id']) {
        return true;
    }
    return n3s_astorage_is_app_manager($ctx);
}

function n3s_astorage_req_str($name)
{
    $v = isset($_REQUEST[$name]) ? $_REQUEST[$name] : '';
    return is_string($v) ? $v : '';
}

function n3s_astorage_req_item_id()
{
    $item_id = isset($_REQUEST['item_id']) ? intval($_REQUEST['item_id']) : 0;
    if ($item_id <= 0) {
        n3s_api_output(false, ["message" => "item_id is invalid"]);
        exit;
    }
    return $item_id;
}

// select_items_* の共通処理
function n3s_astorage_select_items($ctx, $dbname, $with_user_id)
{
    $key = n3s_astorage_req_str('key');
    $offset = isset($_REQUEST['offset']) ? max(0, intval($_REQUEST['offset'])) : 0;
    $limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 30;
    if ($limit > 30 || $limit <= 0) {
        $limit = 30;
    }
    $sort = strtoupper(n3s_astorage_req_str('sort'));
    $sort_order = ($sort === 'DESC') ? " ORDER BY item_id DESC" : " ORDER BY item_id ASC";
    $items = [];
    $r = db_get("SELECT * FROM items WHERE app_id=? AND key=? {$sort_order} LIMIT ?,?", [$ctx['app_id'], $key, $offset, $limit], $dbname);
    foreach ($r ? $r : [] as $row) {
        $item = [
            'item_id' => $row['item_id'],
            'value' => $row['value'],
            'mtime' => $row['mtime'],
        ];
        if ($with_user_id) {
            $item['user_id'] = intval($row['user_id']);
        }
        $items[] = $item;
    }
    n3s_api_output(true, ["values" => $items]);
}

function n3s_astorage_list_keys($ctx, $dbname)
{
    $kv = db_get("SELECT key FROM keys WHERE app_id=?", [$ctx['app_id']], $dbname);
    $keys = [];
    foreach ($kv ? $kv : [] as $row) {
        $keys[] = $row["key"];
    }
    n3s_api_output(true, ['keys' => $keys]);
}

function n3s_astorage_get_key($ctx, $dbname)
{
    $key = n3s_astorage_req_str('key');
    $r = db_get1("SELECT * FROM keys WHERE app_id=? AND key=?", [$ctx['app_id'], $key], $dbname);
    if ($r === false || $r === null) {
        n3s_api_output(true, ['value' => null, 'mtime' => 0]);
    } else {
        n3s_api_output(true, [
            'value' => $r['value'],
            'mtime' => $r['mtime'],
        ]);
    }
}

//------------------------------------------------------------------
// as user --- ログインユーザー × app_id 専用の領域
//------------------------------------------------------------------
function n3s_api__list_key_as_user($ctx)
{
    n3s_astorage_require_user($ctx);
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    n3s_astorage_list_keys($ctx, AS_USER);
}

function n3s_api__set_key_as_user($ctx)
{
    n3s_astorage_require_user($ctx);
    $key = n3s_astorage_req_str('key');
    $val = n3s_astorage_req_str('value');
    n3s_astorage_check_size($key, $val);
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    db_exec("DELETE FROM keys WHERE app_id=? AND key=?", [$ctx['app_id'], $key], AS_USER);
    db_exec("INSERT INTO keys (app_id, key, value, ctime, mtime) VALUES (?, ?, ?, ?, ?)", [$ctx['app_id'], $key, $val, time(), time()], AS_USER);
    n3s_api_output(true, ['message' => "saved."]);
}

function n3s_api__get_key_as_user($ctx)
{
    n3s_astorage_require_user($ctx);
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    n3s_astorage_get_key($ctx, AS_USER);
}

function n3s_api__delete_key_as_user($ctx)
{
    n3s_astorage_require_user($ctx);
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    $key = n3s_astorage_req_str('key');
    db_exec("DELETE FROM keys WHERE app_id=? AND key=?", [$ctx['app_id'], $key], AS_USER);
    n3s_api_output(true, ["message" => "deleted."]);
}

function n3s_api__deleteall_key_as_user($ctx)
{
    n3s_astorage_require_user($ctx);
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    db_exec("DELETE FROM keys WHERE app_id=?", [$ctx['app_id']], AS_USER);
    n3s_api_output(true, ["message" => "deleted all."]);
}

function n3s_api__insert_item_as_user($ctx)
{
    n3s_astorage_require_user($ctx);
    $key = n3s_astorage_req_str('key');
    $val = n3s_astorage_req_str('value');
    n3s_astorage_check_size($key, $val);
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    $item_id = db_insert(
        "INSERT INTO items (app_id, key, value, ctime, mtime) VALUES (?, ?, ?, ?, ?)",
        [$ctx['app_id'], $key, $val, time(), time()],
        AS_USER
    );
    n3s_api_output(true, ['item_id' => $item_id]);
}

function n3s_api__select_items_as_user($ctx)
{
    n3s_astorage_require_user($ctx);
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    n3s_astorage_select_items($ctx, AS_USER, false);
}

function n3s_api__delete_item_as_user($ctx)
{
    n3s_astorage_require_user($ctx);
    $item_id = n3s_astorage_req_item_id();
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    $key = n3s_astorage_req_str('key');
    db_exec("DELETE FROM items WHERE app_id=? AND key=? AND item_id=?", [$ctx['app_id'], $key, $item_id], AS_USER);
    n3s_api_output(true, ["message" => "deleted."]);
}

function n3s_api__update_item_as_user($ctx)
{
    n3s_astorage_require_user($ctx);
    $key = n3s_astorage_req_str('key');
    $value = n3s_astorage_req_str('value');
    n3s_astorage_check_size($key, $value);
    $item_id = n3s_astorage_req_item_id();
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    db_exec("UPDATE items SET value=?, mtime=? WHERE app_id=? AND key=? AND item_id=?", [$value, time(), $ctx['app_id'], $key, $item_id], AS_USER);
    n3s_api_output(true, ["message" => "updated."]);
}

//------------------------------------------------------------------
// as app --- app_id ごとの共有領域
// 読み取りはゲスト(user_id=0)でも可。書き込みはログインが必要で、
// 既存データの変更・削除は書き込んだ本人・作品の作者・管理者のみ。
//------------------------------------------------------------------
function n3s_api__list_key_as_app($ctx)
{
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    n3s_astorage_list_keys($ctx, AS_APP);
}

function n3s_api__set_key_as_app($ctx)
{
    n3s_astorage_require_user($ctx);
    $key = n3s_astorage_req_str('key');
    $val = n3s_astorage_req_str('value');
    n3s_astorage_check_size($key, $val);
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    $row = db_get1("SELECT user_id FROM keys WHERE app_id=? AND key=?", [$ctx['app_id'], $key], AS_APP);
    if ($row && !n3s_astorage_can_modify_app_row($ctx, $row['user_id'])) {
        api_error('このキーは他のユーザーが作成したため変更できません。');
    }
    db_exec("DELETE FROM keys WHERE app_id=? AND key=?", [$ctx['app_id'], $key], AS_APP);
    db_exec(
        "INSERT INTO keys (app_id, key, value, ctime, mtime, user_id) VALUES (?, ?, ?, ?, ?, ?)",
        [$ctx['app_id'], $key, $val, time(), time(), $row ? intval($row['user_id']) : $ctx['user_id']],
        AS_APP
    );
    n3s_api_output(true, ['message' => "saved."]);
}

function n3s_api__get_key_as_app($ctx)
{
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    n3s_astorage_get_key($ctx, AS_APP);
}

function n3s_api__delete_key_as_app($ctx)
{
    n3s_astorage_require_user($ctx);
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    $key = n3s_astorage_req_str('key');
    $row = db_get1("SELECT user_id FROM keys WHERE app_id=? AND key=?", [$ctx['app_id'], $key], AS_APP);
    if ($row && !n3s_astorage_can_modify_app_row($ctx, $row['user_id'])) {
        api_error('このキーは他のユーザーが作成したため削除できません。');
    }
    db_exec("DELETE FROM keys WHERE app_id=? AND key=?", [$ctx['app_id'], $key], AS_APP);
    n3s_api_output(true, ["message" => "deleted."]);
}

// 共有領域のキーを全削除する (作品の作者・管理者のみ)
function n3s_api__deleteall_key_as_app($ctx)
{
    n3s_astorage_require_user($ctx);
    if (!n3s_astorage_is_app_manager($ctx)) {
        api_error('全削除は作品の作者のみ実行できます。');
    }
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    // 以前は誤って AS_USER(ユーザー領域)を削除していた (#194)
    db_exec("DELETE FROM keys WHERE app_id=?", [$ctx['app_id']], AS_APP);
    n3s_api_output(true, ["message" => "deleted all."]);
}

function n3s_api__insert_item_as_app($ctx)
{
    n3s_astorage_require_user($ctx);
    $key = n3s_astorage_req_str('key');
    $val = n3s_astorage_req_str('value');
    n3s_astorage_check_size($key, $val);
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    $item_id = db_insert(
        "INSERT INTO items (app_id, key, value, ctime, mtime, user_id) VALUES (?, ?, ?, ?, ?, ?)",
        [$ctx['app_id'], $key, $val, time(), time(), $ctx['user_id']],
        AS_APP
    );
    n3s_api_output(true, ['item_id' => $item_id]);
}

function n3s_api__select_items_as_app($ctx)
{
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    n3s_astorage_select_items($ctx, AS_APP, true);
}

// app DB のアイテムを取得し、変更権限が無ければエラーにする
function n3s_astorage_app_item_for_modify($ctx, $key, $item_id)
{
    $row = db_get1("SELECT user_id FROM items WHERE app_id=? AND key=? AND item_id=?", [$ctx['app_id'], $key, $item_id], AS_APP);
    if (!$row) {
        n3s_api_output(false, ["message" => "item not found"]);
        exit;
    }
    if (!n3s_astorage_can_modify_app_row($ctx, $row['user_id'])) {
        api_error('このアイテムは他のユーザーが書き込んだため変更・削除できません。');
    }
    return $row;
}

function n3s_api__delete_item_as_app($ctx)
{
    n3s_astorage_require_user($ctx);
    $item_id = n3s_astorage_req_item_id();
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    $key = n3s_astorage_req_str('key');
    n3s_astorage_app_item_for_modify($ctx, $key, $item_id);
    db_exec("DELETE FROM items WHERE app_id=? AND key=? AND item_id=?", [$ctx['app_id'], $key, $item_id], AS_APP);
    n3s_api_output(true, ["message" => "deleted."]);
}

function n3s_api__update_item_as_app($ctx)
{
    n3s_astorage_require_user($ctx);
    $key = n3s_astorage_req_str('key');
    $value = n3s_astorage_req_str('value');
    n3s_astorage_check_size($key, $value);
    $item_id = n3s_astorage_req_item_id();
    n3s_api_astorage_db($ctx['app_id'], $ctx['user_id']);
    n3s_astorage_app_item_for_modify($ctx, $key, $item_id);
    db_exec("UPDATE items SET value=?, mtime=? WHERE app_id=? AND key=? AND item_id=?", [$value, time(), $ctx['app_id'], $key, $item_id], AS_APP);
    n3s_api_output(true, ["message" => "updated."]);
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
