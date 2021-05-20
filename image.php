<?php

// id
$fname = empty($_GET['f']) ? '' : $_GET['f'];

// match
if (!preg_match('/^([0-9]+)\.([a-zA-Z0-9]+)$/', $fname, $m)) {
  header("HTTP/1.0 404 Not Found");
  echo '404 not found';
  exit;
}
$id = intval($m[1]);
$ext = $m[2];
$dir_id = floor($id / 100);
$dir = sprintf('%03d', $dir_id);

// check path
$path = __DIR__."/images/$dir/{$id}.{$ext}";
if (!file_exists($path)) {
  header("HTTP/1.0 404 Not Found");
  echo '404 not found';
  exit;
}

// アクセスコントロール
header('Access-Control-Allow-Origin: *');
header('Content-Type: '.get_mime($ext));

// output
readfile($path);

function get_mime($ext) {
  $mime = [
    "jpg" => "image/jpeg",
    "jpeg" => "image/jpeg",
    "jpe" => "image/jpeg",
    "gif" => "image/gif",
    "png" => "image/png",
    "mp3" => "audio/mpeg",
    "ogg" => "audio/ogg",
    "oga" => "audio/ogg",
    "txt" => "text/plain",
    "csv" => "text/csv",
    "tsv" => "text/tsv",
    "xml" => "text/xml",
    "json" => "application/json",
    "js" => "text/javascreipt",
  ];
  $ext = strtolower($ext);
  if (isset($mime[$ext])) {
    return $mime[$ext];
  }
  return "application/octet-stream";
}



