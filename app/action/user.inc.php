<?php
include_once __DIR__ . '/list.inc.php';

const MAX_USER_PAGE = 24;
const MAX_PAGE_OFFSET_USER = 10000;

// ブラウザからのアクセスがあったとき
function n3s_web_user()
{
    $r = n3s_user_get();
    if ($r === null) {
        return;
    }
    n3s_template_fw('user.html', $r);
}

// データを取得する
function n3s_user_get()
{
    global $n3s_config;

    // user_id
    $user_id = empty($_GET['user_id']) ? 0 : intval($_GET['user_id']);
    if ($user_id <= 0) {
        n3s_error('不正なページ', 'ユーザーIDが指定されていません。');
        return null;
    }

    // ユーザー情報の取得
    $user = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], 'users');
    if (!$user) {
        n3s_error('不正なページ', '指定されたユーザーが見つかりません。');
        return null;
    }

    // プロフィール情報
    $user['profile_url_large'] = n3s_get_user_image_url($user, 0);
    $screen_name = isset($user['screen_name']) ? trim($user['screen_name']) : '';
    $x_url = '';
    if ($screen_name !== '') {
        if (preg_match('/^[A-Za-z0-9_]{1,15}$/', $screen_name)) {
            $x_url = "https://x.com/{$screen_name}";
        }
    }

    // ソート順
    $sort = empty($_GET['sort']) ? 'mtime' : $_GET['sort'];
    if (!in_array($sort, ['mtime', 'view', 'fav'], true)) {
        $sort = 'mtime';
    }

    // 検索語
    $search_word = isset($_GET['search_word']) ? trim($_GET['search_word']) : '';
    $search_error = '';
    $search_wc = '';
    if ($search_word !== '') {
        if (mb_strlen($search_word) < 2) {
            $search_error = '検索語は2文字以上で指定してください。';
        } else {
            // wildcard options
            if (strpos($search_word, '*') !== false) {
                $search_wc = str_replace('*', '%', $search_word);
            } else {
                $search_wc = "%{$search_word}%";
            }
        }
    }

    // オフセット
    $offset = intval(empty($_GET['offset']) ? 0 : $_GET['offset']);
    if ($offset < 0) {
        $offset = 0;
    }
    if ($offset > MAX_PAGE_OFFSET_USER) {
        $offset = MAX_PAGE_OFFSET_USER;
    }

    // 管理者向け
    $nofilter = empty($_GET['nofilter']) ? 0 : intval($_GET['nofilter']);
    $onlybad = empty($_GET['onlybad']) ? 0 : intval($_GET['onlybad']);
    $noindex = empty($_GET['noindex']) ? 0 : intval($_GET['noindex']);
    if ($onlybad || $nofilter) {
        $noindex = 1;
    }

    // 共通のWHERE条件
    $wheres = ['user_id = ?', 'is_private = 0', 'show_list = 1'];
    $where_params = [$user_id];

    if ($nofilter < 1 && $onlybad == 0) {
        $wheres[] = 'bad == 0';
    }
    if ($onlybad >= 1) {
        $wheres[] = 'bad > 0';
    }

    // 総投稿数 (検索フィルタ前の公開作品数)
    $total_post_sql = 'SELECT count(*) FROM apps WHERE ' . implode(' AND ', $wheres);
    $total_post_res = db_get1($total_post_sql, $where_params);
    $total_post_count = $total_post_res ? intval($total_post_res['count(*)']) : 0;

    // 検索ワードでの絞り込み条件追加
    if ($search_word !== '' && $search_error === '') {
        $wheres[] = '(title LIKE ? OR memo LIKE ? OR tag LIKE ?)';
        $where_params[] = $search_wc;
        $where_params[] = $search_wc;
        $where_params[] = $search_wc;
    }

    // 絞り込み後の総件数取得 (ページネーション判定用)
    $total_sql = 'SELECT count(*) FROM apps WHERE ' . implode(' AND ', $wheres);
    $total_res = db_get1($total_sql, $where_params);
    $total_count = $total_res ? intval($total_res['count(*)']) : 0;

    // ソート
    $order_by = 'mtime DESC';
    if ($sort === 'view') {
        $order_by = 'view DESC';
    } elseif ($sort === 'fav') {
        $order_by = 'fav DESC';
    }

    // データ取得
    $sql = 'SELECT app_id, title, author, memo, mtime, fav, view, user_id, tag, nakotype, bad, image_id, comment_count FROM apps ' .
           ' WHERE ' . implode(' AND ', $wheres) .
           ' ORDER BY ' . $order_by . ', app_id DESC LIMIT ? OFFSET ?';

    $list = db_get($sql, array_merge($where_params, [MAX_USER_PAGE, $offset]));

    // アイコンやカードHTMLの設定
    n3s_list_setIcon($list);
    n3s_list_setCoverURL($list);
    n3s_list_setUserProfileURL($list);
    n3s_list_setTagLink($list);
    n3s_list_setCardHTML($list);

    // ページネーションURL of prev
    $prev_url = null;
    if ($offset > 0) {
        $prev_offset = max(0, $offset - MAX_USER_PAGE);
        $prev_url = n3s_getURL('', 'user', [
            'user_id' => $user_id,
            'sort' => $sort,
            'search_word' => $search_word,
            'offset' => $prev_offset,
            'nofilter' => $nofilter,
            'onlybad' => $onlybad,
            'noindex' => $noindex,
        ]);
    }

    // ページネーションURL of next
    $next_url = null;
    if ($offset + MAX_USER_PAGE < $total_count) {
        $next_offset = $offset + MAX_USER_PAGE;
        $next_url = n3s_getURL('', 'user', [
            'user_id' => $user_id,
            'sort' => $sort,
            'search_word' => $search_word,
            'offset' => $next_offset,
            'nofilter' => $nofilter,
            'onlybad' => $onlybad,
            'noindex' => $noindex,
        ]);
    }

    return [
        'user' => $user,
        'screen_name' => $screen_name,
        'x_url' => $x_url,
        'total_post_count' => $total_post_count,
        'list' => $list,
        'sort' => $sort,
        'offset' => $offset,
        'limit' => MAX_USER_PAGE,
        'total_count' => $total_count,
        'prev_url' => $prev_url,
        'next_url' => $next_url,
        'search_word' => $search_word,
        'search_word_url' => urlencode($search_word),
        'search_error' => $search_error,
        'nofilter' => $nofilter,
        'onlybad' => $onlybad,
        'noindex' => $noindex,
        'is_admin' => n3s_is_admin(),
        'page_title' => $user['name'] . ' さんの作品一覧',
    ];
}
