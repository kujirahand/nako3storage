<?php
include_once __DIR__ . '/list.inc.php';

// 1ページあたりの表示件数
const MAX_LIST_LOGIN = 24;
// ページネーションの上限
const MAX_PAGE_OFFSET_LOGIN = 10000;

// ブラウザからのアクセスがあったとき
function n3s_web_list_login()
{
    $r = n3s_list_login_get();
    n3s_template_fw('list_login.html', $r);
}

// データを取得する
function n3s_list_login_get()
{
    global $n3s_config;

    // パラメータ取得
    $sort = empty($_GET['sort']) ? 'mtime' : $_GET['sort'];
    if (!in_array($sort, ['mtime', 'view', 'fav', 'comment'], true)) {
        $sort = 'mtime';
    }

    $offset = intval(empty($_GET['offset']) ? 0 : $_GET['offset']);
    if ($offset < 0) {
        $offset = 0;
    }
    if ($offset > MAX_PAGE_OFFSET_LOGIN) {
        $offset = MAX_PAGE_OFFSET_LOGIN;
    }

    $nofilter = empty($_GET['nofilter']) ? 0 : intval($_GET['nofilter']);
    $onlybad = empty($_GET['onlybad']) ? 0 : intval($_GET['onlybad']);
    $noindex = empty($_GET['noindex']) ? 0 : intval($_GET['noindex']);
    if ($onlybad || $nofilter) {
        $noindex = 1;
    }

    // データベース検索条件
    $wheres = ['user_id > 0', 'is_private = 0', 'show_list = 1'];
    $where_params = [];

    if ($nofilter < 1 && $onlybad == 0) {
        $wheres[] = 'bad == 0';
    }
    if ($onlybad >= 1) {
        $wheres[] = 'bad > 0';
    }

    // 総件数取得 (ページネーション判定用)
    $total_sql = 'SELECT count(*) FROM apps WHERE ' . implode(' AND ', $wheres);
    $total_res = db_get1($total_sql, $where_params);
    $total_count = $total_res ? intval($total_res['count(*)']) : 0;

    // データ取得
    if ($sort === 'comment') {
        $wheres_alias = [];
        foreach ($wheres as $w) {
            $wheres_alias[] = 'a.' . $w;
        }
        $sql = 'SELECT a.app_id, a.title, a.author, a.memo, a.mtime, a.fav, a.view, a.user_id, a.tag, a.nakotype, a.bad, a.image_id, COUNT(c.comment_id) AS comment_count ' .
               ' FROM apps a ' .
               ' LEFT JOIN comments c ON a.app_id = c.app_id AND c.status = \'approved\' ' .
               ' WHERE ' . implode(' AND ', $wheres_alias) .
               ' GROUP BY a.app_id ' .
               ' ORDER BY comment_count DESC, a.app_id DESC LIMIT ? OFFSET ?';
    } else {
        $order_by = 'mtime DESC';
        if ($sort === 'view') {
            $order_by = 'view DESC';
        } elseif ($sort === 'fav') {
            $order_by = 'fav DESC';
        }
        $sql = 'SELECT app_id, title, author, memo, mtime, fav, view, user_id, tag, nakotype, bad, image_id, comment_count FROM apps ' .
               ' WHERE ' . implode(' AND ', $wheres) .
               ' ORDER BY ' . $order_by . ', app_id DESC LIMIT ? OFFSET ?';
    }
    
    $list = db_get($sql, array_merge($where_params, [MAX_LIST_LOGIN, $offset]));

    // アイコンやカードHTMLの設定
    n3s_list_setIcon($list);
    n3s_list_setCoverURL($list);
    n3s_list_setUserProfileURL($list);
    n3s_list_setTagLink($list);
    n3s_list_setCardHTML($list);

    // ページネーションURLの構築
    $prev_url = null;
    if ($offset > 0) {
        $prev_offset = max(0, $offset - MAX_LIST_LOGIN);
        $prev_url = n3s_getURL('all', 'list_login', [
            'sort' => $sort,
            'offset' => $prev_offset,
            'nofilter' => $nofilter,
            'onlybad' => $onlybad,
            'noindex' => $noindex,
        ]);
    }

    $next_url = null;
    if ($offset + MAX_LIST_LOGIN < $total_count) {
        $next_offset = $offset + MAX_LIST_LOGIN;
        $next_url = n3s_getURL('all', 'list_login', [
            'sort' => $sort,
            'offset' => $next_offset,
            'nofilter' => $nofilter,
            'onlybad' => $onlybad,
            'noindex' => $noindex,
        ]);
    }

    return [
        'list' => $list,
        'sort' => $sort,
        'offset' => $offset,
        'limit' => MAX_LIST_LOGIN,
        'total_count' => $total_count,
        'prev_url' => $prev_url,
        'next_url' => $next_url,
        'nofilter' => $nofilter,
        'onlybad' => $onlybad,
        'noindex' => $noindex,
        'is_admin' => n3s_is_admin(),
    ];
}
