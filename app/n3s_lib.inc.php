<?php
// --------------------------------------------------------
// n3s_lib.inc.php
// library for n3s
// --------------------------------------------------------

function n3s_getURL($page, $action, $params = array()) {
  global $n3s_config;
  $baseurl = $n3s_config['baseurl'];
  $url = "{$baseurl}/index.php?$page&$action";
  foreach ($params as $k => $v) {
    $url .= '&'.urlencode($k).'='.urlencode($v);
  }
  return $url;
}

function n3s_jump($page, $action, $params = array()) {
  $url = n3s_getURL($page, $action, $params);
  header("location:$url");
}

function n3s_hash_editkey($key) {
  $salt = 'H38oJpfD/K4PKg6Jf#qcvZt_1P@5XayuTmn';
  return hash('sha256', "$key::$salt");
}

function n3s_parseURI() {
  global $n3s_config;
  $uri = $_SERVER['REQUEST_URI'];
  $params = array();
  $path_args = array();
  list($script_path, $paramStr) = explode('?', $uri.'?');
  $a = explode('&', $paramStr);
  foreach ($a as $p) {
    if (strpos($p, '=') !== false) {
      list($key, $val) = explode('=', $p, 2);
    } else {
      $key = $p; $val = '';
    }
    $key = urldecode($key);
    $val = urldecode($val);
    $params[$key] = $val;
    if ($val === '') {
      $path_args[] = $key;
    }
  }
  array_push($path_args, NULL, NULL, NULL);
  // page
  $page = array_shift($path_args);
  if (isset($params['page'])) $page = $params['page'];
  if ($page == "") $page = 'all';
  // action
  $action = array_shift( $path_args );
  if (isset($params['action'])) $action = $params['action'];
  if ($action == "") $action = "list";
  // status
  $status = array_shift( $path_args );
  if (isset($params['status'])) $action = $params['status'];
  // set to conf
  $n3s_config['page']   = $_GET['page']   = $page;
  $n3s_config['action'] = $_GET['action'] = $action;
  $n3s_config['status'] = $_GET['status'] = $status;
  // set baseurl
  $script = $kona3conf['scriptname'] = basename($_SERVER['SCRIPT_NAME']);
  $script_dir = preg_replace("#/{$script}$#", "", $script_path);
  $n3s_config['baseurl'] = sprintf(
    "%s://%s%s",
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') ? 'https' : 'http',
    $_SERVER['HTTP_HOST'],
    $script_dir
  );
}

function n3s_get_db($type = 'main') {
  global $n3s_config;
  global $n3s_db_handle;
  if (empty($n3s_db_handle)) $n3s_db_handle = array();
  if (isset($n3s_db_handle[$type])) return $n3s_db_handle[$type];
  // open db
  $file_db = $n3s_config["file_db_{$type}"];
  $db = new PDO($file_db);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $n3s_db_handle[$type] = $db;
  return $db;
}

function n3s_template($name, $params) {
  global $n3s_config;
  extract($params);
  $dir_template = $n3s_config['dir_template']."/$name.tpl.php";
  include $dir_template;
}

function n3s_error($title, $msg) {
  $html = <<< EOS
<h3 class="error">$title</h3>
<div>{$msg}</div>
EOS;
  n3s_template('basic', array(
    "contents" => $html
  ));
}

function n3s_api_output($result, $data) {
  $data['result'] = $result;
  header('content-type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
