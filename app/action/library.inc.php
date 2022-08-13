<?php
const MAX_APP = 20; // 何件まで表示するか

// ブラウザからのアクセスがあったとき
function n3s_web_library()
{
    $r = n3s_library_get();
    n3s_template_fw('library.html', $r);
}

// Web API向けのアクセスがあったとき
function n3s_api_list()
{
    $r = n3s_library_get();
    $res = array();
    foreach ($r['list'] as $row) {
        $res[] = array(
            "app_id" => $row['app_id'],
            "title" => $row['title'],
            "user_id" => $row['user_id'],
            "author" => $row['author'],
            "memo" => $row['memo'],
            "mtime" => $row['mtime'],
        );
    }
    n3s_api_output(true, array(
        "list" => $res
    ));
}

// 一覧に表示するデータを取得する
function n3s_library_get()
{
    global $n3s_config;
    $page = empty($_GET['page']) ? 0 : intval($_GET['page']);
    $app_id = empty($_GET['app_id']) ? 0 : intval($_GET['app_id']);
    $offset = $page * MAX_APP;
    $list = db_get(
        'SELECT app_id,title,author,app_name,nakotype,memo,mtime,fav,user_id FROM apps ' .
        ' WHERE (app_id > ?) AND (app_name != "") AND (is_private == 0)'.
        ' ORDER BY fav DESC LIMIT ? OFFSET ?',[$app_id, MAX_APP, $page]);
    if (!$list) { $list = []; }
    foreach ($list as &$i) {
        $i['ext'] = '.txt';
        switch ($i['nakotype']) {
            case 'wnako': $i['ext'] = '.nako3'; break;
            case 'cnako': $i['ext'] = '.nako3'; break;
            case 'sh': $i['ext'] = '.sh'; break;
            case 'js': $i['ext'] = '.js'; break;
            default: break;
        }
    }
    n3s_list_setIcon($list);
    // next
    $next_url = n3s_getURL(($page + 1), 'library', []);

    return [
        "list" => $list,
        "next_url" => $next_url,
    ];
}
