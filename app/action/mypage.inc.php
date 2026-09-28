<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');
define('MAX_MYPAGE_FAV_DEF', 5);
define('MAX_MYPAGE_APP', 20);
define('MAX_MYPAGE_MATERIALS', 20);
define('MAX_MYPAGE_COMMENTS', 10);

// no api login
function n3s_api_mypage()
{
    n3s_api_output('ng', ['msg' => 'should use web access']);
}

function n3s_web_mypage()
{
    // ログインが必須
    $login_url = n3s_getURL('my', 'login');
    $logout_url = n3s_getURL('my', 'logout');
    $back = isset($_GET['back']) ? $_GET['back'] : '';
    if ($back == 'list') {
        n3s_setBackURL(n3s_getURL('all', 'list'));
    }
    if (!n3s_is_login()) {
        header('location:' . $login_url);
        exit;
    }
    // ログインが完了してこのページが表示されたところ？
    // そうならばセッションのn3s_on_after_loginをチェック
    if (!empty($_SESSION['n3s_on_after_login'])) {
        $url = $_SESSION['n3s_on_after_login'];
        unset($_SESSION['n3s_on_after_login']);
        header('location:' . $url);
        exit;
    }
    // ユーザー情報はDBから取得し、プロフィール画像の変更を即時反映する
    $login_user = n3s_get_login_info();
    $user_id = $login_user['user_id'];
    $user = n3s_getUserInfo($user_id);
    if (!$user) {
        n3s_error('ユーザー情報を取得できません', 'もう一度ログインしてください。');
        return;
    }
    // リンクページ
    $page = empty($_GET['page']) ? 0 : intval($_GET['page']);
    $offset = MAX_MYPAGE_APP * $page;
    $link_next_page = n3s_getURL($page + 1, 'mypage');
    $link_next_material_page = n3s_getURL($page + 1, 'mypage', ['mode' => 'material']);
    $link_all_fav = n3s_getURL('all', 'mypage', ['fav' => 'all']);
    $link_mypage = n3s_getURL('all', 'mypage', []);
    $link_materil = n3s_getURL('all', 'mypage', ['mode' => 'material']);
    $link_userinfo = n3s_getURL($user_id, 'userinfo', []);
    $link_del_account = n3s_getURL($user_id, 'mypage', ['mode' => 'del_account']);
    // -----------------------------------
    // 表示モードの確認
    // -----------------------------------
    $mode = empty($_GET['mode']) ? 'mypage' : $_GET['mode'];
    // 素材ページ
    if ($mode == 'material') {
        // 素材一覧
        // ranking, mtime, search の3択
        $sort = 'ranking';
        if (isset($_GET['sort'])) {
            if ($_GET['sort'] === 'mtime') {
                $sort = 'mtime';
            } elseif ($_GET['sort'] === 'search') {
                $sort = 'search';
            }
        }

        // 検索語の取得
        $search_word = isset($_GET['search_word']) ? trim($_GET['search_word']) : '';
        $has_search = ($sort === 'search' && $search_word !== '');

        global $n3s_config;
        $n3s_config['search_word'] = $search_word;
        $n3s_config['has_search'] = $has_search;

        $params = [$user_id];
        $where = 'WHERE user_id=?';

        if ($has_search) {
            // %, _, \ のエスケープ
            $escaped_word = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search_word);
            $search_like = "%{$escaped_word}%";
            $where .= ' AND (title LIKE ? ESCAPE \'\\\' OR description LIKE ? ESCAPE \'\\\')';
            $params[] = $search_like;
            $params[] = $search_like;
        }

        if ($sort === 'mtime' || $sort === 'search') {
            $order_by = 'image_id DESC';
        } else {
            $order_by = 'view DESC, image_id DESC';
        }

        $material_offset = MAX_MYPAGE_MATERIALS * $page;
        $sql = 'SELECT * FROM images ' . $where . ' ORDER BY ' . $order_by . ' LIMIT ? OFFSET ?';
        $params[] = MAX_MYPAGE_MATERIALS;
        $params[] = $material_offset;

        $images = db_get($sql, $params);

        foreach ($images as &$image) {
            $filename = isset($image['filename']) ? $image['filename'] : '';
            $extension = strtoupper(pathinfo($filename, PATHINFO_EXTENSION));
            $image['extension'] = ($extension !== '') ? $extension : 'FILE';
            $image['is_image'] = preg_match('/\.(jpe?g|png|gif|webp)$/i', $filename) === 1;
            $image['preview_url'] = $image['is_image'] ? n3s_cover_url_from_image_row($image) : '';
            $image['info_url'] = n3s_getURL('', 'upload', [
                'mode' => 'show',
                'image_id' => intval($image['image_id']),
            ]);
        }
        unset($image);

        $link_params = ['mode' => 'material', 'sort' => $sort];
        if ($has_search) {
            $link_params['search_word'] = $search_word;
        }

        $link_next_material_page = n3s_getURL($page + 1, 'mypage', $link_params);
        if (count($images) < MAX_MYPAGE_MATERIALS) {
            $link_next_material_page = '';
        }
        $link_prev_material_page = ($page > 0)
            ? n3s_getURL($page - 1, 'mypage', $link_params)
            : '';

        n3s_template_fw('mymaterial.html', [
            'user_id' => $user_id,
            'name' => $user['name'],
            'logout_url' => $logout_url,
            'user' => $user,
            'images' => $images,
            'url_images' => n3s_get_config('url_images', '/images'),
            'link_all_fav' => $link_all_fav,
            'link_next_page' => $link_next_material_page,
            'link_prev_page' => $link_prev_material_page,
            'link_mypage' => $link_mypage,
            'link_material' => $link_materil,
            'link_userinfo' => $link_userinfo,
            'page' => $page,
            'sort' => $sort,
            'search_word' => $search_word,
            'has_search' => $has_search,
            'link_sort_ranking' => n3s_getURL('all', 'mypage', ['mode' => 'material', 'sort' => 'ranking']),
            'link_sort_mtime' => n3s_getURL('all', 'mypage', ['mode' => 'material', 'sort' => 'mtime']),
            'link_sort_search' => n3s_getURL('all', 'mypage', ['mode' => 'material', 'sort' => 'search']),
            'link_clear_search' => n3s_getURL('all', 'mypage', ['mode' => 'material', 'sort' => 'search']),
        ]);
        return;
    }
    if ($mode == 'del_account') {
        // アカウント削除
        n3s_mypage_mode_del_account($user_id);
        return;
    }

    // 作品一覧を取得
    $apps = db_get('SELECT * FROM apps WHERE user_id=? ORDER BY app_id DESC LIMIT ? OFFSET ?', [$user_id, MAX_MYPAGE_APP, $offset]);
    if (count($apps) < MAX_MYPAGE_APP) {
        $link_next_page = '';
    }
    $link_prev_page = ($page > 0) ? n3s_getURL($page - 1, 'mypage') : '';
    // お気に入り一覧を取得
    $fav_limit = empty($_GET['fav']) ? MAX_MYPAGE_FAV_DEF : (($_GET['fav'] == 'all') ? 1000 : MAX_MYPAGE_FAV_DEF);
    $bookmark_ids = db_get(
        'SELECT * FROM bookmarks WHERE user_id=? ' .
            'ORDER BY bookmark_id DESC LIMIT ?',
        [$user_id, $fav_limit]
    );
    $bookmarks = [];
    if ($bookmark_ids) {
        foreach ($bookmark_ids as $aid) {
            $a = db_get1(
                'SELECT app_id, title, author FROM apps ' .
                    'WHERE app_id=?',
                [intval($aid['app_id'])]
            );
            // 作品が削除済みの場合は取得できないのでスキップする
            if (!$a) { continue; }
            $bookmarks[] = $a;
        }
    }
    // 最近コメントが付いた投稿一覧を取得(自分の投稿への他人からのコメントのみ)
    $recent_comments = [];
    if ($page == 0) {
        $rows = db_get(
            'SELECT c.comment_id, c.app_id, c.name, c.body, c.ctime, a.title AS app_title ' .
                'FROM comments c JOIN apps a ON c.app_id = a.app_id ' .
                "WHERE a.user_id=? AND c.status='approved' AND c.user_id!=? " .
                'ORDER BY c.ctime DESC LIMIT ?',
            [$user_id, $user_id, MAX_MYPAGE_COMMENTS]
        );
        foreach ($rows as $r) {
            $r['link'] = n3s_getURL($r['app_id'], 'show') . '#comment_section';
            $recent_comments[] = $r;
        }
    }
    // ユーザー情報
    n3s_template_fw('mypage.html', [
        'user_id' => $user_id,
        'name' => $user['name'],
        'apps' => $apps,
        'logout_url' => $logout_url,
        'user' => $user,
        'url_images' => n3s_get_config('url_images', '/images'),
        'bookmarks' => $bookmarks,
        'recent_comments' => $recent_comments,
        'link_all_fav' => $link_all_fav,
        'link_next_page' => $link_next_page,
        'link_prev_page' => $link_prev_page,
        'link_mypage' => $link_mypage,
        'link_userinfo' => $link_userinfo,
        'link_del_account' => $link_del_account,
        'page' => $page,
        // 'page' は n3s_template_fw() 内で $n3s_config['page'](ルーティング用の値)に
        // 上書きされてしまうため、テンプレートの条件分岐には専用のキーを使う。
        'is_mypage_top' => ($page == 0),
        'dashboard' => ($page == 0) ? n3s_mypage_get_dashboard_data($user_id) : [],
        'link_material' => $link_materil,
        'link_logout' => $logout_url,
    ]);
}

