<?php
const MAX_APP = 15; // 何件まで表示するか
const MAX_PAGE_OFFSET = 500; // 500以降は表示しない

// ブラウザからのアクセスがあったとき
function n3s_web_list()
{
    $r = n3s_list_get();
    n3s_template_fw('list.html', $r);
}

// 一覧に表示するデータを取得する
function n3s_list_get()
{
    global $n3s_config;
    // --------------------------------------------------------
    // URLパラメータの取得
    // --------------------------------------------------------
    $mode = empty($_GET['mode']) ? 'list' : $_GET['mode'];
    $nofilter = empty($_GET['nofilter']) ? 0 : intval($_GET['nofilter']);
    $onlybad = empty($_GET['onlybad']) ? 0 : intval($_GET['onlybad']);
    $find_user_id = empty($_GET['user_id']) ? 0 : intval($_GET['user_id']);
    $noindex = empty($_GET['noindex']) ? 0 : intval($_GET['noindex']);
    if ($onlybad || $nofilter) { // サーチエンジンから検索されないようにする
        $noindex = 1;
    }

    // 検索のページャーのため
    $offset = intval(empty($_GET['offset']) ? 0 : $_GET['offset']);
    if ($offset > MAX_PAGE_OFFSET) { $offset = MAX_PAGE_OFFSET; }

    // --------------------------------------------------------
    // データベースに接続
    // --------------------------------------------------------
    $db = n3s_get_db();
    $find_user_info = [];

    // --------------------------------------------------------
    // オプションを確認して条件などを設定
    // --------------------------------------------------------
    // list (app_id for list pager)
    $wheres = array('tag != "w_noname"');
    $statements = [];
    // check nofilter parameters
    if ($nofilter < 1) { // パラメータがない場合(通報がないものだけを表示する - 通報機能 #18)
        $wheres[] = 'bad == 0';
    }
    // check onlybad parameters
    if ($onlybad >= 1) { // 通報された投稿のみ表示 #18
        $wheres[] = 'bad > 0';
    }
    // check user_id
    if ($find_user_id > 0) {
        $wheres[] = "user_id = $find_user_id";
        $find_user_info = db_get1("SELECT * FROM users WHERE user_id=?", [$find_user_id]);
    }
    // 非公開投稿は表示しない
    $wheres[] = 'is_private = 0';
    $list = [];

    // --------------------------------------------------------
    // 表示モードごとに処理を分ける
    // --------------------------------------------------------
    if ($mode === 'list' || $mode === 'search') { // 通常 or 検索モード
        $h = $db->prepare(
            'SELECT app_id,title,author,memo,mtime,fav,user_id,tag,nakotype,bad FROM apps ' .
            ' WHERE ' . implode(' AND ', $wheres) .
            ' ORDER BY mtime DESC LIMIT ? OFFSET ?'
        );
        $statements[] = MAX_APP;
        $statements[] = $offset;
        $h->execute($statements);
        $list = $h->fetchAll();
    }
    elseif ($mode === 'ranking') { // ランキング表示モード
        $wheres[] = 'fav >= 3';
        $h = $db->prepare(
            'SELECT app_id,title,author,memo,mtime,fav,user_id,tag,nakotype,bad FROM apps ' .
            ' WHERE ' . implode(' AND ', $wheres) .
            ' ORDER BY fav DESC, app_id DESC LIMIT ?'
        );
        $statements[] = MAX_APP;
        $h->execute($statements);
        $list = $h->fetchAll();
    }
    // next
    $next_url = n3s_getURL('all', 'list', [
        'offset' => ($offset + MAX_APP),
        'nofilter' => $nofilter,
        'user_id' => $find_user_id,
        'onlybad' => $onlybad,
        'noindex' => $noindex,
    ]);

    // --------------------------------------------------------
    // ■ Ranking (トップページだけに表示する)
    // --------------------------------------------------------
    $ranking = [];
    $ranking_all = [];
    if ($mode === 'list' && $find_user_id === 0 && $onlybad === 0 && $nofilter === 0 && $offset == 0) {
        // Nヶ月以内に更新されたアプリ
        $mon = 6;
        $mtime = time() - (60 * 60 * 24 * 30 * $mon);

        $ranking_all = db_get('SELECT * FROM apps '.
            'WHERE (bad < 2) AND (fav >= 3) AND (is_private = 0) AND (tag != "w_noname")'.
            'ORDER BY fav DESC LIMIT 20', []);

        $ranking = db_get('SELECT * FROM apps '.
            'WHERE (mtime > ?) AND (bad < 2) AND (fav >= 3) AND (is_private = 0) AND (tag != "w_noname")'.
            'ORDER BY fav DESC LIMIT 30', [$mtime]);

        // 常に異なる作品が表示されるようにシャッフルして新鮮味を出す
        // 上位N件を取る
        shuffle($ranking);
        $ranking = array_splice($ranking, 0, 7);
        // 重なる投稿を削除
        $all = [];
        foreach ($ranking_all as $row) {
            $flag = TRUE;
            $all_id = $row['app_id'];
            foreach ($ranking as $r) {
                $a_id = $r['app_id'];
                if ($a_id == $all_id) { $flag = FALSE; break; }
            }
            if ($flag) {
                $all[] = $row;
            }
        }
        shuffle($ranking_all);
        $ranking_all = array_splice($ranking_all, 0, 10);
    }

    // --------------------------------------------------------
    // ■ 統計情報
    // --------------------------------------------------------
    // $toukei = db_get1('SELECT count(*) FROM apps');
    // $total_post = $toukei['count(*)'];

    // アイコンを付ける
    n3s_list_setIcon($list);
    n3s_list_setIcon($ranking);
    n3s_list_setIcon($ranking_all);
    n3s_list_setTagLink($list);
    n3s_list_setTagLink($ranking);
    n3s_list_setTagLink($ranking_all);
    return [
        "mode" => $mode,
        "list" => $list,
        "next_url" => $next_url,
        "ranking" => $ranking,
        "ranking_all" => $ranking_all,
        "find_user_id" => $find_user_id,
        "find_user_info" => $find_user_info,
        "noindex" => $noindex,
        // "total_post" => $total_post,
        "onlybad" => $onlybad,
        "offset" => $offset,
    ];
}

// Web API向けのアクセスがあったとき
function n3s_api_list()
{
    $r = n3s_list_get();
    $res = array();
    foreach ($r['list'] as $row) {
        // 必要なデータだけを選んで出力する
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
