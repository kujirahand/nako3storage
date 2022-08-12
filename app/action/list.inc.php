<?php
const MAX_APP = 15; // 何件まで表示するか

// ブラウザからのアクセスがあったとき
function n3s_web_list()
{
    $r = n3s_list_get();
    n3s_template_fw('list.html', $r);
}

// Web API向けのアクセスがあったとき
function n3s_api_list()
{
    $r = n3s_list_get();
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
function n3s_list_get()
{
    global $n3s_config;

    // get parameters
    $n3s_config['search_word'] = $search = isset($_GET['search_word']) ? trim($_GET['search_word']) : '';
    $nofilter = empty($_GET['nofilter']) ? 0 : (int) ($_GET['nofilter']);
    $onlybad = empty($_GET['onlybad']) ? 0 : (int) ($_GET['onlybad']);
    $find_user_id = empty($_GET['user_id']) ? 0 : (int) ($_GET['user_id']);
    $mode = empty($_GET['mode']) ? 'list' : $_GET['mode'];
    $noindex = empty($_GET['noindex']) ? 0 : (int) ($_GET['noindex']);
    if (! empty($search)) {
        $mode = "search";
    }
    if ($onlybad || $nofilter) {
        $noindex = 1;
    }

    // --------------------------------------------------------
    // データベースに接続
    // --------------------------------------------------------
    $db = n3s_get_db();
    $find_user_info = [];

    // --------------------------------------------------------
    // オプションを確認して条件などを設定
    // --------------------------------------------------------
    // list (app_id for list pager)
    $app_id = (int) (empty($n3s_config['app_id']) ? 0 : $n3s_config['app_id']);
    if ($app_id <= 0) {
        $app_id = PHP_INT_MAX;
    }
    $wheres = array('app_id <= ?', 'tag != "w_noname"');
    // check nofilter parameters
    if ($nofilter < 1) { // パラメータがない場合(通報がないものだけを表示する - 通報機能 #18)
        $wheres[] = 'bad <= 2';
        $wheres[] = 'tag != "なでしこ3本"';
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
    $statements = array($app_id);
    // 検索モードか？
    if (!empty($n3s_config['search_word'])) {
        // 検索モードの場合
        $wheres[] = 'author = ? OR title LIKE ?';
        $statements[] = $n3s_config['search_word'];
        $statements[] = "%".$n3s_config['search_word']."%";
        $mode = 'search'; // 必ず検索モードにする
    }
    // 非公開投稿は表示しない
    $wheres[] = 'is_private = 0';
    $statements[] = MAX_APP;
    $list = [];

    // --------------------------------------------------------
    // 表示モードごとに処理を分ける
    // --------------------------------------------------------
    if ($mode === 'list' || $mode === 'search') { // 通常 or 検索モード
        $h = $db->prepare(
            'SELECT app_id,title,author,memo,mtime,fav,user_id FROM apps ' .
            ' WHERE ' . implode(' AND ', $wheres) .
            ' ORDER BY app_id DESC LIMIT ?'
        );
        $h->execute($statements);
        $list = $h->fetchAll();
    }
    elseif ($mode === 'ranking') { // ランキング表示モード
        $wheres[] = 'fav >= 3';
        $h = $db->prepare(
            'SELECT app_id,title,author,memo,mtime,fav,user_id FROM apps ' .
            ' WHERE ' . implode(' AND ', $wheres) .
            ' ORDER BY fav DESC, app_id DESC LIMIT ?'
        );
        $h->execute($statements);
        $list = $h->fetchAll();
    }
    // next
    $min_id = PHP_INT_MAX;
    foreach ($list as &$row) {
        $id = $row['app_id'];
        if ($min_id >= $id) {
            $min_id = $id - 1;
        }
    }
    $next_url = n3s_getURL('all', 'list', [
        'app_id' => $min_id,
        'nofilter' => $nofilter,
        'user_id' => $find_user_id,
        'onlybad' => $onlybad,
        'noindex' => $noindex,
    ]);
    if ($app_id === 0) {
        $next_url = "";
    } // トップなので次はない

    // --------------------------------------------------------
    // ■ Ranking (トップページだけに表示する)
    // --------------------------------------------------------
    $ranking = [];
    $ranking_all = [];
    if ($mode === 'list' && $find_user_id === 0 && $onlybad === 0 && $nofilter === 0) {
        // Nヶ月以内に更新されたアプリ
        $mon = 6;
        $mtime = time() - (60 * 60 * 24 * 30 * $mon);

        $ranking_all = db_get('SELECT * FROM apps '.
            'WHERE (bad < 2) AND (fav >= 3) AND (is_private = 0) '.
            'ORDER BY fav DESC LIMIT 10', []);

        $ranking = db_get('SELECT * FROM apps '.
            'WHERE (mtime > ?) AND (bad < 2) AND (fav >= 3) AND (is_private = 0) '.
            'ORDER BY fav DESC LIMIT 25', [$mtime]);

        // 常に異なる作品が表示されるようにシャッフルして新鮮味を出す
        // 上位N件を取る
        shuffle($ranking);
        $ranking = array_splice($ranking, 0, 10);
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
    }

    // --------------------------------------------------------
    // ■ 統計情報
    // --------------------------------------------------------
    $row = db_get1('SELECT count(*) FROM apps');
    $total_post = $row['count(*)'];

    return [
        "mode" => $mode,
        "list" => $list,
        "next_url" => $next_url,
        "ranking" => $ranking,
        "ranking_all" => $ranking_all,
        "find_user_id" => $find_user_id,
        "find_user_info" => $find_user_info,
        "noindex" => $noindex,
        "total_post" => $total_post,
    ];
}
