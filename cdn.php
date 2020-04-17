<?php
$DEF_VERSION = '3.0.72';
// ref) https://www.jsdelivr.com/
$CDN = 'https://cdn.jsdelivr.net/npm/nadesiko3';

function get($key, $def = '') {
  if (!isset($_GET[$key])) {
    return $def;
  }
  return $_GET[$key];
}


$ver = get('v', $DEF_VERSION);
$file = get('f', 'release/wnako3.js');
$run = isset($_GET['run']) ? '?run' : '';

// 先頭に/があれば削る
if (substr($file, 0, 1) == '/') {
  $file = substr($file, 1);
}

$url = "{$CDN}@{$ver}/{$file}{$run}";
header("location: $url", TRUE, 307);

