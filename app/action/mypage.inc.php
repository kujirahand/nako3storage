<?php

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
    if (!n3s_is_login()) {
        header('location:'.$login_url);
        exit;
    }
    $user = n3s_get_login_info();
    $user_id = $user['user_id'];
    // 作品一覧を取得
    $apps = db_get('SELECT * FROM apps WHERE user_id=? ORDER BY app_id DESC', [$user_id]);
    // ユーザー情報
    n3s_template_fw('mypage.html', [
        'user_id' => $user_id,
        'name' => $user['name'],
        'apps' => $apps,
        'logout_url' => $logout_url,
    ]);
}

