<?php
function n3s_web_image() {
    echo 'api only'; exit;
}
function n3s_api_image() {
    $fname = empty($_GET['f']) ? '' : $_GET['f'];
    
    // match
    if (!preg_match('/^([0-9]+)\.([a-zA-Z0-9]+)$/', $fname, $m)) {
      header("HTTP/1.0 404 Not Found");
      echo '404 not found ... invalid filename.';
      exit;
    }
    $id = intval($m[1]);
    $ext = $m[2];
    $path = n3s_getImageFile($id, $ext);
    // check path
    if (!file_exists($path)) {
      header("HTTP/1.0 404 Not Found");
      echo '404 not found';
      exit;
    }
    
    // アクセスコントロール
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: '.n3s_get_mime($ext));
    
    // output
    readfile($path);
}