// アカウント削除
function n3s_mypage_mode_del_account($user_id)
{
    $confirm = empty($_POST['confirm']) ? '' : $_POST['confirm'];
    $token = n3s_getEditToken("del_account__$user_id", FALSE);
    $token_post = empty($_POST['token']) ? '' : $_POST['token'];
    $link_del_account = n3s_getURL(
        $user_id,
        'mypage',
        [
            'mode' => 'del_account',
        ]
    );
    $link_mypage = n3s_getURL($user_id, 'mypage');
    // check user_id
    $user = n3s_getUserInfo($user_id);
    if (!$user) {
        n3s_error('ユーザーIDがありません', '', TRUE);
        return;
    }
    $user_name = $user['name'];
    // 添付ファイルの列挙
    $files = db_get('SELECT * FROM images WHERE user_id=?', [$user_id]);
    $files_html = "<h3>削除対象のファイル</h3>\n<ul>\n";
    $files_full = [];
    foreach ($files as $file) {
        $image_id = $file['image_id'];
        $imageDir = n3s_getImageDir($image_id);
        $filename = $file['filename'];
        $fullpath = "$imageDir/$filename";
        $title = htmlspecialchars($file['title'], ENT_QUOTES);
        $files_full[] = [
            "image_id" => $image_id,
            "filename" => $fullpath,
        ];
        $url = n3s_getURL($user_id, 'upload', ['mode' => 'show', 'image_id' => $image_id]);
        $files_html .= "<li><a href='$url'>$title - $filename</li>\n";
    }
    if (count($files) == 0) {
        $files_html .= "<li>なし</li>\n";
    }
    $files_html .= "</ul>\n";
    // 作品一覧
    $apps = db_get('SELECT * FROM apps WHERE user_id=?', [$user_id]);
    $apps_html = "<h3>削除対象の作品</h3>\n<ul>\n";
    $apps_full = [];
    foreach ($apps as $app) {
        $app_id = $app['app_id'];
        $material_id = $app['material_id'];
        $title = htmlspecialchars($app['title'], ENT_QUOTES);
        $url = n3s_getURL($app_id, 'show');
        $apps_html .= "<li><a href='$url'>($app_id) $title</a></li>\n";
        $apps_full[] = [
            "app_id" => $app_id,
            "material_id" => $material_id,
            "dbname" => n3s_getMaterialDB($app_id),
            "title" => $app['title'],
        ];
    }
    if (count($apps) == 0) {
        $apps_html .= "<li>なし</li>\n";
    }
    $apps_html .= "</ul>\n";
    // 退会処理
    // 確認画面 -> 実行
    $q = empty($_POST['q']) ? '' : $_POST['q'];
    if ($confirm == 'yes' && $q == 'たいかい') {
        if ($token != $token_post) {
            n3s_error(
                '退会トークンのエラー',
                "<a href='$link_del_account'>こちらから再度お試しください。($token_post)</a>",
                TRUE
            );
            return;
        }
        // === 退会処理 ===
        // ログに追加
        n3s_log("+ ($user_id)『{$user_name}』が退会(n3s_mypage_mode_del_account)", '退会', 1);
        // 添付ファイルの削除
        foreach ($files_full as $file) {
            $image_id = $file['image_id'];
            $filename = $file['filename'];
            if (file_exists($filename)) {
                @unlink($filename);
            }
            $title = '(ユーザー退会のため削除されました)';
            db_exec('UPDATE images SET title=?, filename="53.png" WHERE image_id=?', [$title, $image_id]);
            n3s_log("- 素材($image_id) $filename を削除", '退会');
        }
        // 作品情報の削除
        foreach ($apps_full as $app) {
            $app_id = $app['app_id'];
            $material_id = $app['material_id'];
            $app_title = $app['title'];
            if ($material_id == 0) {
                $material_id = $app_id;
            }
            $date = date('Y-m-d H:i:s');
            $body =
                "# この作品はユーザー退会により削除されました。\n" .
                "# 退会ID={$user_id}\n" .
                "# 退会日時={$date}\n" .
                "「申し訳ありません。この作品は削除されました。」と表示。\n";
            $dbname = n3s_getMaterialDB($material_id);
            $ok = db_exec('UPDATE materials SET body=? WHERE material_id=?', [$body, $material_id], $dbname);
            if (!$ok) {
                n3s_error('作品情報の削除に失敗しました', '', TRUE);
                return;
            }
            n3s_log("- 作品削除($app_id)『{$app_title}』", "退会");
        }
        // お気に入り情報の削除
        db_exec('DELETE FROM bookmarks WHERE user_id=?', [$user_id]);
        // ユーザーの変更
        $pw = generatePassword();
        $email = generatePassword();
        $token = generatePassword();
        db_exec(
            'UPDATE users SET name="(退会ユーザー)", email=?, password=?, login_token=? WHERE user_id=?',
            [
                $email,
                $pw,
                $token,
                $user_id
            ],
            'users'
        );
        // 作品インデックスを変更
        $title = '(削除されました)';
        $memo = 'この作品はユーザー退会により削除されました。';
        $author = '(退会ユーザー)';
        $tag = 'w_noname'; // widgetでタイトルを隠す(一覧の非掲載は show_list=0 で行う #202)
        db_exec(
            'UPDATE apps SET title=?, author=?, memo=?, email=?, tag=?, show_list=0 WHERE user_id=?',
            [$title, $author, $memo, $email, $tag, $user_id]
        );
        // ログアウト
        n3s_logout();
        n3s_info('退会しました', 'またのご利用をお待ちしております。', TRUE);
        exit;
    }
    // 確認画面
    $title = '貯蔵庫から退会（アカウントを削除）しますか？';
    $msg = <<< __EOS__
<div class="mypage_box">
    <p>
        <span style="background-color: yellow; font-weight: bold;">
        貯蔵庫から退会すると、ユーザー情報が全て削除されます。</span>
    </p>
    <p>
        本当に退会する場合は、以下にチェックを入れて、質問に答えてください。
        退会すると、作品や素材などの情報はすべて失われます。慎重に考慮してください。
    </p>
    <form method="post" action="{$link_del_account}">
        <div style="margin-left: 1em;">
            <input id="confirm" type="checkbox" name="confirm" value="yes">
            <label style="color: black; font-size: 1em;" for="confirm">アカウントを削除する</label>
            <br><br>
            質問: 以下にひらがなで「退会」と記入してください：<br>
            <input id="q" type="text" name="q" value="" placeholder="質問の答えを入力">
            <input type="hidden" name="token" value="{$token}">
        </div>
        <p><input type="submit" value="退会する"></p>
    </form>
</div>
<div class="mypage_box">
    <a href="{$link_mypage}">→退会しない</a>
</div>
<div class="mypage_box">
{$apps_html}
</div>
<div class="mypage_box">
{$files_html}
</div>
__EOS__;
    n3s_info($title, $msg, TRUE);
}

