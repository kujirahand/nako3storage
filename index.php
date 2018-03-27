<?php
global $n3s_config;

// read config file
$root = dirname(__FILE__);
$n3s_config = array(
  'version' => 0.5,
  'agent' => 'web',
  'dir_app' => $root.'/app'
);

// config parameters see lib/index.inc.php
$config_file = "$root/n3s_config.ini.php";
if (file_exists($config_file)) include_once($config_file);

// execute main file 
$main_file = $n3s_config['dir_app'].'/index.inc.php';
include_once($main_file);

