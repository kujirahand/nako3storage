<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/userinfo.inc.php';

test('プロフィール編集ページはマイページ共通レイアウトと500px画像で描画される', function () {
    n3s_add_user('userinfo-design@example.com', 'password1', '設定太郎');
    expect(n3s_login('userinfo-design@example.com', 'password1'))->toBeTrue();
    $user = n3s_get_login_info();
    $now = time();
    $image_id = db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,token,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['プロフィール画像', '301.jpg', $user['user_id'], 'SELF', 'userinfo-token', $now, $now]
    );
    db_exec('UPDATE users SET image_id=?, description=? WHERE user_id=?', [
        $image_id,
        'プロフィールです',
        $user['user_id'],
    ], 'users');

    $_GET['page'] = (string)$user['user_id'];
    $out = n3s_test_capture(fn() => n3s_web_userinfo());

    expect($out)
        ->toContain('class="n3s-mypage-page n3s-userinfo-page"')
        ->toContain('n3s-userinfo-form')
        ->toContain('プロフィール設定')
        ->toContain('/image.php?f=301.jpg&amp;t=userinfo-token')
        ->not->toContain('/image.php?f=301.jpg&amp;s=32');
});