function generatePassword($length = 16)
{
    return substr(bin2hex(random_bytes($length)), 0, $length);
}

/**
 * マイページのダッシュボード用データを集計する
 *
 * @param int $user_id
 * @return array
 */
function n3s_mypage_get_dashboard_data($user_id)
{
    $empty_result = [
        'total_views' => 0,
        'monthly_views' => 0,
        'total_favs' => 0,
        'total_apps' => 0,
        'public_apps' => 0,
        'top_all_time' => [],
        'top_surging' => [],
        'chart_labels' => '[]',
        'chart_datasets' => '[]',
        'has_chart_data' => false,
    ];

    // ユーザーの全作品を取得 (app_id, title, view, fav, is_private, ctime, mtime)
    $apps = db_get(
        'SELECT app_id, title, view, fav, is_private, ctime, mtime FROM apps WHERE user_id=? ORDER BY app_id DESC',
        [$user_id]
    );
    if (!$apps) {
        return $empty_result;
    }

    $total_apps = count($apps);
    $public_apps = 0;
    $total_views = 0;
    $total_favs = 0;
    $app_map = [];
    $app_ids = [];

    foreach ($apps as &$a) {
        $a['app_id'] = intval($a['app_id']);
        $a['view'] = intval($a['view']);
        $a['fav'] = intval($a['fav']);
        $a['is_private'] = intval($a['is_private']);
        $aid = $a['app_id'];
        $app_ids[] = $aid;
        $app_map[$aid] = $a;
        $total_views += $a['view'];
        $total_favs += $a['fav'];
        if ($a['is_private'] === 0) {
            $public_apps++;
        }
    }
    unset($a);

    if (empty($app_ids)) {
        return $empty_result;
    }

    // 1. これまでの人気作品ベスト5 (累計アクセス数順)
    $top_all_time = $apps;
    usort($top_all_time, function ($a, $b) {
        if ($b['view'] !== $a['view']) {
            return $b['view'] <=> $a['view'];
        }
        if ($b['fav'] !== $a['fav']) {
            return $b['fav'] <=> $a['fav'];
        }
        return $b['app_id'] <=> $a['app_id'];
    });
    $top_all_time = array_slice($top_all_time, 0, 5);
    foreach ($top_all_time as $i => &$item) {
        $item['rank'] = $i + 1;
        $item['rank_index'] = $i;
    }
    unset($item);

    // 2. ログDBからのアクセス集計 (500件ずつ分割クエリ)
    $chunks = array_chunk($app_ids, 500);

    // 今月のアクセス数 (access_stats_monthly より)
    $current_month = date('Y-m');
    $monthly_views = 0;
    foreach ($chunks as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $params = array_merge([$current_month], $chunk);
        $res = db_get1(
            "SELECT SUM(count) AS m_count FROM access_stats_monthly
             WHERE month=? AND app_id IN ($in) AND kind IN ('show', 'widget')",
            $params,
            'log'
        );
        if ($res && !empty($res['m_count'])) {
            $monthly_views += intval($res['m_count']);
        }
    }

    // 直近30日間の日付リスト (29日前〜今日までの30日間)
    $days = 30;
    $date_labels = [];
    $date_short_labels = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $date_labels[] = $d;
        $date_short_labels[] = date('n/j', strtotime($d));
    }
    $since = $date_labels[0];

    // 急上昇: 直近30日の作品別アクセス数
    $period_app_views = [];
    foreach ($chunks as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $params = array_merge([$since], $chunk);
        $rows = db_get(
            "SELECT app_id, SUM(count) AS period_views FROM access_stats
             WHERE date >= ? AND app_id IN ($in) AND kind IN ('show', 'widget')
             GROUP BY app_id",
            $params,
            'log'
        );
        if ($rows) {
            foreach ($rows as $r) {
                $aid = intval($r['app_id']);
                $cnt = intval($r['period_views']);
                if ($cnt > 0) {
                    $period_app_views[$aid] = ($period_app_views[$aid] ?? 0) + $cnt;
                }
            }
        }
    }

    // 急上昇ベスト5
    arsort($period_app_views);
    $top_surging = [];
    $surging_count = 0;
    foreach ($period_app_views as $aid => $p_views) {
        if (isset($app_map[$aid])) {
            $top_surging[] = array_merge($app_map[$aid], [
                'period_views' => $p_views,
                'rank' => $surging_count + 1,
                'rank_index' => $surging_count,
            ]);
            $surging_count++;
            if ($surging_count >= 5) {
                break;
            }
        }
    }

    // 全作品の日別合計
    $daily_total_map = [];
    foreach ($chunks as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $params = array_merge([$since], $chunk);
        $rows = db_get(
            "SELECT date, SUM(count) AS total_count FROM access_stats
             WHERE date >= ? AND app_id IN ($in) AND kind IN ('show', 'widget')
             GROUP BY date",
            $params,
            'log'
        );
        if ($rows) {
            foreach ($rows as $r) {
                $d = $r['date'];
                $daily_total_map[$d] = ($daily_total_map[$d] ?? 0) + intval($r['total_count']);
            }
        }
    }

    // 個別作品の系列用作品選定 (急上昇または累計人気の上位3作品)
    $chart_app_ids = [];
    foreach ($top_surging as $s) {
        if (!in_array($s['app_id'], $chart_app_ids, true) && count($chart_app_ids) < 3) {
            $chart_app_ids[] = $s['app_id'];
        }
    }
    foreach ($top_all_time as $t) {
        if (!in_array($t['app_id'], $chart_app_ids, true) && count($chart_app_ids) < 3) {
            $chart_app_ids[] = $t['app_id'];
        }
    }

    $daily_app_map = [];
    if (!empty($chart_app_ids)) {
        $in = implode(',', array_fill(0, count($chart_app_ids), '?'));
        $params = array_merge([$since], $chart_app_ids);
        $rows = db_get(
            "SELECT date, app_id, SUM(count) AS count FROM access_stats
             WHERE date >= ? AND app_id IN ($in) AND kind IN ('show', 'widget')
             GROUP BY date, app_id",
            $params,
            'log'
        );
        if ($rows) {
            foreach ($rows as $r) {
                $daily_app_map[$r['app_id']][$r['date']] = intval($r['count']);
            }
        }
    }

    $total_counts = [];
    $has_any_access = false;
    foreach ($date_labels as $d) {
        $cnt = $daily_total_map[$d] ?? 0;
        $total_counts[] = $cnt;
        if ($cnt > 0) {
            $has_any_access = true;
        }
    }

    $app_colors = [
        ['border' => '#0984e3', 'bg' => 'rgba(9, 132, 227, 0.08)'],
        ['border' => '#00b894', 'bg' => 'rgba(0, 184, 148, 0.08)'],
        ['border' => '#f39c12', 'bg' => 'rgba(243, 156, 18, 0.08)'],
    ];

    $datasets = [];
    $datasets[] = [
        'label' => '全作品合計',
        'data' => $total_counts,
        'borderColor' => '#d63031',
        'backgroundColor' => 'rgba(214, 48, 49, 0.12)',
        'fill' => true,
        'tension' => 0.25,
        'borderWidth' => 2.5,
        'pointRadius' => 2,
    ];

    if ($total_apps > 1) {
        foreach ($chart_app_ids as $idx => $aid) {
            $color = $app_colors[$idx % count($app_colors)];
            $app_title = isset($app_map[$aid]) ? mb_strimwidth($app_map[$aid]['title'], 0, 20, '…') : "#{$aid}";
            $app_data = [];
            foreach ($date_labels as $d) {
                $app_data[] = $daily_app_map[$aid][$d] ?? 0;
            }
            $datasets[] = [
                'label' => "#{$aid} {$app_title}",
                'data' => $app_data,
                'borderColor' => $color['border'],
                'backgroundColor' => $color['bg'],
                'fill' => false,
                'tension' => 0.25,
                'borderWidth' => 1.8,
                'pointRadius' => 2,
            ];
        }
    }

    return [
        'total_views' => $total_views,
        'monthly_views' => $monthly_views,
        'total_favs' => $total_favs,
        'total_apps' => $total_apps,
        'public_apps' => $public_apps,
        'top_all_time' => $top_all_time,
        'top_surging' => $top_surging,
        'chart_labels' => json_encode($date_short_labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'chart_datasets' => json_encode($datasets, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'has_chart_data' => $has_any_access,
    ];
}

