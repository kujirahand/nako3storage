<?php
// --------------------------------------------------------
// n3s_lib.inc.php
// library for n3s
// --------------------------------------------------------
global $n3s_config;

// include version
require_once dirname(__DIR__) . '/nako3storage_version.inc.php';
require_once dirname(__DIR__) . '/nako_version.inc.php';
require_once __DIR__ . '/mime.inc.php';

// fw_template_engine
require_once __DIR__ . '/fw_simple/fw_template_engine.lib.php';
require_once __DIR__ . '/fw_simple/fw_database.lib.php';

// database version
define("N3S_DB_VERSION", 3);
// TODO: 将来的に個別のハッシュを設定できるようにする
define("LOGIN_HASH_SALT_DEFAULT", "97mwXXq08tku4eN6#YvbS0~cn0U8sb[PChfOjYe_ruJ5]RiVscCS");

function n3s_db_init()
{
    global $n3s_config;
    $dir_sql = $n3s_config['dir_sql'];

    // set main db
    database_set(
        $n3s_config["file_db_main"],
        $dir_sql . '/init-main.sql',
        'main'
    );
    // set log db
    database_set(
        $n3s_config['file_db_log'],
        $dir_sql . '/init-log.sql',
        'log'
    );
    // set users db
    database_set(
        $n3s_config['file_db_users'],
        $dir_sql . '/init-users.sql',
        'users'
    );

    // v0.7未満で利用(過去のDB参照のため) #80
    /*
    // set material db
    database_set(
      $n3s_config["file_db_material"],
      $dir_sql.'/init-material.sql',
      'material');
    $f = $n3s_config["file_db_material"];
    */
}

/**
 * get config value
 */
function n3s_get_config($key, $def)
{
    global $n3s_config;
    if (isset($n3s_config[$key])) {
        return $n3s_config[$key];
    }
    return $def;
}
/**
 * set config value
 */
function n3s_set_config($key, $val)
{
    global $n3s_config;
    $n3s_config[$key] = $val;
}

function get_param($name, $def = '')
{
    if (isset($_GET[$name])) {
        return $_GET[$name];
    }
    return $def;
}

function post_param($name, $def = '')
{
    if (isset($_POST[$name])) {
        return $_POST[$name];
    }
    return $def;
}

function n3s_getURL($page, $action, $params = array())
{
    global $n3s_config;
    $baseurl = $n3s_config['baseurl'];
    if (substr($baseurl, strlen($baseurl) - 1, 1) == '/') {
        // 末尾に "/"が含まれるとき削る
        $baseurl = substr($baseurl, 0, strlen($baseurl) - 1);
    }
    $url = "{$baseurl}/index.php?page=$page&action=$action";
    foreach ($params as $k => $v) {
        $url .= '&' . urlencode($k) . '=' . urlencode($v);
    }
    return $url;
}

function n3s_jump($page, $action, $params = array())
{
    $url = n3s_getURL($page, $action, $params);
    header("location:$url");
}

function n3s_hash_editkey($key)
{
    $salt = 'H38oJpfD/K4PKg6Jf#qcvZt_1P@5XayuTmn';
    return hash('sha256', "$key::$salt");
}

function n3s_parseURI()
{
    global $n3s_config;
    $uri = $_SERVER['REQUEST_URI'];
    $script_path = explode('?', $uri)[0];
    $n3s_config['page'] = 'all';
    $n3s_config['action'] = 'list';
    foreach ($_GET as $k => $v) {
        $n3s_config[$k] = $v;
    }
    if (isset($n3s_config['status'])) {
        $n3s_config['action'] = $n3s_config['status'];
    }
    // set baseurl
    $script = basename($_SERVER['SCRIPT_NAME']);
    $script_dir = preg_replace("#/{$script}$#", "", $script_path);
    $n3s_config['baseurl'] = sprintf(
        "%s://%s%s",
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http',
        $_SERVER['HTTP_HOST'],
        $script_dir
    );
}

function n3s_get_db($type = 'main')
{
    return database_get($type);
}

