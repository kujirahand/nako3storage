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
    $find_user_info = [];
    $top_users = [];

    // --------------------------------------------------------
    // オプションを確認して条件などを設定
    // --------------------------------------------------------
    // list (app_id for list pager)
    $wheres = array('tag != "w_noname"');
    $statements = [];
    // check nofilter parameters
    if ($nofilter < 1 && $onlybad == 0) { // パラメータがない場合(通報がないものだけを表示する - 通報機能 #18)
        $wheres[] = 'bad == 0';
    }
    // check onlybad parameters
    if ($onlybad >= 1) { // 通報された投稿のみ表示 #18
        $wheres[] = 'bad > 0';
    }
    // check user_id
    if ($find_user_id > 0) {
        $wheres[] = "user_id = $find_user_id";
        $find_user_info = db_get1("SELECT * FROM users WHERE user_id=?", [$find_user_id], "users");
    }
    // 非公開投稿は表示しない
    $wheres[] = 'is_private = 0';
    $list = [];
    $list2 = [];

    // 現在のユーザー数を取得
    $user_count = db_get1('SELECT count(*) FROM users', [], "users");
    $user_count = $user_count['count(*)'];
    // 投稿された作品数を取得
    $app_count = db_get1('SELECT count(*) FROM apps', []);
    $app_count = $app_count['count(*)'];
    // --------------------------------------------------------
    // 表示モードごとに処理を分ける
    // --------------------------------------------------------
    if ($mode === 'list' || $mode === 'search') { // 通常 or 検索モード
        // user_id > 0
        $wheres1 = unserialize(serialize($wheres));
        $wheres1[] = "user_id > 0";
        $sql = 
            'SELECT app_id,title,author,memo,mtime,fav,user_id,tag,nakotype,bad FROM apps ' .
            ' WHERE ' . implode(' AND ', $wheres1) .
            ' ORDER BY mtime DESC LIMIT ? OFFSET ?';
        $list = db_get($sql, [MAX_APP, $offset]);
        // user_id == 0
        $wheres2 = unserialize(serialize($wheres));
        $wheres2[] = "user_id == 0";
        $sql2 =
            'SELECT app_id,title,author,memo,mtime,fav,user_id,tag,nakotype,bad FROM apps ' .
            ' WHERE ' . implode(' AND ', $wheres2) .
            ' ORDER BY mtime DESC LIMIT ? OFFSET ?';
        $list2 = db_get($sql2, [MAX_APP, $offset]);
    }
    elseif ($mode === 'ranking') { // ランキング表示モード
        $wheres[] = 'fav >= 3';
        $sql =
            'SELECT app_id,title,author,memo,mtime,fav,user_id,tag,nakotype,bad FROM apps ' .
            ' WHERE ' . implode(' AND ', $wheres) .
            ' ORDER BY fav DESC, app_id DESC LIMIT ?';
        $statements[] = MAX_APP;
        $list = db_get($sql, $statements);
        $list2 = [];
    }
    // --------------------------------------------------------
    // next url link
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
        // 全期間を取得
        $ranking_all = db_get('SELECT * FROM apps '.
            'WHERE (bad < 2) AND (fav >= 3) AND (is_private = 0) AND (tag != "w_noname")'.
            'ORDER BY fav DESC LIMIT 30', []);

        // Nヶ月以内に更新されたアプリを取得
        $mon = 6;
        $mtime = time() - (60 * 60 * 24 * 30 * $mon);
        $ranking = db_get('SELECT * FROM apps '.
            'WHERE (mtime > ?) AND (bad < 2) AND (fav >= 2) AND (is_private = 0) AND (tag != "w_noname")'.
            'ORDER BY fav DESC LIMIT 40', [$mtime]);
        // ランキング情報を得る
        $ranking_total = $ranking_all + $ranking;

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
        $ranking_all = array_splice($ranking_all, 0, 5);

        // 人気のユーザー (#185)
        $users = [];
        $users_names = [];
        foreach ($ranking_total as $row) {
            $user_id = $row['user_id'];
            $name = $row['author'];
            if (empty($users[$user_id])) {
                $users[$user_id] = 0;
            }
            $users[$user_id] += 1;
            $users_names[$user_id] = $name;
        }
        arsort($users);
        foreach ($users as $user_id => $count) {
            $top_users[] = [
                'user_id' => $user_id,
                'name' => $users_names[$user_id],
                'count' => $count,
            ];
        }
    }

    // --------------------------------------------------------
    // ■ 統計情報
    // --------------------------------------------------------
    // $toukei = db_get1('SELECT count(*) FROM apps');
    // $total_post = $toukei['count(*)'];
    // アイコンを付ける
    n3s_list_setIcon($list);
    n3s_list_setIcon($list2);
    n3s_list_setIcon($ranking);
    n3s_list_setIcon($ranking_all);
    n3s_list_setTagLink($list);
    n3s_list_setTagLink($list2);
    n3s_list_setTagLink($ranking);
    n3s_list_setTagLink($ranking_all);
    return [
        "mode" => $mode,
        "list" => $list,
        "list2" => $list2,
        "next_url" => $next_url,
        "ranking" => $ranking,
        "ranking_all" => $ranking_all,
        "find_user_id" => $find_user_id,
        "find_user_info" => $find_user_info,
        "noindex" => $noindex,
        // "total_post" => $total_post,
        "onlybad" => $onlybad,
        "offset" => $offset,
        "is_admin" => n3s_is_admin(),
        "top_users" => $top_users,
        "user_count" => $user_count,
        "app_count" => $app_count,
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
            "top_users" => [],
        );
    }
    n3s_api_output(true, array(
        "list" => $res
    ));
}
