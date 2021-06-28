<?php
const MAX_APP = 30; // 何件まで表示するか

function n3s_web_list()
{
    $r = n3s_list_get();
    n3s_template_fw('list.html', $r);
}

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

function n3s_list_get()
{
    global $n3s_config;
  
    // get parameters
    $n3s_config['search_word'] = $search = isset($_GET['search_word']) ? trim($_GET['search_word']) : '';
    $nofilter = empty($_GET['nofilter']) ? 0 : intval($_GET['nofilter']);
    $onlybad = empty($_GET['onlybad']) ? 0 : intval($_GET['onlybad']);
    $find_user_id = empty($_GET['user_id']) ? 0 : intval($_GET['user_id']);
    $mode = empty($_GET['mode']) ? 'list' : $_GET['mode'];
    if (!empty($search)) { $mode = "search";}
    
    // get db
    $db = n3s_get_db();
    $find_user_info = [];
    // list
    $app_id = intval(empty($n3s_config['app_id']) ? 0 : $n3s_config['app_id']);
    if ($app_id <= 0) $app_id = PHP_INT_MAX;
    $wheres = array('app_id <= ?');
    // check nofilter parameters
    if ($nofilter < 1) { // パラメータがない場合(通報がないものだけを表示する - 通報機能 #18)
      $wheres[] = 'bad <= 3';
    }
    // check onlybad parameters
    if ($onlybad >= 1) { // 通報された投稿のみ表示
        $wheres[] = 'bad > 0';
    }
    // check user_id
    if ($find_user_id > 0) {
        $wheres[] = "user_id = $find_user_id";
        $find_user_info = db_get1("SELECT * FROM users WHERE user_id=?",[$find_user_id]);
    }
    $statements = array($app_id);
    if (!empty($n3s_config['search_word'])) {
        $wheres[] = 'author = ? OR title LIKE ?';
        $statements[] = $n3s_config['search_word'];
        $statements[] = "%".$n3s_config['search_word']."%";
    }
    $wheres[] = 'is_private = 0';
    $statements[] = MAX_APP;
    $list = [];
    if ($mode == 'list' || $mode == 'search') {
      $h = $db->prepare(
          'SELECT app_id,title,author,memo,mtime,fav,user_id FROM apps ' .
          ' WHERE ' . implode(' AND ', $wheres) .
          ' ORDER BY app_id DESC LIMIT ?');
      $h->execute($statements);
      $list = $h->fetchAll();
    } else if ($mode == 'ranking') {
      $wheres[] = 'fav > 0';
      $h = $db->prepare(
          'SELECT app_id,title,author,memo,mtime,fav,user_id FROM apps ' .
          ' WHERE ' . implode(' AND ', $wheres) .
          ' ORDER BY fav DESC, app_id DESC LIMIT ?');
      $h->execute($statements);
      $list = $h->fetchAll();
    }
    // next
    $min_id = PHP_INT_MAX;
    foreach ($list as &$row) {
        $id = $row['app_id'];
        if ($min_id >= $id) $min_id = $id - 1;
    }
    $next_url = n3s_getURL('all', 'list', [
        'app_id' => $min_id,
        'nofilter' => $nofilter,
        'user_id' => $find_user_id,
    ]);
    if ($app_id === 0) $next_url = ""; // トップなので次はない
    // ranking
    $ranking = null;
    if ($mode == 'list' && $find_user_id == 0 && $onlybad == 0 && $nofilter == 0) {
        // Nヶ月以内に更新されたアプリ
        $mon = 3;
        $mtime = time() - (60 * 60 * 24 * 30 * $mon);
        $h = $db->prepare('SELECT * FROM apps '.
          'WHERE (mtime > ?) AND (bad < 2) AND (fav > 0) AND (is_private = 0)'.
          'ORDER BY fav DESC LIMIT 15');
        $h->execute([$mtime]);
        $ranking = $h->fetchAll();
        // shuffle
        shuffle($ranking);
        // 上位5件を取る
        $ranking = array_splice($ranking, 0, 5);
    }

    return [
        "mode" => $mode,
        "list" => $list,
        "next_url" => $next_url,
        "ranking" => $ranking,
        "find_user_id" => $find_user_id,
        "find_user_info" => $find_user_info,
    ];
}
