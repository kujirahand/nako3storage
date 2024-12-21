<?php
// テンプレートディレクトリにあるファイルを出力する
//
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

function echo_file()
{
    global $n3s_config;
    // ファイル情報を page パラメータから得る
    $file = empty($_GET['page']) ? '' : $_GET['page'];
    // idと拡張子を得る
    if (! preg_match('#^([a-zA-Z0-9_]+)(\.[a-zA-Z0-9_]+)$#', $file, $m)) {
        header("HTTP/1.1 404 Not Found");
        exit;
    }
    $id = $m[1];
    $ext = $m[2];

    // パスを得る
    $path = $n3s_config['dir_resource']."/$file";
    if (! file_exists($path)) {
        header("HTTP/1.1 404 Not Found");
        exit;
    }
    // 拡張子に応じてヘッダを出す
    if ($ext === '.css') {
        header('content-type: text/css; charset=utf8');
    } elseif ($ext === '.js') {
        header('content-type: 	text/javascript; charset=utf8');
    } else {
        $mime = @mime_content_type($path);
        if (! $mime) {
            header("content-type:$mime");
        } else {
            header('content-type:application/octet-stream');
        }
    }
    // ファイルを出力
    echo file_get_contents($path);
}