function n3s_template_fw($name, $params)
{
    global $n3s_config;
    global $DIR_TEMPLATE_CACHE, $DIR_TEMPLATE, $FW_TEMPLATE_PARAMS;
    $DIR_TEMPLATE = $n3s_config['dir_template'];
    $DIR_TEMPLATE_CACHE = $n3s_config['dir_cache'];
    $p = $n3s_config + $params;
    // IE対策のためmsieパラメータをセット
    $useragent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $agent = strtolower($useragent);
    $msie = false;
    if (strstr($agent, 'trident') || strstr($agent, 'msie')) {
        $msie = true;
    }
    $p['msie'] = $msie;
    //
    $FW_TEMPLATE_PARAMS = $p;
    template_render($name, []);
}

function n3s_error($title, $msg, $useHTML = false, $isAPI = false)
{
    if ($isAPI) {
        n3s_api_output(false, ['title' => $title, 'msg' => $msg]);
        return;
    }
    $template = 'error.html';
    if ($useHTML) {
        $template = 'error_raw.html';
    }
    n3s_template_fw($template, array(
        "title" => $title,
        "msg" => $msg
    ));
}

function n3s_info($title, $msg, $useHTML = false)
{
    $template = 'info.html';
    if ($useHTML) {
        $template = 'info_raw.html';
    }
    n3s_template_fw($template, array(
        "title" => $title,
        "msg" => $msg
    ));
}

