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
    // 注意: ここで配信するのはユーザー投稿の本文(材料テキスト)である。
    // インライン表示時にブラウザがスクリプトを実行し得る MIME 型でそのまま配信すると
    // 主オリジンでの Stored XSS になるため、安全な型のみ許可する (todo-security.md #2)。
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . n3s_plain_safe_content_type($r['nakotype']));
    header("Content-Disposition: inline; filename=\"$page\"");
    // アクセスコントロール
    header('Access-Control-Allow-Origin: *');
    // 内容を出力
    $body = trim($b['body'])."\n\n";
    if (substr($body, 0, 2) == '#!') {
        $body = str_replace("\r\n", "\n", $body);
    }
    echo $body;
}

// 投稿本文をインライン配信する際の安全な Content-Type(charset付き)を返す。
// image/svg+xml や text/html など、インライン表示でスクリプトが実行され得る型は
// text/plain に落とす。安全と分かっている型のみそのまま通す (todo-security.md #2)。
function n3s_plain_safe_content_type($nakotype)
{
    if ($nakotype === 'wnako' || $nakotype === 'cnako') {
        return 'text/plain; charset=utf-8';
    }
    $mime = n3s_get_mime($nakotype);
    $safe_inline = ['text/plain', 'text/csv', 'text/tsv', 'application/json'];
    $mime_main = trim(explode(';', strtolower($mime))[0]);
    if (!in_array($mime_main, $safe_inline, true)) {
        $mime = 'text/plain'; // 安全でない型は平文として配信(スクリプト実行を防ぐ)
    }
    return $mime . '; charset=utf-8';
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
