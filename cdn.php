<?php
// CDN.php --- redirect to cdn

// get nadesiko default version
require_once __DIR__.'/nako_version.inc.php';

// setting
// ref) https://www.jsdelivr.com/
$CDN = 'https://cdn.jsdelivr.net/npm/nadesiko3';
$cache_dir = __DIR__.'/cache-cdn';
$uri = dirname($_SERVER['SCRIPT_NAME']);
$cache_url = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].$uri."/cache-cdn";
// for nadesi.com/v3/cdn.php
if ($_SERVER['HTTP_HOST'] == 'nadesi.com' && $uri == '/v3') {
  $cache_url = 'https://nadesi.com/v3/storage/cache-cdn';
}


// get parameters
$ver = get('v', NAKO_DEFAULT_VERSION);
$file = get('f', 'release/wnako3.js');
$run = isset($_GET['run']) ? '?run' : '';

// check parameters
// 先頭に/があれば削る
if (substr($file, 0, 1) == '/') {
  $file = substr($file, 1);
}

// redirect url
$url = "{$CDN}@{$ver}/{$file}{$run}";
// check mime (ex) cdn.php?f=src/wnako3_editor.css
if (preg_match('#\.(css|html)$#', $file, $m)) {
  // get from cdn
  $file = str_replace('..', '', $file);
  $file = str_replace('/', '___', $file);
  $file = preg_replace('|[^a-zA-Z0-9-\_.]+|','',$file);
  $cache_file = $cache_dir."/{$ver}@{$file}";
  $cache_url_file = "$cache_url/{$ver}@{$file}";
  if (!file_exists($cache_file)) {
    $body = @file_get_contents($url);
    if ($body == '') {
      header("HTTP/1.0 404 Not Found");
      echo "file not found.";
      exit;
    }
    @file_put_contents($cache_file, $body);
  } else {
    $body = @file_get_contents($cache_file);
  }
  if ($m[1] == 'css') {
    header('content-type: text/css; charset=utf-8');
    echo $body;
  }
  // header("location: $cache_url_file", TRUE, 307);
  exit;
}
// redirect
header("location: $url", TRUE, 307);
exit;


function get($key, $def = '') {
  if (!isset($_GET[$key])) {
    return $def;
  }
  return $_GET[$key];
}

