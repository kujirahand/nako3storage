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
// デフォルトのソルト（既存ユーザーの後方互換用）
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
    // 既存DB(init-users.sqlが実行済み)向けの軽量マイグレーション
    n3s_db_migrate_users();
    n3s_db_migrate_comments();
    n3s_db_migrate_apps(); // 一覧掲載フラグ show_list (Issue #202)
    n3s_db_migrate_access_stats(); // アクセス統計テーブル (Issue #217)

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

// init-users.sql 作成後の既存DBに google_sub カラムが無ければ追加する
// (docs/user_login_oauth_google.md #4)。init-users.sql はテーブル新規作成時にしか
// 実行されないため、既にDBファイルが存在するサイトに対してはここで追従させる。
function n3s_db_migrate_users()
{
    $columns = db_get('PRAGMA table_info(users)', [], 'users');
    if (!is_array($columns)) {
        return;
    }
    $names = [];
    foreach ($columns as $col) {
        $names[$col['name']] = true;
    }
    if (!isset($names['google_sub'])) {
        db_exec("ALTER TABLE users ADD COLUMN google_sub TEXT DEFAULT ''", [], 'users');
    }
    if (!isset($names['image_id'])) {
        db_exec('ALTER TABLE users ADD COLUMN image_id INTEGER DEFAULT 0', [], 'users');
    }
    db_exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_google_sub ' .
            "ON users(google_sub) WHERE google_sub != ''",
        [],
        'users'
    );
}

// init-main.sql 作成後の既存DBにコメント関連のカラムやテーブルが無ければ追加する
function n3s_db_migrate_comments()
{
    // 1. comments テーブルに parent_id, status, fav がなければ追加する
    $columns = db_get('PRAGMA table_info(comments)', [], 'main');
    if (is_array($columns)) {
        $has_parent_id = false;
        $has_status = false;
        $has_fav = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'parent_id') { $has_parent_id = true; }
            if ($col['name'] === 'status') { $has_status = true; }
            if ($col['name'] === 'fav') { $has_fav = true; }
        }
        if (!$has_parent_id) {
            db_exec("ALTER TABLE comments ADD COLUMN parent_id INTEGER DEFAULT 0", [], 'main');
        }
        if (!$has_status) {
            db_exec("ALTER TABLE comments ADD COLUMN status TEXT DEFAULT 'pending'", [], 'main');
        }
        if (!$has_fav) {
            db_exec("ALTER TABLE comments ADD COLUMN fav INTEGER DEFAULT 0", [], 'main');
        }
    }

    // 2. comment_likes テーブルがなければ作成する
    db_exec("CREATE TABLE IF NOT EXISTS comment_likes (
      comment_like_id INTEGER PRIMARY KEY,
      user_id         INTEGER,
      comment_id      INTEGER,
      ctime           INTEGER DEFAULT 0,
      UNIQUE(user_id, comment_id)
    )", [], 'main');

    // 3. comment_audit_cache テーブルがなければ作成する
    db_exec("CREATE TABLE IF NOT EXISTS comment_audit_cache (
      body_hash   TEXT PRIMARY KEY,
      result      TEXT DEFAULT '',
      reason      TEXT DEFAULT '',
      ctime       INTEGER DEFAULT 0
    )", [], 'main');
}

// init-main.sql 作成後の既存DBに apps の追加カラムが無ければ追加する。
// show_list 追加時だけ、従来 w_noname タグで表現していた「一覧非掲載」を一度だけ引き継ぐ。
function n3s_db_migrate_apps()
{
    $columns = db_get('PRAGMA table_info(apps)', [], 'main');
    if (!is_array($columns)) {
        return;
    }
    $names = array_column($columns, 'name');
    if (!in_array('show_list', $names, true)) {
        db_exec("ALTER TABLE apps ADD COLUMN show_list INTEGER DEFAULT 1", [], 'main');
        db_exec("UPDATE apps SET show_list=0 WHERE tag LIKE '%w_noname%'", [], 'main');
    }
    if (!in_array('image_id', $names, true)) {
        db_exec("ALTER TABLE apps ADD COLUMN image_id INTEGER DEFAULT 0", [], 'main');
    }
}

// log DB に access_stats テーブルが無ければ作成する (Issue #217)
function n3s_db_migrate_access_stats()
{
    db_exec(
        'CREATE TABLE IF NOT EXISTS access_stats (
            stat_id INTEGER PRIMARY KEY,
            date    TEXT NOT NULL,
            kind    TEXT NOT NULL,
            app_id  INTEGER DEFAULT 0,
            count   INTEGER DEFAULT 0,
            UNIQUE(date, kind, app_id)
        )',
        [],
        'log'
    );
}

/**
 * アクセス統計を日別にアップサートする (Issue #217)
 *
 * @param string $kind   'show' | 'widget' | 'api'
 * @param int    $app_id 対象の app_id (0 = 全体)
 */
