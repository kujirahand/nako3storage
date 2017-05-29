<?php

include_once dirname(__FILE__).'/n3s_lib.inc.php';

n3s_main();

function n3s_main() {
  global $n3s_config;
  n3s_check_config();
  n3s_init_db();
  n3s_parseURI();
  n3s_action();
}

function n3s_check_config() {
  global $n3s_config;
  $root = dirname(dirname(__FILE__));
  $def_values = array(
    "dir_data"        => "{$root}/data",
    "dir_app"         => "{$root}/app",
    "dir_template"    => "{$root}/app/template",
    "dir_action"      => "{$root}/app/action",
    "file_database"   => "{$root}/data/n3s_main.sqlite",
    "admin_password"  => "hoge",
  );
  foreach ($def_values as $key => $def) {
    if (empty($n3s_config[$key])) $n3s_config[$key] = $def;
  }
}

function n3s_action() {
  global $n3s_config;
  $action = $_GET['action'];
  $action = preg_replace('/([a-zA-Z0-9_]+)/', '$1', $action);
  $file_action = $n3s_config['dir_action']."/$action.inc.php";
  $func_action = "n3s_action_$action";
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
