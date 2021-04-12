<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

function n3s_web_file()
{
    echo_file();
}
function n3s_api_file()
{
    echo_file();
}

function echo_file() {
    global $n3s_config;
    $file = empty($_GET['page']) ? '__empty__' : $_GET['page'];
    // テンプレート内のパス指定を削除
    $file = str_replace('..', '', $file);
    $file = str_replace('/', '', $file);
    $path = $n3s_config['dir_template']."/$file";
    if (!file_exists($path)) {
        header( "HTTP/1.1 404 Not Found" );
        exit;
    }
    if (preg_match('#\.css$#', $file)) {
        header('content-type:text/css; charset=utf-8');
    }
    if (preg_match('#\.js$#', $file)) {
        header('content-type:text/javascript; charset=utf-8');
    }
    elseif (preg_match('#\.png$#', $file, $m)) {
        header('content-type:image/png; charset=utf-8');
    }
    elseif (preg_match('#\.(jpg|jpeg|gif)$#', $file, $m)) {
        $ext = $m[1];
        header("content-type:image/$ext; charset=utf-8");
    }
    echo file_get_contents($path);
}
