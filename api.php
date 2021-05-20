<?php
// [nako3storage] api.php

// デフォルトを読む
require_once __DIR__.'/app/n3s_config.def.php';

// デフォルトとの差分を指定する
$config_file = __DIR__."/n3s_config.ini.php";
if (file_exists($config_file)) include_once($config_file);

// api.php 固有の設定
global $n3s_config;
$n3s_config['agent'] = 'api';

// execute main file
$main_file = $n3s_config['dir_app'].'/index.inc.php';
include_once($main_file);


