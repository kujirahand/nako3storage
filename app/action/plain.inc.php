<?php

require_once dirname(__DIR__).'/mime.inc.php';
require_once __DIR__.'/save.inc.php';

function n3s_web_plain()
{
    $page = isset($_GET['page']) ? $_GET['page'] : '0';
    // check pattern
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $page)) {
        error404(); exit;
    }
    $r = null;
    // app_id ?
    if (preg_match('/^[0-9]+$/', $page)) {
        $app_id = intval($page);
        $r = db_get1('SELECT * FROM apps WHERE app_id=?', [$app_id]);
        if (!$r) {
            error404();
            exit;
        }
        $page = $app_id;
    }
    // app_name ?
    else {
        $r = db_get1('SELECT * FROM apps WHERE app_name=? LIMIT 1', [$page]);
        if (!$r) {
            error404();
            exit;
        }
        $page = $r['app_id'];
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
