<?php
// --------------------------------------------------------
// n3s_lib.inc.php
// library for n3s
// --------------------------------------------------------
global $n3s_config;

// include version
include_once dirname(__DIR__).'/nako3storage_version.inc.php';
include_once dirname(__DIR__).'/nako_version.inc.php';

// fw_template_engine
require_once __DIR__ . '/fw_template_engine.lib.php';
require_once __DIR__ . '/fw_database.lib.php';

// database version
define("N3S_DB_VERSION", 3);

/**
 * get config value
 */
function n3s_get_config($key, $def) {
    global $n3s_config;
    if (isset($n3s_config[$key])) {
        return $n3s_config[$key];
    }
    return $def;
}
/**
 * set config value
 */
function n3s_set_config($key, $val) {
    global $n3s_config;
    $n3s_config[$key] = $val;
}

function get_param($name, $def = '') {
    if (isset($_GET[$name])) {
      return $_GET[$name];
    }
    return $def;
  }
  
  function post_param($name, $def = '') {
    if (isset($_POST[$name])) {
      return $_POST[$name];
    }
    return $def;
  }

function n3s_getURL($page, $action, $params = array())
{
    global $n3s_config;
    $baseurl = $n3s_config['baseurl'];
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
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') ? 'https' : 'http',
        $_SERVER['HTTP_HOST'],
        $script_dir
    );
}

function n3s_get_db($type = 'main')
{
    global $n3s_config;
    global $n3s_db_handle;
    global $FW_DB_INFO;
    if (empty($n3s_db_handle)) $n3s_db_handle = array();
    if (isset($n3s_db_handle[$type])) return $n3s_db_handle[$type];
    // open db
    $file_db = $n3s_config["file_db_{$type}"];
    $file_init_sql = $n3s_config['dir_sql'] . "/init-{$type}.sql";
    database_set($file_db, $file_init_sql, $type);
    $db = database_get($type);
    // memorize db
    $n3s_db_handle[$type] = $db;
    return $db;
}

function n3s_template_fw($name, $params)
{
    global $n3s_config;
    global $DIR_TEMPLATE_CACHE, $DIR_TEMPLATE, $FW_TEMPLATE_PARAMS;
    $DIR_TEMPLATE = $n3s_config['dir_template'];
    $DIR_TEMPLATE_CACHE = $n3s_config['dir_cache'];
    $p = $params + $n3s_config;
    $FW_TEMPLATE_PARAMS = $p;
    template_render($name, []);
}

function n3s_error($title, $msg)
{
    n3s_template_fw('error.html', array(
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

function n3s_is_login() {
    // @see action/login.inc.php
    if (empty($_SESSION['n3s_login'])) {
        return FALSE;
    }
    return TRUE;
}

function n3s_get_user_id() {
    if (!n3s_is_login()) { return 0; }
    return empty($_SESSION['user_id']) ? 0 : intval($_SESSION['user_id']);
}

function n3s_get_login_info() {
    if (!n3s_is_login()) {
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

function n3s_is_admin() {
    $user_id = n3s_get_user_id();
    $admin_users = n3s_get_config('admin_users', [1]);
    foreach ($admin_users as $id) {
        if ($id === $user_id) {
            return TRUE;
        }
    }
    return FALSE;
}

function n3s_getEditToken($key = 'default', $update = TRUE)
{
  global $n3s_config;
  $sname = "n3s_edit_token_$key";
  if ($update == FALSE) {
    if (isset($_SESSION[$sname])) {
      $n3s_config['edit_token'] = $_SESSION[$sname];
      return $n3s_config['edit_token'];
    }
  }
  if (!isset($n3s_config['edit_token'])) {
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
  if ($ses != '' && $ses == $get) {
    return TRUE;
  }
  return FALSE;
}

