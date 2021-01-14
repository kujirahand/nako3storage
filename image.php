<?php

// id
$fname = empty($_GET['f']) ? $_GET['f'] : '';

// match
if (!preg_match('/^[a-zA-Z0-9\_\-\.]+$/', $fname)) {
  header("HTTP/1.0 404 Not Found");
  echo '404 not found';
  exit;
}

// check path
$path = __DIR__.'/images/'.$fname;
if (!file_exists($path)) {
  header("HTTP/1.0 404 Not Found");
  echo '404 not found';
  exit;
}

// アクセスコントロール
header('Access-Control-Allow-Origin: *');
// output
readfile($path);


