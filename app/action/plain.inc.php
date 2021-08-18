<?php

require_once dirname(__DIR__).'/mime.inc.php';
require_once __DIR__.'/save.inc.php';

function n3s_web_plain()
{
    $page = intval(isset($_GET['page']) ? $_GET['page'] : 0);
    if ($page <= 0) {
        error404();
        exit;
    }
    $r = db_get1('SELECT * FROM apps WHERE app_id=?', [$page]);
    if (!$r) {
        error404();
        exit;
    }
    if ($r['is_private'] > 0) {
        error403();
        exit;
    }
    // ok
    $b = n3s_getMaterialData($page);
    if (!$b) {
        error403();
        exit; // broken?
    }
    // output
    $t = $r['nakotype'];
    if ($t == 'wnako' || $t == 'cnako') {
        header("Content-Type: text/plain; charset=utf-8");
        header("Content-Disposition: inline; filename=\"$page\"");
    } else {
        $mime = n3s_get_mime($t);
        header("Content-Type: $mime; charset=utf-8");
        // header("Content-Disposition: attachment; filename=\"$page\"");
        header("Content-Disposition: inline; filename=\"$page\"");
    }
    // アクセスコントロール
    header('Access-Control-Allow-Origin: *');
    // 内容を出力
    $body = trim($b['body'])."\n\n";
    if (substr($body, 0, 2) == '#!') {
        $body = str_replace("\r\n", "\n", $body);
    }
    echo $body;
}

function error403()
{
    header("HTTP/1.1 403 Forbidden");
    echo "403 Forbidden";
    exit;
}

function error404()
{
    header("HTTP/1.1 404 Not Found");
    echo "404 File Not Found...";
    exit;
}
