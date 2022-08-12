<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');
define('MAX_MYPAGE_FAV_DEF', 5);
define('MAX_MYPAGE_APP', 20);
define('MAX_MYPAGE_MATERIALS', 20);

// no api login
function n3s_api_mypage()
{
    n3s_api_output($ng, ['msg'=>'should use web access']);
}

function n3s_web_mypage()
{
    // ログインが必須
    $login_url = n3s_getURL('my', 'login');
    $logout_url = n3s_getURL('my', 'logout');
    $back = isset($_GET['back']) ? $_GET['back']: '';
    if ($back == 'list') {
        n3s_setBackURL(n3s_getURL('all', 'list'));
    }
    if (!n3s_is_login()) {
        header('location:'.$login_url);
        exit;
    }
    // ログインが完了してこのページが表示されたところ？
    // そうならばセッションのn3s_on_after_loginをチェック
    if (!empty($_SESSION['n3s_on_after_login'])) {
        $url = $_SESSION['n3s_on_after_login'];
        unset($_SESSION['n3s_on_after_login']);
        header('location:'.$url);
        exit;
    }
    // ユーザー情報を取得
    $user = n3s_get_login_info();
    $user_id = $user['user_id'];
    // リンクページ
    $page = empty($_GET['page']) ? 0 : intval($_GET['page']);
    $offset = MAX_MYPAGE_APP * $page;
    $link_next_page = n3s_getURL($page + 1, 'mypage');
    $link_next_material_page = n3s_getURL($page + 1, 'mypage', ['mode' => 'material']);
    $link_all_fav = n3s_getURL('all', 'mypage', ['fav'=>'all']);
    $link_mypage = n3s_getURL('all', 'mypage', []);
    $link_materil = n3s_getURL('all', 'mypage', ['mode' => 'material']);
    // -----------------------------------
    // 表示モードの確認
    // -----------------------------------
    $mode = empty($_GET['mode']) ? 'mypage' : $_GET['mode'];
    // 素材ページ
    if ($mode == 'material') {
        // 素材一覧
        $images = db_get(
            'SELECT * FROM images WHERE user_id=? ORDER BY image_id DESC '.
            ' LIMIT ? OFFSET ?', 
            [$user_id, MAX_MYPAGE_MATERIALS, $page]);
        n3s_template_fw('mymaterial.html', [
            'user_id' => $user_id,
            'name' => $user['name'],
            'logout_url' => $logout_url,
            'user' => $user,
            'images' => $images,
            'url_images' => n3s_get_config('url_images', '/images'),
            'link_all_fav' => $link_all_fav,
            'link_next_page' => $link_next_material_page,
            'link_mypage' => $link_mypage,
            'link_material' => $link_materil,
            'page' => $page,
        ]);
        return;
    }

    // 作品一覧を取得
    $apps = db_get('SELECT * FROM apps WHERE user_id=? ORDER BY app_id DESC LIMIT ? OFFSET ?', [$user_id, MAX_MYPAGE_APP, $offset]);
    // お気に入り一覧を取得
    $fav_limit = empty($_GET['fav']) ? MAX_MYPAGE_FAV_DEF : (($_GET['fav'] == 'all') ? 1000 : MAX_MYPAGE_FAV_DEF);
    $bookmark_ids = db_get(
        'SELECT * FROM bookmarks WHERE user_id=? '.
        'ORDER BY bookmark_id DESC LIMIT ?',
        [$user_id, $fav_limit]
    );
    $bookmarks = [];
    if ($bookmark_ids) {
        foreach ($bookmark_ids as $aid) {
            $a = db_get1(
                'SELECT app_id, title, author FROM apps '.
          'WHERE app_id=?',
                [intval($aid['app_id'])]
            );
            $bookmarks[] = $a;
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
        'link_all_fav' => $link_all_fav,
        'link_next_page' => $link_next_page,
        'page' => $page,
        'link_material' => $link_materil,
    ]);
}