function n3s_api_output($result, $data)
{
    $data['result'] = $result;
    header('content-type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function n3s_is_login()
{
    // @see action/login.inc.php
    if (empty($_SESSION['n3s_login'])) {
        return false;
    }
    return true;
}

function n3s_get_user_id()
{
    if (! n3s_is_login()) {
        return 0;
    }
    if (isset($_SESSION['user_id'])) {
        return (int) ($_SESSION['user_id']);
    }
    return 0;
}

function n3s_get_user_name()
{
    if (! n3s_is_login()) {
        return '?';
    }
    if (isset($_SESSION['name'])) {
        return (int) ($_SESSION['name']);
    }
    return 0;
}

function n3s_get_login_info()
{
    if (! n3s_is_login()) {
        return [
            'user_id' => 0,
            'name' => '?',
            'screen_name' => '?',
            'profile_url' => 'skin/def/user-icon.png',
        ];
    }
    return [
        'user_id' => $_SESSION['user_id'],
        'name' => $_SESSION['name'],
        'screen_name' => $_SESSION['screen_name'],
        'profile_url' => $_SESSION['profile_url'],
    ];
}

function n3s_is_admin()
{
    $user_id = n3s_get_user_id();
    $admin_users = n3s_get_config('admin_users', [1]);
    foreach ($admin_users as $id) {
        if ($id === $user_id) {
            return true;
        }
    }
    return false;
}

function n3s_get_user_id_by_email($email)
{
    $row = db_get1(
        'SELECT user_id FROM users WHERE email=?',
        [$email],
        'users'
    );
    if ($row === false || $row === null) {
        return 0;
    }
    return $row['user_id'];
}

function n3s_login_password_to_hash($password)
{
    $hash = hash('sha256', $password . '::' . LOGIN_HASH_SALT_DEFAULT);
    return 'def::' . $hash;
}

function n3s_add_user($email, $password, $name)
{
    $hash = n3s_login_password_to_hash($password);
    $user_id = db_insert(
        'INSERT INTO users (email, password, name) VALUES (?,?,?)',
        [$email, $hash, $name],
        'users'
    );
    return $user_id;
}

function n3s_login($email, $password)
{
    $user_id = n3s_get_user_id_by_email($email);
    if ($user_id <= 0) {
        return false;
    }
    $hash = n3s_login_password_to_hash($password);
    $user = db_get1(
        'SELECT * FROM users WHERE user_id=? AND password=?',
        [$user_id, $hash],
        'users'
    );
    if ($user === false || $user === null) {
        return false;
    }
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = $name = $user['name'];
    $_SESSION['screen_name'] = $user['name'];
    $_SESSION['profile_url'] = '';
    // log
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    n3s_log("$email,ip={$ip},name={$name}", "login", 1);
    return true;
}

function n3s_getAPIToken()
{
    return bin2hex(random_bytes(16));
}


function n3s_getEditToken($key = 'default', $update = true)
{
    global $n3s_config;
    $sname = "n3s_edit_token_$key";
    if ($update === false) {
        if (isset($_SESSION[$sname])) {
            $n3s_config['edit_token'] = $_SESSION[$sname];
            return $n3s_config['edit_token'];
        }
    }
    if (! isset($n3s_config['edit_token'])) {
        $t = $n3s_config['edit_token'] = bin2hex(random_bytes(32));
        $_SESSION[$sname] = $t;
    }
    return $n3s_config['edit_token'];
}

function n3s_checkEditToken($key = 'default')
{
    $sname = "n3s_edit_token_$key";
    $ses = isset($_SESSION[$sname]) ? $_SESSION[$sname] : '';
    $get = isset($_REQUEST['edit_token']) ? $_REQUEST['edit_token'] : '';
    if ($ses !== '' && $ses === $get) {
        return true;
    }
    return false;
}

function n3s_setBackURL($url)
{
    $_SESSION['n3s_backurl'] = $url;
}

function n3s_getBackURL()
{
    $url = isset($_SESSION['n3s_backurl']) ? $_SESSION['n3s_backurl'] : '';
    unset($_SESSION['n3s_backurl']);
    return $url;
}

function n3s_getImageDir($id)
{
    $dir_images = n3s_get_config('dir_images', '');
    $dir_id = floor($id / 100);
    $dir = $dir_images . '/' . sprintf('%03d', $dir_id);
    return $dir;
}

function n3s_getImageFile($id, $ext, $create = false)
{
    $dir = n3s_getImageDir($id);
    if ($create) {
        if (! file_exists($dir)) {
            mkdir($dir);
        }
    }
    if (substr($ext, 0, 1) !== '.') {
        $ext = '.' . $ext;
    }
    $file = $dir . "/{$id}{$ext}";
    return $file;
}


// 保存先のDBを調べる
function n3s_getMaterialDB($material_id)
{
    $dir_app = n3s_get_config('dir_app', dirname(__DIR__));
    $dir_data = n3s_get_config('dir_data', "{$dir_app}/data");
    $db_id = floor($material_id / 100);
    $file_db = "{$dir_data}/sub_material_{$db_id}.sqlite3";
    $file_sql = "{$dir_app}/sql/init-material.sql";
    $dbname = basename($file_db);
    database_set($file_db, $file_sql, $dbname);
    return $dbname;
}

// 実際のプログラムを取得する
function n3s_getMaterialData($app_id)
{
    if ($app_id <= 0) {
        return null;
    }
    $dbname = n3s_getMaterialDB($app_id);
    $m = db_get1('SELECT * FROM materials WHERE material_id=?', [$app_id], $dbname);
    return $m;
}

function n3s_saveNewProgram(&$data)
{
    // データを $a でアクセス
    $a = $data;

    // 日付を指定
    $a['ctime'] = $a['mtime'] = time();

    // ログインしていれば強制的にuser_idを書き換える
    if (n3s_is_login()) {
        $a['user_id'] = n3s_get_user_id();
        $a['author'] = n3s_get_user_name();
    }

    // update で正しい値を入れるので適当にタイトルだけ挿入
    // メインDBに入れる
    $sql = 'INSERT INTO apps (title, user_id, ctime) VALUES (?,?,?)';
    $app_id = db_insert($sql, [$a['title'], $a['user_id'], $a['ctime']]);
    // プログラムのDBに入れる
    $dbname = n3s_getMaterialDB($app_id);
    db_insert(
        'INSERT INTO materials (material_id) VALUES (?)',
        [$app_id],
        $dbname
    );
    $data['app_id'] = $app_id;
    // log
    n3s_log("app_id={$app_id},user_id={$a['user_id']},author={$a['author']},", '新規投稿');
    // 実際のデータに反映するようにアップデート
    n3s_updateProgram($data);
    return $app_id;
}

function n3s_updateProgram($data)
{
    $a = $data;
    // check
    $a["mtime"] = time(); // 確実に毎回アップデートする (#158)
    $a['body'] = trim($a['body']);
    $a['prog_hash'] = hash('sha256', $a['body']);
    // update info
    $sql = <<< EOS
        UPDATE apps SET
            app_name=:app_name,
            title=:title,
            author=:author,
            email=:email,
            url=:url, memo=:memo,
            canvas_w=:canvas_w, canvas_h=:canvas_h,
            access_key=:access_key,
            version=:version,
            is_private=:is_private,
            custom_head=:custom_head,
            copyright=:copyright,
            editkey=:editkey,
            nakotype=:nakotype,
            tag=:tag,
            prog_hash=:prog_hash,
            ref_id=:ref_id, 
            ip=:ip,
            mtime=:mtime
        WHERE
            app_id=:app_id;
EOS;
    db_exec($sql, [
        ":app_id"     => $a['app_id'],
        ":app_name"   => $a['app_name'],
        ":title"      => $a['title'],
        ":author"     => $a['author'],
        ":url"        => $a['url'],
        ":email"      => $a['email'],
        ":memo"       => $a['memo'],
        ":canvas_w"   => $a['canvas_w'],
        ":canvas_h"   => $a['canvas_h'],
        ":version"    => $a['version'],
        ":is_private" => $a['is_private'],
        ":ref_id"     => $a['ref_id'],
        ":canvas_w"   => $a['canvas_w'],
        ":canvas_h"   => $a['canvas_h'],
        ":ip"         => $a['ip'],
        ":mtime"      => $a['mtime'],
        ":access_key" => $a['access_key'],
        ":custom_head" => $a['custom_head'],
        ":editkey"    => $a['editkey'],
        ":copyright"  => $a['copyright'],
        ":nakotype"   => $a['nakotype'],
        ":tag"        => $a['tag'],
        ":prog_hash"  => $a['prog_hash']
    ]);
    // update body
    $app_id = $a['app_id'];
    $dbname = n3s_getMaterialDB($app_id);
    db_exec(
        'UPDATE materials SET body=?, app_id=? WHERE material_id=?',
        [$a['body'], $app_id, $app_id],
        $dbname
    );
    n3s_log("app_id={$app_id},author={$a['author']},title={$a['title']},user_id={$a['user_id']},", '作品更新');
    // save to nadesiko3hub
    n3s_nadesiko3hub_save($app_id, $a);
    // discord webhook
    n3s_discord_webhook($a);
    return $app_id;
}

function n3s_discord_webhook($a)
{
    $app_root_url = n3s_get_config('app_root_url', '');
    $discord_webhook_url = n3s_get_config('discord_webhook_url', '');
    if ($discord_webhook_url == '') {
        return;
    }
    //
    $title = $a['title'];
    $author = $a['author'];
    $app_id = $a['app_id'];
    $app_key = "$app_id";
    $memo = $a['memo'];
    $is_prvate = $a['is_private'];
    $tag = $a['tag'];
    // 公開設定の時のみ通知を行う
    if ($is_prvate !== 0 || $tag == 'w_noname') {
        return;
    }
    // -------------------------------------------
    // 3時間以内に同じ投稿があっても無視する
    // check interval
    $last_times = json_decode(n3s_getInfoTag('discord_webhook_last_times', '{}'), TRUE);
    // 3時間以内のエントリのみ残す
    $remain = [];
    $limit = time() - 60 * 60 * 3;
    // ~~~~~~~~~ $limit -----$val------ $now
    foreach ($last_times as $key => $val) {
        if ($limit < $val) {
            $remain[$key] = $val;
        }
    }
    $last_times = $remain;
    // check interval
    $last_t = isset($last_times[$app_key]) ? $last_times[$app_key] : 0;
    if ($last_t == 0) { // new post
        $last_times[$app_key] = time();
        n3s_setInfoTag('discord_webhook_last_times', json_encode($last_times));
    } else {
        return;
    }
    // -------------------------------------------

    $app_url = "{$app_root_url}id.php?{$app_id}";
    //メッセージの内容を定義
    $contents = "{$author}さんが「{$title}」を投稿しました。\n{$app_url}\n{$memo}";
    $message = array(
        'username' => n3s_get_config('discord_webhook_name', 'なでしこ3貯蔵庫'),
        'content'  => $contents
    );
    $message_json = json_encode($message);
    // curlを利用してポスト(非同期)
    $curl_command = sprintf(
        'curl -X POST %s -H "Content-Type: application/json; charset=utf-8" -d %s --insecure > /dev/null 2>&1 &',
        escapeshellarg($discord_webhook_url),
        escapeshellarg($message_json)
    );
    @exec($curl_command);
    /*
    // curlのオプションを設定してPOST
    // PHP code
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $discord_webhook_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    $_resp = curl_exec($ch);
    curl_close($ch);
    */
}

function n3s_getInfo($key, $def = null)
{
    $info = db_get1("SELECT * from info WHERE key=?", [$key]);
    if ($info) {
        return $info;
    }
    return $def;
}

function n3s_setInfo($key, $value = 0, $tag = "")
{
    $info = db_get1("SELECT * from info WHERE key=?", [$key]);
    if ($info) {
        db_exec("UPDATE info SET value=?, tag=? WHERE key=?", [$value, $tag, $key]);
    } else {
        db_exec("INSERT INTO info (key, value, tag) VALUES (?,?,?)", [$key, $value, $tag]);
    }
}

function n3s_getInfoTag($key, $def = "")
{
    $info = db_get1("SELECT * from info WHERE key=?", [$key]);
    if ($info) {
        return $info["tag"];
    }
    return $def;
}

function n3s_setInfoTag($key, $tag)
{
    n3s_setInfo($key, 0, $tag);
}

function n3s_nadesiko3hub_save($app_id, $data)
{
    // ライセンスを確認して問題なければ、nadesiko3hubに保存
    $nadesiko3hub_enabled = n3s_get_config('nadesiko3hub_enabled', FALSE);
    $nadesiko3hub_dir = n3s_get_config('nadesiko3hub_dir', '');
    if (!$nadesiko3hub_enabled || $nadesiko3hub_dir == '') {
        return;
    }
    // プログラムが空ならばスキップ
    $body = empty($data['body']) ? '' : $data['body'];
    // ライセンスの確認 (ライセンスがない場合は保存しない)
    $copyright = $data['copyright'];
    if ($copyright == '未指定' || $copyright == '自分用') {
        $copyright = '';
    } // 未指定と自分用は保存しない
    // 保存先を決定(フォルダ1つずつに500件)
    $dirno = floor($app_id / 500) * 500;
    $dirname = sprintf('%05d', $dirno);
    $savedir = $nadesiko3hub_dir . '/' . $dirname;
    if (!file_exists($savedir)) {
        @mkdir($savedir);
    }
    $savefile = $savedir . '/' . $app_id . '.nako3';
    // 非公開であれば保存しない(また非公開にされたり、著作権を自分用にされたら削除)
    if ($data['is_private'] == 1 || $body == '' || $copyright == '') {
        if (file_exists($savefile)) {
            unlink($savefile);
        }
        return;
    }
    // メタ情報を追加
    $memo = empty($data['memo']) ? '' : $data['memo'];
    $memo = preg_replace('#[\r|\n]#', '', $memo); // 改行コードを削除
    // mtime
    if (empty($data['mtime'])) {
        $data['mtime'] = $data['ctime'];
    }
    $mtime = date('Y-m-d H:i:s', $data['mtime']);
    //
    $meta  = "### [作品情報]\n";
    $meta .= "### 掲載URL=https://n3s.nadesi.com/id.php?{$app_id}\n";
    $meta .= "### タイトル={$data['title']}\n";
    $meta .= "### 作者={$data['author']}(user_id={$data['user_id']})\n";
    $meta .= "### ライセンス={$data['copyright']}\n";
    $meta .= "### 説明={$memo}\n";
    $meta .= "### 対象バージョン={$data['version']}\n";
    $meta .= "### URL={$data['url']}\n";
    $meta .= "### 種類={$data['nakotype']}\n";
    $meta .= "### タグ={$data['tag']}\n";
    $meta .= "### 更新日時={$mtime}\n";
    $meta .= "###\n\n";
    // 保存
    $body = str_replace("\r\n", "\n", $body); // 改行コードを統一
    $body = str_replace("\r", "\n", $body);
    file_put_contents($savefile, $meta . $body);
    n3s_log("app_id={$app_id}", 'ハブ保存');
}

function n3s_nadesiko3hub_update_all()
{
    $all = db_get('SELECT * FROM apps WHERE is_private=0 ORDER BY app_id DESC');
    foreach ($all as $a) {
        $app_id = $a['app_id'];
        $body = n3s_getMaterialData($app_id);
        $a['body'] = empty($body['body']) ? '' : $body['body'];
        $len = mb_strlen($a['body']);
        echo "[$app_id] {$a['title']}({$len}字)\n";
        n3s_nadesiko3hub_save($app_id, $a);
    }
    /*
    # update mtime (mtime=0の作品がいくつかあったので緊急の処置)
    $all = db_get('SELECT * FROM apps ORDER BY app_id DESC');
    foreach ($all as $a) {
        $app_id = $a['app_id'];
        $mtime = $a['mtime'];
        $ctime = $a['ctime'];
        $title = $a['title'];
        if (!empty($mtime)) { continue; }
        db_exec("UPDATE apps SET mtime=$ctime WHERE app_id=$app_id");
        echo "update mtime app_id=$app_id $title\n";
    }
    */
}

function n3s_list_setIcon(&$list)
{
    // wnako / dncl / other
    foreach ($list as &$i) {
        $icon = isset($i['nakotype']) ? $i['nakotype'] : 'wnako';
        $i['tag'] = isset($i['tag']) ? $i['tag'] : '';
        if (strpos($i['tag'], 'DNCL') !== FALSE) {
            $icon = 'dncl';
        }
        if ($icon != 'wnako' && $icon != 'dncl') {
            $icon = 'other';
        }
        $i['icon'] = "images/0-$icon.png";
    }
}
function n3s_list_setTagLink(&$list)
{
    foreach ($list as &$i) {
        $i['tag'] = isset($i['tag']) ? $i['tag'] : '';
        $i['tag_link'] = n3s_makeTagLink($i['tag']);
    }
}

function n3s_makeTagLink($tag)
{
    if ($tag == '') {
        return '-';
    }
    $tag_a = explode(',', $tag);
    $tag_link = [];
    foreach ($tag_a as $t) {
        $label = htmlspecialchars($t, ENT_QUOTES);
        $tagenc = urlencode($t);
        $tag_link[] = "<a href='index.php?search_word={$tagenc}&action=search&target=tag'>$label</a>";
    }
    return implode(', ', $tag_link);
}

function n3s_log($msg, $kind = 'info', $level = 0)
{
    db_exec('INSERT INTO logs(log_level, kind, body, ctime) VALUES (?,?,?,?)', [
        intval($level),
        $kind,
        $msg,
        time(),
    ], 'log');
}

function n3s_warn($msg)
{
    $kind = 'warn';
    $level = 1;
    n3s_log($msg, $kind, $level);
}

function n3s_getUserInfo($user_id)
{
    $user = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], 'users');
    return $user;
}

function n3s_logout()
{
    // logout info
    $name = empty($_SESSION['name']) ? '?' : $_SESSION['name'];
    $user_id = empty($_SESSION['user_id']) ? 0 : $_SESSION['user_id'];
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    // unset session
    unset($_SESSION['n3s_login']);
    unset($_SESSION['user_id']);
    unset($_SESSION['n3s_backurl']);
    unset($_SESSION['name']);
    // log
    if ($user_id > 0) {
        n3s_log("user_id=$user_id,name={$name},ip={$ip}", "logout", 0);
    }
}

function n3s_randomIntStr($length = 7)
{
    $r = '';
    for ($i = 0; $i < $length; $i++) {
        $r .= '' . rand(0, 9);
    }
    return $r;
}