function n3s_record_access($kind, $app_id)
{
    $date = date('Y-m-d');
    $app_id = intval($app_id);
    // アプリ単位のカウントアップ
    db_exec(
        'INSERT INTO access_stats (date, kind, app_id, count)
         VALUES (?, ?, ?, 1)
         ON CONFLICT(date, kind, app_id) DO UPDATE SET count = count + 1',
        [$date, $kind, $app_id],
        'log'
    );
    // 全体合計 (app_id=0) のカウントアップ
    if ($app_id !== 0) {
        db_exec(
            'INSERT INTO access_stats (date, kind, app_id, count)
             VALUES (?, ?, 0, 1)
             ON CONFLICT(date, kind, app_id) DO UPDATE SET count = count + 1',
            [$date, $kind],
            'log'
        );
    }
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

// セッションクッキーの属性を返す(session_start()前に session_set_cookie_params()へ渡す)。
// todo-security.md #7: 以前は httponly/secure/samesite を明示しておらず、php.ini依存だった。
// - httponly: JSからのクッキー読み取りは不要なため常にtrue(XSS発生時の被害軽減)。
// - samesite=Lax: strictにすると Google OAuth のコールバック(トップレベルのGETリダイレクト)で
//   セッションクッキーが送られず ログインできなくなるため、Laxを使う(CSRF対策とOAuth互換性の両立)。
// - secure: HTTPS時のみtrue。常時trueにすると `php -S localhost:8000` 等のローカルhttp開発が
//   壊れるため、実際の接続方式で判定する。
function n3s_session_cookie_params()
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

// session_start() の直前に呼ぶこと。
function n3s_configure_session_cookie()
{
    $p = n3s_session_cookie_params();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($p);
    } else {
        // samesite は PHP 7.3 未満では指定できない(AGENTS.mdの要求バージョンはPHP7以上のため、
        // 古い環境でもfatal errorにはせず、対応可能な属性だけ設定するに留める)
        session_set_cookie_params($p['lifetime'], $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
}

function n3s_parseURI()
{
    global $n3s_config;
    $uri = empty($_SERVER['REQUEST_URI']) ? './' :  $_SERVER['REQUEST_URI'];
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
        empty($_SERVER['HTTP_HOST']) ? 'localhost' : $_SERVER['HTTP_HOST'],
        $script_dir
    );
    // dynamically determine app_root_url if it is default or empty, and sandbox_url is not set (same-origin)
    $app_root_url = isset($n3s_config['app_root_url']) ? trim($n3s_config['app_root_url']) : '';
    if ($app_root_url === '' || $app_root_url === 'http://localhost/repos/nako3storage/') {
        $sandbox_url = isset($n3s_config['sandbox_url']) ? trim($n3s_config['sandbox_url']) : '';
        if ($sandbox_url === '') {
            $n3s_config['app_root_url'] = rtrim($n3s_config['baseurl'], '/') . '/';
        }
    }
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
        return (string)$_SESSION['name'];
    }
    return '';
}

