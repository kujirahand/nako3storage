<?php
const MAX_APP_RECENT = 20; // 最新の投稿を何件まで表示するか
const MAX_APP_GUEST = 4; // ログインなし投稿を何件まで表示するか
const MAX_APP_RANKING = 8; // トップページのランキング枠を何件まで表示するか
const MAX_LIST_MATERIALS = 12; // トップページの人気素材枠を何件まで表示するか
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
    $wheres = array('show_list = 1'); // 一覧掲載フラグ (#202)
    $where_params = []; // $wheres の "?" に対応する値を、出現順に積んでいく
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
        $wheres[] = 'user_id = ?';
        $where_params[] = $find_user_id;
        $find_user_info = db_get1("SELECT * FROM users WHERE user_id=?", [$find_user_id], "users");
        if ($find_user_info) {
            $find_user_info['profile_url_large'] = n3s_get_user_image_url($find_user_info, 0);
        }
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
            'SELECT app_id,title,author,memo,mtime,fav,view,user_id,tag,nakotype,bad,image_id,comment_count FROM apps ' .
            ' WHERE ' . implode(' AND ', $wheres1) .
            ' ORDER BY mtime DESC LIMIT ? OFFSET ?';
        $list = db_get($sql, array_merge($where_params, [MAX_APP_RECENT, $offset]));
        // user_id == 0
        $wheres2 = unserialize(serialize($wheres));
        $wheres2[] = "user_id == 0";
        $sql2 =
            'SELECT app_id,title,author,memo,mtime,fav,view,user_id,tag,nakotype,bad,image_id,comment_count FROM apps ' .
            ' WHERE ' . implode(' AND ', $wheres2) .
            ' ORDER BY mtime DESC LIMIT ? OFFSET ?';
        $list2 = db_get($sql2, array_merge($where_params, [MAX_APP_GUEST, $offset]));
    }
    elseif ($mode === 'ranking') { // ランキング表示モード
        $wheres[] = 'fav >= 3';
        $sql =
            'SELECT app_id,title,author,memo,mtime,fav,view,user_id,tag,nakotype,bad,image_id,comment_count FROM apps ' .
            ' WHERE ' . implode(' AND ', $wheres) .
            ' ORDER BY fav DESC, app_id DESC LIMIT ?';
        $statements = array_merge($where_params, [MAX_APP_RECENT]);
        $list = db_get($sql, $statements);
        $list2 = [];
    }
    // --------------------------------------------------------
    // next url link
    $next_url = n3s_getURL('all', 'list', [
        'offset' => ($offset + MAX_APP_RECENT),
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
    $top_materials = [];
    if ($mode === 'list' && $find_user_id === 0 && $onlybad === 0 && $nofilter === 0 && $offset == 0) {
        // 人気の素材一覧 (image.php の閲覧数ランキング, scripts/image_count.php が集計)
        $top_materials = n3s_list_get_popular_materials(MAX_LIST_MATERIALS);

        // 全期間を取得
        $ranking_all = db_get('SELECT * FROM apps '.
            'WHERE (bad < 2) AND (fav >= 3) AND (is_private = 0) AND (show_list = 1)'.
            'ORDER BY fav DESC LIMIT 30', []);

        // Nヶ月以内に更新されたアプリを取得
        // mtimeはいいねでは更新されないため、fav蓄積ペースに対して閾値・期間が厳しすぎると
        // 対象がほぼ枯渇する(#今回の不具合)。閾値と期間を緩めて母数を確保する。
        $mon = 12;
        $mtime = time() - (60 * 60 * 24 * 30 * $mon);
        $ranking = db_get('SELECT * FROM apps '.
            'WHERE (mtime > ?) AND (bad < 2) AND (fav >= 1) AND (is_private = 0) AND (show_list = 1)'.
            'ORDER BY fav DESC LIMIT 40', [$mtime]);
        // ランキング情報を得る
        $ranking_total = $ranking_all + $ranking;

        // 常に異なる作品が表示されるようにシャッフルして新鮮味を出す
        // 上位N件を取る
        shuffle($ranking);
        $ranking = array_splice($ranking, 0, MAX_APP_RANKING);
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
        $ranking_all = array_splice($ranking_all, 0, MAX_APP_RANKING);

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
    n3s_list_setCoverURL($list);
    n3s_list_setCoverURL($list2);
    n3s_list_setCoverURL($ranking);
    n3s_list_setCoverURL($ranking_all);
    n3s_list_setUserProfileURL($list);
    n3s_list_setUserProfileURL($list2);
    n3s_list_setUserProfileURL($ranking);
    n3s_list_setUserProfileURL($ranking_all);
    n3s_list_setTagLink($list);
    n3s_list_setTagLink($list2);
    n3s_list_setTagLink($ranking);
    n3s_list_setTagLink($ranking_all);
    n3s_list_setCardHTML($list);
    n3s_list_setCardHTML($list2);
    n3s_list_setCardHTML($ranking);
    n3s_list_setCardHTML($ranking_all);
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
        "top_materials" => $top_materials,
    ];
}

/**
 * 人気の素材一覧を取得する (images.view 降順)。
 * view はアクセスログの集計バッチ scripts/image_count.php が更新する。
 * 自分専用(SELF)や通報された素材は除外し、表示用のプレビューURL・詳細URLを付与する。
 */
function n3s_list_get_popular_materials($limit)
{
    $limit = intval($limit);
    if ($limit <= 0) {
        return [];
    }
    $rows = db_get(
        'SELECT image_id, title, filename, copyright, view FROM images ' .
            "WHERE view > 0 AND copyright != 'SELF' AND bad = 0 " .
            'ORDER BY view DESC, image_id DESC LIMIT ?',
        [$limit]
    );
    if (!$rows) {
        return [];
    }
    foreach ($rows as &$row) {
        $filename = isset($row['filename']) ? $row['filename'] : '';
        $extension = strtoupper(pathinfo($filename, PATHINFO_EXTENSION));
        $row['extension'] = ($extension !== '') ? $extension : 'FILE';
        $row['is_image'] = preg_match('/\.(jpe?g|png|gif|webp)$/i', $filename) === 1;
        $row['preview_url'] = $row['is_image'] ? n3s_cover_url_from_image_row($row) : '';
        $title = isset($row['title']) ? trim($row['title']) : '';
        $row['display_title'] = ($title !== '') ? $title : $filename;
        $row['info_url'] = n3s_getURL('', 'upload', [
            'mode' => 'show',
            'image_id' => intval($row['image_id']),
        ]);
    }
    unset($row);
    return $rows;
}

function n3s_list_setUserProfileURL(&$list)
{
    $user_ids = [];
    foreach ($list as $row) {
        $user_id = intval(isset($row['user_id']) ? $row['user_id'] : 0);
        if ($user_id > 0) {
            $user_ids[$user_id] = true;
        }
    }
    $users = [];
    if ($user_ids) {
        $ids = array_keys($user_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_get(
            "SELECT user_id,image_id,profile_url FROM users WHERE user_id IN ($placeholders)",
            $ids,
            'users'
        );
        foreach ($rows as $row) {
            $users[intval($row['user_id'])] = $row;
        }
    }
    foreach ($list as &$row) {
        $user_id = intval(isset($row['user_id']) ? $row['user_id'] : 0);
        $row['profile_url'] = isset($users[$user_id])
            ? n3s_get_user_image_url($users[$user_id])
            : n3s_get_user_default_image_url();
    }
}

function n3s_list_setCardHTML(&$list)
{
    foreach ($list as &$r) {
        if (empty($r['title'])) {
            $r['card_html'] = '';
            continue;
        }
        $app_id = intval($r['app_id']);
        $title = t_check_mudai($r['title']);
        $author = t_check_nanasi($r['author']);
        $memo = t_trim100($r['memo']);
        $date = t_date2($r['mtime']);
        $cover_url = htmlspecialchars($r['cover_url'], ENT_QUOTES);
        $icon = htmlspecialchars($r['icon'], ENT_QUOTES);
        $profile_url = htmlspecialchars($r['profile_url'], ENT_QUOTES);
        $fav = intval($r['fav']);
        $bad = intval($r['bad']);
        $user_id = intval($r['user_id']);
        $tag_html = (!empty($r['tag'])) ? $r['tag_link'] : '';
        if ($user_id > 0) {
            $author_html = "<a class=\"n3s-app-author\" href=\"index.php?user_id={$user_id}&action=list\">{$author} 作</a>";
        } else {
            $author_html = "<span class=\"n3s-app-author\">{$author} 作</span>";
        }
        $fav_html = ($fav > 0) ? "<span class=\"n3s-app-fav\">⭐ {$fav}</span>" : '';
        $bad_html = ($bad > 0) ? "<span class=\"n3s-app-bad\">⛔{$bad}</span>" : '';
        $tag_html = ($tag_html !== '') ? "<span class=\"n3s-app-tags\">{$tag_html}</span>" : '';
        $view = isset($r['view']) ? intval($r['view']) : 0;
        $comment_count = isset($r['comment_count']) ? intval($r['comment_count']) : 0;
        $info_html = "<span class=\"n3s-app-info-meta\" style=\"position: absolute; right: 14px; bottom: 14px; font-size: 0.82em; color: #76676c;\">id:{$app_id} 👀{$view} 💬{$comment_count}</span>";
        $r['card_html'] = <<<HTML
<article class="n3s-app-card" style="position: relative;">
  <a class="n3s-app-card-cover" href="id.php?{$app_id}">
    <img src="{$cover_url}" alt="{$title}">
  </a>
  <div class="n3s-app-card-body">
    <div class="n3s-app-card-head">
      <img class="n3s-app-icon" src="{$icon}" alt="">
      <img class="n3s-user-image" src="{$profile_url}" width="32" height="32" alt="">
      <div class="n3s-app-meta">
        {$author_html}
        <br>
        <span class="n3s-app-date">{$date}</span>
      </div>
    </div>
    <h2 class="n3s-app-title"><a href="id.php?{$app_id}">{$title}</a></h2>
    <p class="n3s-app-memo">{$memo}</p>
    <div class="n3s-app-foot" style="padding-right: 155px;">
      {$fav_html}
      {$bad_html}
      {$tag_html}
    </div>
    {$info_html}
  </div>
</article>
HTML;
    }
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
            "cover_url" => $row['cover_url'],
            "top_users" => [],
        );
    }
    n3s_api_output(true, array(
        "list" => $res
    ));
}
