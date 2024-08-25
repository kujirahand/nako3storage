<?php
// CDN.php --- redirect to cdn
global $cache_dir, $CDN, $cache_url, $cache_config;
// cache_config
$cache_config = [
  'cache_all' => TRUE,
];

// get nadesiko default version
require_once __DIR__.'/nako_version.inc.php';
require_once __DIR__.'/app/mime.inc.php';

// setting
// ref) https://www.jsdelivr.com/
$CDN = 'https://cdn.jsdelivr.net/npm/nadesiko3';
$cache_dir = __DIR__.'/cache-cdn';
$uri = dirname($_SERVER['SCRIPT_NAME']);
$scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : '';
if ($scheme !== '') { $scheme .= ':'; }
$cache_url = $scheme.'//'.$_SERVER['HTTP_HOST'].$uri."/cache-cdn";

// for n3s.nadesi.com/cdn.php
if ($_SERVER['HTTP_HOST'] === 'nadesi.com' && $uri === '/v3') {
  $cache_url = 'https://nadesi.com/v3/storage/cache-cdn';
}
else if ($_SERVER['HTTP_HOST'] === 'n3s.nadesi.com') {
  $cache_url = 'https://n3s.nadesi.com/cache-cdn';
}

// get parameters
$ver = get('v', NAKO_DEFAULT_VERSION);
$file = get('f', 'release/wnako3.js');
$run = isset($_GET['run']) ? '?run' : '';
// check ver
if (! preg_match('#^3\.\d{1,3}\.\d{1,3}$#', $ver)) {
  $ver = NAKO_DEFAULT_VERSION;
}

// check parameters
// 先頭に/があれば削る
if (substr($file, 0, 1) === '/') {
  $file = substr($file, 1);
}

// redirect url
$url = "{$CDN}@{$ver}/{$file}{$run}";

// check mime (ex) cdn.php?f=src/wnako3_editor.css
if ($cache_config['cache_all']) {
  useCache($ver, $url, $file);
}
else if (preg_match('#\.(css|html)$#', $file, $m)) {
  $ext = $m[1];
  useCache($ver, $url, $file, $ext);
}
if (basename($file) === 'wnako3webworker.js') {
  useCache($ver, $url, $file, 'js');
} 

// redirect
header('Access-Control-Allow-Origin: *');
header("location: $url", TRUE, 307);
header("x-memo: $file;");
exit;

function useCache($ver, $url, $file, $ext = '') {
  global $cache_dir, $CDN, $cache_url, $cache_config;
  // get from cdn
  $file = str_replace('..', '', $file);
  $file = str_replace('/', '___', $file);
  $file = preg_replace('#[^a-zA-Z0-9\-\_\.]+#','',$file);
  $save_dir = $cache_dir."/{$ver}";
  $cache_file = $save_dir."/{$file}";
  $cache_url_file = "$cache_url/{$ver}/{$file}";
  
  // キャッシュが存在しないので改めて取得する場合
  if (! file_exists($cache_file)) {
    // WEBからファイルを取得
    $body = @file_get_contents($url);
    if ($body === "" || $body === FALSE || strlen(trim($body)) <= 2) {
      // [失敗条件] FALSE or 空
      // https://www.php.net/manual/ja/function.file-get-contents.php
      header("HTTP/1.0 404 Not Found");
      echo "file not found.";
      exit;
    }
    if (! file_exists($save_dir)) { mkdir($save_dir); }
    // 取得したファイルを保存
    @file_put_contents($cache_file, $body);
  } else {
    // キャッシュからファイルを取得
    $body = @file_get_contents($cache_file);
  }
  // output
  header('Access-Control-Allow-Origin: *');
  // check ext
  if ($ext === '') {
    if (preg_match('#\.([a-z0-9_]+)$#', $file, $m)) {
      $ext = $m[1];
    }
  }
  // ファイルを出力する
  if ($ext === 'css') {
    header('content-type: text/css; charset=utf-8');
    echo $body;
  } else if ($ext === 'js' || $ext === 'mjs') {
    header('content-type: text/javascript; charset=utf-8');
    echo $body;
  } else if ($ext === 'map') {
    header('content-type: application/json; charset=utf-8');
    header("x-memo: $url"); 
    echo $body;
  } else {
    header("location: $cache_url_file", TRUE, 307);
  }
  exit;
}

function get($key, $def = '') {
  if (! isset($_GET[$key])) {
    return $def;
  }
  return $_GET[$key];
}