function n3s_get_login_info()
{
    if (! n3s_is_login()) {
        return [
            'user_id' => 0,
            'name' => '?',
            'screen_name' => '?',
            'profile_url' => n3s_get_user_default_image_url(),
        ];
    }
    return [
        'user_id' => $_SESSION['user_id'] ?? 0,
        'name' => $_SESSION['name'] ?? '?',
        'screen_name' => $_SESSION['screen_name'] ?? '?',
        'profile_url' => $_SESSION['profile_url'] ?? n3s_get_user_default_image_url(),
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

function n3s_generate_salt()
{
    return bin2hex(random_bytes(32));
}

// 旧方式 (SHA-256 + salt、1回計算のみ) のハッシュ生成。
// 後方互換の検証専用。新規のパスワード保存には n3s_password_hash() を使うこと。
function n3s_login_password_to_hash($password, $salt = '')
{
    if ($salt === '' || $salt === null) {
        // 後方互換: saltが未設定の既存ユーザー向け
        $hash = hash('sha256', $password . '::' . LOGIN_HASH_SALT_DEFAULT);
        return 'def::' . $hash;
    }
    // ユーザー個別のsaltを使用
    $hash = hash('sha256', $password . '::' . $salt);
    return 'salt::' . $hash;
}

// パスワードの新規保存用ハッシュ。password_hash() (PASSWORD_DEFAULT、現状bcrypt) を使い、
// ストレッチングと強固なソルトを自動的に適用する。ソルトはハッシュ文字列に内包されるため
// users.salt は不要 ('' を保存する)。'hash::' プレフィックスで旧方式 (def::/salt::) と区別する。
function n3s_password_hash($password)
{
    return 'hash::' . password_hash($password, PASSWORD_DEFAULT);
}

// 保存済みハッシュ($stored)に対してパスワードを検証する。
// 'hash::' プレフィックスなら password_verify()、それ以外は旧方式の単純比較にフォールバックする。
function n3s_password_verify($password, $stored, $legacy_salt = '')
{
    if (strpos($stored, 'hash::') === 0) {
        return password_verify($password, substr($stored, strlen('hash::')));
    }
    // 後方互換: 旧方式 (def::/salt:: プレフィックスのSHA-256)
    return $stored === n3s_login_password_to_hash($password, $legacy_salt);
}

// 保存済みハッシュが旧方式、またはコストパラメータが古いpassword_hash()形式なら
// 再ハッシュが必要と判定する。ログイン成功時に呼び、必要ならその場で移行する。
function n3s_password_needs_upgrade($stored)
{
    if (strpos($stored, 'hash::') !== 0) {
        return true; // 旧方式 (def::/salt::)
    }
    return password_needs_rehash(substr($stored, strlen('hash::')), PASSWORD_DEFAULT);
}

// ログイン成功時、現在のパスワード平文で保存ハッシュを password_hash() 形式へ更新する
// (旧方式ユーザーの段階的な移行、およびコストパラメータ変更時の再ハッシュ)。
function n3s_upgrade_password_hash($user_id, $password)
{
    $hash = n3s_password_hash($password);
    db_exec(
        'UPDATE users SET password=?, salt=? WHERE user_id=?',
        [$hash, '', $user_id],
        'users'
    );
}

function n3s_add_user($email, $password, $name)
{
    $hash = n3s_password_hash($password);
    $user_id = db_insert(
        'INSERT INTO users (email, password, name, salt) VALUES (?,?,?,?)',
        [$email, $hash, $name, ''],
        'users'
    );
    return $user_id;
}

// ログイン成功確定時のセッション設定 (パスワードログイン・Googleログイン共通)
function n3s_login_session_start($user)
{
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['screen_name'] = $user['screen_name'] ?? '';
    $_SESSION['profile_url'] = n3s_get_user_image_url($user);
}

function n3s_login($email, $password)
{
    $user_id = n3s_get_user_id_by_email($email);
    if ($user_id <= 0) {
        return false;
    }
    // ユーザー情報を取得してsaltを確認
    $user = db_get1(
        'SELECT * FROM users WHERE user_id=?',
        [$user_id],
        'users'
    );
    if ($user === false || $user === null) {
        return false;
    }
    $salt = isset($user['salt']) ? $user['salt'] : '';
    $stored = $user['password'];
    if (!n3s_password_verify($password, $stored, $salt)) {
        return false;
    }
    // 旧方式のハッシュ、またはコストパラメータが古い場合は password_hash() 形式へ移行する
    if (n3s_password_needs_upgrade($stored)) {
        n3s_upgrade_password_hash($user_id, $password);
    }
    n3s_login_session_start($user);
    // log
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    n3s_log("$email,ip={$ip},name={$user['name']}", "login", 1);
    return true;
}

// --------------------------------------------------------
// Google OAuth ログイン (docs/user_login_oauth_google.md)
// --------------------------------------------------------

function n3s_get_user_id_by_google_sub($sub)
{
    if ($sub === '' || $sub === null) {
        return 0;
    }
    $row = db_get1(
        'SELECT user_id FROM users WHERE google_sub=?',
        [$sub],
        'users'
    );
    if ($row === false || $row === null) {
        return 0;
    }
    return $row['user_id'];
}

// Google経由の新規ユーザー作成。パスワードは空文字のまま
// (パスワードログインは使わせず、後から「パスワードを忘れた場合」フローで設定可能)
function n3s_add_user_google($email, $name, $sub)
{
    $user_id = db_insert(
        'INSERT INTO users (email, password, name, salt, google_sub) VALUES (?,?,?,?,?)',
        [$email, '', $name, '', $sub],
        'users'
    );
    return $user_id;
}

// 既存ユーザーにGoogleアカウントを紐付ける (パスワードログインとの併用が可能になる)
function n3s_link_google_account($user_id, $sub)
{
    db_exec(
        'UPDATE users SET google_sub=? WHERE user_id=?',
        [$sub, $user_id],
        'users'
    );
}

// Googleの認可エンドポイントURLを組み立てる
function n3s_google_get_auth_url($state)
{
    $params = [
        'client_id' => n3s_get_config('google_oauth_client_id', ''),
        'redirect_uri' => n3s_get_config('google_oauth_redirect_uri', ''),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

// application/x-www-form-urlencoded なPOSTを行う(トークン交換専用)。
// テストでは $n3s_config['_google_http_post'] にcallable(callable($url, $params): array|false)を
// 差し込むことで、実際のネットワーク呼び出しを行わずに検証できる。
function n3s_google_http_post($url, $params)
{
    $override = n3s_get_config('_google_http_post', null);
    if (is_callable($override)) {
        return call_user_func($override, $url, $params);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $http_code !== 200) {
        return false;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : false;
}

// 認可コードをGoogleのトークンエンドポイントでアクセストークン/ID Tokenに交換する
function n3s_google_exchange_code($code)
{
    $params = [
        'code' => $code,
        'client_id' => n3s_get_config('google_oauth_client_id', ''),
        'client_secret' => n3s_get_config('google_oauth_client_secret', ''),
        'redirect_uri' => n3s_get_config('google_oauth_redirect_uri', ''),
        'grant_type' => 'authorization_code',
    ];
    return n3s_google_http_post('https://oauth2.googleapis.com/token', $params);
}

// ID Token(JWT)のclaimsを検証して返す。失敗時はfalse。
// このID Tokenはサーバー間の直接通信(client_secretで認証済みのHTTPS接続)で
// 受け取ったものであり、ブラウザ経由の改ざん経路を通っていないため、
// 署名検証(JWKS取得)は行わずclaimsの妥当性チェックのみ行う
// (docs/user_login_oauth_google.md #7.2 手順4 参照)。
function n3s_google_verify_id_token($id_token)
{
    $parts = explode('.', (string) $id_token);
    if (count($parts) !== 3) {
        return false;
    }
    $payload_json = base64_decode(strtr($parts[1], '-_', '+/'));
    $payload = $payload_json === false ? null : json_decode($payload_json, true);
    if (!is_array($payload)) {
        return false;
    }
    $client_id = n3s_get_config('google_oauth_client_id', '');
    if ($client_id === '' || ($payload['aud'] ?? '') !== $client_id) {
        return false;
    }
    if (!in_array($payload['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) {
        return false;
    }
    if (($payload['exp'] ?? 0) < time()) {
        return false;
    }
    $email_verified = $payload['email_verified'] ?? false;
    if ($email_verified !== true && $email_verified !== 'true') {
        return false;
    }
    if (empty($payload['sub']) || empty($payload['email'])) {
        return false;
    }
    return $payload;
}

// 検証済みclaims(sub/email/name)から、ログイン対象ユーザーの行を検索・作成・紐付けする。
// 優先順位: google_sub一致 → email一致(アカウントリンク) → 新規作成
// (docs/user_login_oauth_google.md #7.2 手順5, #7.3)
function n3s_google_find_or_create_user($claims)
{
    $sub = $claims['sub'];
    $email = $claims['email'];
    $name = empty($claims['name']) ? $email : $claims['name'];

    $user_id = n3s_get_user_id_by_google_sub($sub);
    if ($user_id > 0) {
        return db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], 'users');
    }

    $user_id = n3s_get_user_id_by_email($email);
    if ($user_id > 0) {
        n3s_link_google_account($user_id, $sub);
        return db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], 'users');
    }

    $user_id = n3s_add_user_google($email, $name, $sub);
    if ($user_id <= 0) {
        return false;
    }
    return db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], 'users');
}

function n3s_getAPIToken()
{
    return bin2hex(random_bytes(16));
}


function n3s_getEditToken($key = 'default', $update = true)
{
    global $n3s_config;
    $sname = "n3s_edit_token_$key";
    // キーごとのリクエスト内キャッシュ。
    // 注意: $n3s_config['edit_token'] という「素の」キーには保存しないこと。
    // n3s_template_fw() は $n3s_config + $params でテンプレート変数をマージするため、
    // ここに値を置くと save.html/upload.html などが明示的に渡している
    // 'edit_token' パラメータを ($n3s_config 側が優先されて) 上書きしてしまう。
    if (!isset($n3s_config['_edit_token_cache']) || !is_array($n3s_config['_edit_token_cache'])) {
        $n3s_config['_edit_token_cache'] = [];
    }
    if ($update === false) {
        if (isset($_SESSION[$sname])) {
            $n3s_config['_edit_token_cache'][$key] = $_SESSION[$sname];
            return $n3s_config['_edit_token_cache'][$key];
        }
    }
    if (!isset($n3s_config['_edit_token_cache'][$key])) {
        $t = bin2hex(random_bytes(32));
        $n3s_config['_edit_token_cache'][$key] = $t;
        $_SESSION[$sname] = $t;
    }
    return $n3s_config['_edit_token_cache'][$key];
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

/// 画像ファイルのフォルダを返す
function n3s_getImageDir($id)
{
    $dir_images = n3s_get_config('dir_images', '');
    $dir_id = floor($id / 100);
    $dir = $dir_images . '/' . sprintf('%03d', $dir_id);
    return $dir;
}

/// 画像ファイルのパスを取得する
function n3s_getImageFile($id, $ext, $create = false, $token = '')
{
    $dir = n3s_getImageDir($id);
    if ($create) {
        if (! file_exists($dir)) {
            $b = mkdir($dir, 0755, true);
            if (!$b) {
                throw new Exception("Failed to create directory: $dir");
            }
        }
    }
    if (substr($ext, 0, 1) !== '.') {
        $ext = '.' . $ext;
    }
    if ($token) {
        // トークン付きの場合
        $file = $dir . "/{$id}-{$token}{$ext}";
    } else {
        $file = $dir . "/{$id}{$ext}";
    }
    return $file;
}

function n3s_delete_image_record_and_file($image_id)
{
    $image_id = intval($image_id);
    if ($image_id <= 0) {
        return;
    }
    $im = db_get1('SELECT * FROM images WHERE image_id=? LIMIT 1', [$image_id]);
    if (!$im) {
        return;
    }
    $filename = isset($im['filename']) ? $im['filename'] : '';
    $ext = '.' . pathinfo($filename, PATHINFO_EXTENSION);
    if ($ext === '.') {
        $ext = '.jpg';
    }
    $targetFile = n3s_getImageFile($image_id, $ext, false, $im['token']);
    db_exec('DELETE FROM images WHERE image_id=?', [$image_id]);
    if (file_exists($targetFile)) {
        @unlink($targetFile);
    }
}

function n3s_cover_image_type_supported($type)
{
    if ($type === IMAGETYPE_JPEG || $type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
        return true;
    }
    if (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        return true;
    }
    return false;
}

function n3s_gd_load_image($path, $type)
{
    switch ($type) {
        case IMAGETYPE_JPEG:
            return @imagecreatefromjpeg($path);
        case IMAGETYPE_PNG:
            return @imagecreatefrompng($path);
        case IMAGETYPE_GIF:
            return @imagecreatefromgif($path);
        default:
            if (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
                return @imagecreatefromwebp($path);
            }
    }
    return false;
}

function n3s_gd_resize_center_crop($srcPath, $destPath, $w, $h, $image_label)
{
    $info = @getimagesize($srcPath);
    if (!$info || !isset($info[0], $info[1], $info[2])) {
        throw new Exception("{$image_label}として使える画像ファイルではありません。");
    }
    $srcW = intval($info[0]);
    $srcH = intval($info[1]);
    $type = intval($info[2]);
    if ($srcW <= 0 || $srcH <= 0 || !n3s_cover_image_type_supported($type)) {
        throw new Exception("{$image_label}は JPEG/PNG/GIF/WebP の画像を指定してください。");
    }
    $src = n3s_gd_load_image($srcPath, $type);
    if (!$src) {
        throw new Exception("{$image_label}を読み込めませんでした。");
    }
    $dst = imagecreatetruecolor($w, $h);
    if (!$dst) {
        throw new Exception("{$image_label}を作成できませんでした。");
    }
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    $scale = max($w / $srcW, $h / $srcH);
    $scaledW = (int)ceil($srcW * $scale);
    $scaledH = (int)ceil($srcH * $scale);
    $dstX = (int)floor(($w - $scaledW) / 2);
    $dstY = (int)floor(($h - $scaledH) / 2);
    $ok = imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $scaledW, $scaledH, $srcW, $srcH);
    if (!$ok || !imagejpeg($dst, $destPath, 90)) {
        throw new Exception("{$image_label}の保存に失敗しました。");
    }
}

function n3s_gd_cover_resize($srcPath, $destPath, $w, $h)
{
    n3s_gd_resize_center_crop($srcPath, $destPath, $w, $h, '扉絵画像');
}

function n3s_getImageThumbnailFile($id, $size, $create = false, $token = '')
{
    $dir = n3s_getImageDir($id);
    if ($create && !file_exists($dir) && !mkdir($dir, 0755, true)) {
        throw new Exception("Failed to create directory: $dir");
    }
    $suffix = $token ? "-{$token}" : '';
    return $dir . "/{$id}{$suffix}-{$size}.jpg";
}

function n3s_get_user_default_image_url()
{
    return n3s_get_config('user_default_image_url', 'https://n3s.nadesi.com/image.php?f=726.png');
}

function n3s_get_user_image_url($user, $size = 32)
{
    $image_id = intval(isset($user['image_id']) ? $user['image_id'] : 0);
    if ($image_id > 0) {
        $image = db_get1('SELECT image_id,filename,token FROM images WHERE image_id=? LIMIT 1', [$image_id]);
        if ($image && !empty($image['filename'])) {
            $baseurl = rtrim(n3s_get_config('baseurl', '.'), '/');
            $url = $baseurl . '/image.php?f=' . rawurlencode($image['filename']);
            if (intval($size) === 32) {
                $url .= '&s=32';
            }
            if (!empty($image['token'])) {
                $url .= '&t=' . rawurlencode($image['token']);
            }
            return $url;
        }
    }
    return n3s_get_user_default_image_url();
}

function n3s_save_user_image($user_id, $file)
{
    if (!extension_loaded('gd')) {
        throw new Exception('プロフィール画像機能には GD 拡張が必要です。');
    }
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        throw new Exception('プロフィール画像を設定するにはログインが必要です。');
    }
    if (!$file || !isset($file['error']) || intval($file['error']) === UPLOAD_ERR_NO_FILE) {
        return 0;
    }
    if (intval($file['error']) !== UPLOAD_ERR_OK) {
        throw new Exception('プロフィール画像のアップロードに失敗しました。');
    }
    $size_upload_max = n3s_get_config('size_upload_max', 1024 * 1024 * 7);
    if (intval($file['size']) > $size_upload_max) {
        $mb = floor($size_upload_max / (1024 * 1024));
        throw new Exception("プロフィール画像のファイルサイズが最大の{$mb}MBを超えています。");
    }
    $tmp_name = isset($file['tmp_name']) ? $file['tmp_name'] : '';
    $info = @getimagesize($tmp_name);
    if (!$info || !isset($info[2]) || !n3s_cover_image_type_supported(intval($info[2]))) {
        throw new Exception('プロフィール画像は JPEG/PNG/GIF/WebP の画像を指定してください。');
    }
    $user = db_get1('SELECT name FROM users WHERE user_id=? LIMIT 1', [$user_id], 'users');
    if (!$user) {
        throw new Exception('プロフィール画像を設定するユーザーが見つかりません。');
    }
    $title = mb_substr('プロフィール画像: ' . $user['name'], 0, 512);
    $token = bin2hex(random_bytes(8));
    $image_id = 0;
    $path = '';
    $thumbnail_path = '';
    db_exec('begin');
    try {
        $now = time();
        $image_id = db_insert(
            'INSERT INTO images (title,user_id,copyright,app_id,image_name,token,ctime,mtime)VALUES(?,?,?,?,?,?,?,?)',
            [$title, $user_id, 'SELF', 0, '', $token, $now, $now]
        );
        $filename = "{$image_id}.jpg";
        $path = n3s_getImageFile($image_id, '.jpg', true, $token);
        $thumbnail_path = n3s_getImageThumbnailFile($image_id, 32, true, $token);
        db_exec('UPDATE images SET filename=? WHERE image_id=?', [$filename, $image_id]);
        n3s_gd_resize_center_crop($tmp_name, $path, 500, 500, 'プロフィール画像');
        n3s_gd_resize_center_crop($tmp_name, $thumbnail_path, 32, 32, 'プロフィール画像');
        db_exec('UPDATE users SET image_id=?, mtime=? WHERE user_id=?', [$image_id, $now, $user_id], 'users');
        db_exec('commit');
    } catch (Exception $e) {
        db_exec('rollback');
        if ($path !== '' && file_exists($path)) { @unlink($path); }
        if ($thumbnail_path !== '' && file_exists($thumbnail_path)) { @unlink($thumbnail_path); }
        throw $e;
    }
    return $image_id;
}

