<?php
// --------------------------------------------------------
// n3s_lib.inc.php
// library for n3s
// --------------------------------------------------------
global $n3s_config;

// include version
require_once dirname(__DIR__).'/nako3storage_version.inc.php';
require_once dirname(__DIR__).'/nako_version.inc.php';
require_once __DIR__.'/mime.inc.php';

// fw_template_engine
require_once __DIR__ . '/fw_template_engine.lib.php';
require_once __DIR__ . '/fw_database.lib.php';

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
        $dir_sql.'/init-main.sql',
        'main'
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
    $p = $params + $n3s_config;
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

function n3s_error($title, $msg, $useHTML = false)
{
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
        [$email]
    );
    if ($row === false || $row === null) {
        return 0;
    }
    return $row['user_id'];
}

function n3s_login_password_to_hash($password) {
    $hash = hash('sha256', $password.'::'.LOGIN_HASH_SALT_DEFAULT);
    return 'def::'.$hash;
}

function n3s_add_user($email, $password, $name) {
    $hash = n3s_login_password_to_hash($password);
    $user_id = db_insert(
        'INSERT INTO users (email, password, name) VALUES (?,?,?)',
        [$email, $hash, $name]
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
        [$user_id, $hash]
    );
    if ($user === false || $user === null) {
        return false;
    }
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = $user['name'];
    $_SESSION['screen_name'] = $user['name'];
    $_SESSION['profile_url'] = '';
    return true;
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
    $dir = $dir_images.'/'.sprintf('%03d', $dir_id);
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
        $ext = '.'.$ext;
    }
    $file = $dir."/{$id}{$ext}";
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

    // 実際のデータに反映するようにアップデート
    n3s_updateProgram($app_id, $data);
    return $app_id;
}

function n3s_updateProgram($app_id, $data)
{
    $a = $data;
    // check
    $data["mtime"] = time();
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
        WHERE app_id=:app_id;
EOS;
    db_exec($sql, [
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
        ":mtime"      => time(), // 確実に毎回アップデートする (#158)
        ":app_id"     => $a['app_id'],
        ":access_key" => $a['access_key'],
        ":custom_head"=> $a['custom_head'],
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
        'UPDATE materials SET body=? WHERE material_id=?',
        [$a['body'], $app_id],
        $dbname
    );
    return $app_id;
}

function n3s_list_setIcon(&$list) {
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
function n3s_list_setTagLink(&$list) {
    foreach ($list as &$i) {
        $i['tag'] = isset($i['tag']) ? $i['tag'] : '';
        $i['tag_link'] = n3s_makeTagLink($i['tag']);
    }
}

function n3s_makeTagLink($tag) {
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
