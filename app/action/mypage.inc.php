<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

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
    // 作品一覧を取得
    $apps = db_get('SELECT * FROM apps WHERE user_id=? ORDER BY app_id DESC', [$user_id]);
    $images = db_get('SELECT * FROM images WHERE user_id=? ORDER BY image_id DESC', [$user_id]);
    // お気に入り一覧を取得
    $bookmark_ids = db_get(
        'SELECT * FROM bookmarks WHERE user_id=? '.
      'ORDER BY bookmark_id DESC LIMIT 30',
        [$user_id]
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
        'images' => $images,
        'url_images' => n3s_get_config('url_images', '/images'),
        'bookmarks' => $bookmarks,
    ]);
}