function n3s_save_cover_image($app_id, $user_id, $file)
{
    if (!extension_loaded('gd')) {
        throw new Exception('扉絵機能には GD 拡張が必要です。');
    }
    $app_id = intval($app_id);
    $user_id = intval($user_id);
    if ($app_id <= 0 || $user_id <= 0) {
        throw new Exception('扉絵を設定するにはログインが必要です。');
    }
    if (!$file || !isset($file['error']) || intval($file['error']) === UPLOAD_ERR_NO_FILE) {
        return 0;
    }
    if (intval($file['error']) !== UPLOAD_ERR_OK) {
        throw new Exception('扉絵のアップロードに失敗しました。');
    }
    $size_upload_max = n3s_get_config('size_upload_max', 1024 * 1024 * 7);
    if (intval($file['size']) > $size_upload_max) {
        $mb = floor($size_upload_max / (1024 * 1024));
        throw new Exception("扉絵のファイルサイズが最大の{$mb}MBを超えています。");
    }
    $tmp_name = isset($file['tmp_name']) ? $file['tmp_name'] : '';
    $info = @getimagesize($tmp_name);
    if (!$info || !isset($info[2]) || !n3s_cover_image_type_supported(intval($info[2]))) {
        throw new Exception('扉絵は JPEG/PNG/GIF/WebP の画像を指定してください。');
    }
    $app = db_get1('SELECT title FROM apps WHERE app_id=? LIMIT 1', [$app_id]);
    if (!$app) {
        throw new Exception('扉絵を設定する作品が見つかりません。');
    }
    $title = mb_substr('扉絵: ' . $app['title'], 0, 512);
    $token = bin2hex(random_bytes(8));
    $image_id = 0;
    $path = '';
    db_exec('begin');
    try {
        $now = time();
        $image_id = db_insert(
            'INSERT INTO images (title,user_id,copyright,app_id,image_name,token,ctime,mtime)VALUES(?,?,?,?,?,?,?,?)',
            [$title, $user_id, 'SELF', $app_id, '', $token, $now, $now]
        );
        $filename = "{$image_id}.jpg";
        $path = n3s_getImageFile($image_id, '.jpg', true, $token);
        db_exec('UPDATE images SET filename=? WHERE image_id=?', [$filename, $image_id]);
        n3s_gd_cover_resize($tmp_name, $path, intval(n3s_get_config('cover_width', 600)), intval(n3s_get_config('cover_height', 240)));
        db_exec('UPDATE apps SET image_id=? WHERE app_id=?', [$image_id, $app_id]);
        db_exec('commit');
    } catch (Exception $e) {
        db_exec('rollback');
        if ($path !== '' && file_exists($path)) {
            @unlink($path);
        }
        throw $e;
    }
    return $image_id;
}

function n3s_unset_cover_image($app_id)
{
    $app_id = intval($app_id);
    if ($app_id <= 0) {
        return;
    }
    db_exec('UPDATE apps SET image_id=0 WHERE app_id=?', [$app_id]);
}

function n3s_delete_cover_image($app_id)
{
    n3s_unset_cover_image($app_id);
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
    // 削除済み作品の残骸行があってもapp_id再利用時に主キー衝突しないよう REPLACE を使う
    db_insert(
        'INSERT OR REPLACE INTO materials (material_id) VALUES (?)',
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
    // 一覧掲載フラグ。0以外(未設定含む)は掲載扱いに倒す (#202)
    $a['show_list'] = (isset($a['show_list']) && intval($a['show_list']) === 0) ? 0 : 1;
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
            show_list=:show_list,
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
        ":show_list"  => $a['show_list'],
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
    $show_list = intval(isset($a['show_list']) ? $a['show_list'] : 1);
    // 公開設定かつ一覧掲載の時のみ通知を行う (#202)
    if ($is_prvate !== 0 || $show_list === 0) {
        return;
    }
    // -------------------------------------------
    // 3時間以内に同じ投稿があっても無視する
    // check interval
    $last_times = json_decode(n3s_getInfoTag('discord_webhook_last_times', '{}'), TRUE);
    // N時間以内のエントリのみ残す
    $remain = [];
    $limit = time() - 60 * 60 * 24 * 3; // 3日間
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
    $curl_command = n3s_discord_webhook_curl_command($discord_webhook_url, $message_json);
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

// Discord Webhook用のcurlコマンド文字列を組み立てる(副作用なし。execはしない)。
// todo-security.md #10: 従来は常に --insecure を付けてTLS証明書検証を無効化していた。
// サーバー環境によっては証明書検証を有効にするとcurlがエラーになる(自己署名証明書・
// 中間証明書未設置など)ため、既定は従来通り --insecure(後方互換・デフォルトfalse)のまま
// にしつつ、設定 `webhook_secure` を true にした環境ではTLS証明書検証を有効にできるようにする。
function n3s_discord_webhook_curl_command($url, $json)
{
    $secure = n3s_get_config('webhook_secure', false);
    $insecure_flag = $secure ? '' : ' --insecure';
    return sprintf(
        'curl -X POST %s -H "Content-Type: application/json; charset=utf-8" -d %s%s > /dev/null 2>&1 &',
        escapeshellarg($url),
        escapeshellarg($json),
        $insecure_flag
    );
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
    foreach ($list as &$i) {
        $nakotype = isset($i['nakotype']) ? $i['nakotype'] : '';
        $i['icon'] = ($nakotype === 'wnako' || $nakotype === 'cnako')
            ? 'https://n3s.nadesi.com/image.php?f=727.png'
            : 'https://n3s.nadesi.com/image.php?f=729.png';
    }
}

function n3s_cover_url_from_image_row($image)
{
    $filename = isset($image['filename']) ? $image['filename'] : '';
    if ($filename === '') {
        return n3s_get_config('cover_default_url', 'https://n3s.nadesi.com/image.php?f=721.png');
    }
    $baseurl = rtrim(n3s_get_config('baseurl', '.'), '/');
    $url = $baseurl . '/image.php?f=' . rawurlencode($filename);
    $token = isset($image['token']) ? $image['token'] : '';
    if ($token !== '') {
        $url = $baseurl . '/image.php?t=' . rawurlencode($token) . '&f=' . rawurlencode($filename);
    }
    return $url;
}

function n3s_get_cover_url($app_row)
{
    $image_id = intval(isset($app_row['image_id']) ? $app_row['image_id'] : 0);
    if ($image_id <= 0) {
        return n3s_get_config('cover_default_url', 'https://n3s.nadesi.com/image.php?f=721.png');
    }
    $image = db_get1('SELECT image_id,filename,token FROM images WHERE image_id=? LIMIT 1', [$image_id]);
    if (!$image) {
        return n3s_get_config('cover_default_url', 'https://n3s.nadesi.com/image.php?f=721.png');
    }
    return n3s_cover_url_from_image_row($image);
}

function n3s_list_setCoverURL(&$rows)
{
    if (!is_array($rows) || count($rows) === 0) {
        return;
    }
    $ids = [];
    foreach ($rows as $row) {
        $image_id = intval(isset($row['image_id']) ? $row['image_id'] : 0);
        if ($image_id > 0) {
            $ids[$image_id] = true;
        }
    }
    $images = [];
    if (count($ids) > 0) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $image_rows = db_get(
            "SELECT image_id,filename,token FROM images WHERE image_id IN ($placeholders)",
            array_keys($ids)
        );
        foreach ($image_rows as $image) {
            $images[intval($image['image_id'])] = $image;
        }
    }
    foreach ($rows as &$row) {
        $image_id = intval(isset($row['image_id']) ? $row['image_id'] : 0);
        if ($image_id > 0 && isset($images[$image_id])) {
            $row['cover_url'] = n3s_cover_url_from_image_row($images[$image_id]);
        } else {
            $row['cover_url'] = n3s_get_config('cover_default_url', 'https://n3s.nadesi.com/image.php?f=721.png');
        }
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
    if ($user) {
        $user['profile_url'] = n3s_get_user_image_url($user);
        $user['profile_url_large'] = n3s_get_user_image_url($user, 0);
    }
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

// --------------------------------------------------------
// 非公開・限定公開の作品を、現在のリクエストで閲覧してよいか判定する共通ロジック。
// show / widget / widget_frame / api など、閲覧系アクションすべてで使う (todo-security.md #6)。
//
// 以前は show.inc.php が保存時に指定された editkey を見る一方、widget_frame.inc.php は
// 常に未使用のまま空文字が入っている access_key カラムを見ていた。そのため widget/widget_frame
// 経由では「保存されたキー('')」と「GETで渡されなかった場合のデフォルト('')」が常に一致してしまい、
// 限定公開のチェックが実質機能していなかった。
// --------------------------------------------------------

// $a (apps テーブルの1行) を、キー $key を提示した状態で閲覧してよいか判定する。
// 副作用なし(exitや画面出力をしない)の純粋な判定関数。
function n3s_private_access_allowed($a, $key)
{
    if (!$a) {
        return false;
    }
    $is_private = intval(isset($a['is_private']) ? $a['is_private'] : 0);
    if ($is_private !== 1 && $is_private !== 2) {
        return true; // 公開作品(0)、あるいは想定外の値は許可(既存挙動を踏襲)
    }
    if (n3s_is_admin()) {
        return true;
    }
    $stored_key = isset($a['editkey']) ? (string)$a['editkey'] : '';
    $given_key = (string)$key;
    $owner_user_id = intval(isset($a['user_id']) ? $a['user_id'] : 0);
    if ($owner_user_id === 0) {
        // ログインなしで投稿された作品は、editkeyの一致でのみ閲覧できる
        // (匿名投稿には「本人」の概念がないため)
        return hash_equals($stored_key, $given_key);
    }
    if ($owner_user_id === n3s_get_user_id()) {
        return true; // 投稿者本人は常に閲覧可能
    }
    if ($is_private === 1) {
        return false; // 非公開は本人・管理者のみ閲覧可能(editkeyでの迂回は不可)
    }
    // is_private === 2 (限定公開): editkeyが一致すれば第三者も閲覧できる
    return hash_equals($stored_key, $given_key);
}

// n3s_private_access_allowed() が false のときの共通の拒否処理。
// ログインユーザーの非公開(1)は入力の余地がないためエラー表示のみ(agent=apiならJSON)。
// それ以外(匿名投稿の非公開/限定公開、ログインユーザーの限定公開)は
// editkey の入力画面を表示し、リトライできるようにする。
function n3s_deny_private_access($a, $agent, $action)
{
    $is_private = intval(isset($a['is_private']) ? $a['is_private'] : 0);
    $owner_user_id = intval(isset($a['user_id']) ? $a['user_id'] : 0);
    if ($owner_user_id > 0 && $is_private === 1) {
        n3s_error(
            "非公開の投稿($agent)",
            'この投稿は非公開です。',
            false,
            ($agent === 'api')
        );
        exit;
    }
    n3s_template_fw('show_input_editkey.html', [
        'app_id' => isset($a['app_id']) ? $a['app_id'] : 0,
        'author' => isset($a['author']) ? $a['author'] : '',
        'run' => empty($_GET['run']) ? 0 : $_GET['run'],
        'back' => $action,
    ]);
    exit;
}

function n3s_randomIntStr($length = 7)
{
    // パスワード再設定の認証番号などに使われるため、予測不能な乱数(CSPRNG)を使う。
    // 以前は rand() (Mersenne Twister) を使っており、内部状態を推測されると
    // 認証番号を予測される恐れがあった (todo-security.md #5)。
    $r = '';
    for ($i = 0; $i < $length; $i++) {
        $r .= '' . random_int(0, 9);
    }
    return $r;
}
