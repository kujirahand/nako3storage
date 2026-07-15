<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/mypage.inc.php';

test('マイページは新しいレイアウトと既定プロフィール画像で描画される', function () {
    n3s_add_user('mypage@example.com', 'password1', 'マイページ太郎');
    expect(n3s_login('mypage@example.com', 'password1'))->toBeTrue();

    $_GET['page'] = '0';
    $out = n3s_test_capture(fn() => n3s_web_mypage());

    expect($out)
        ->toContain('class="n3s-mypage-page"')
        ->toContain('class="n3s-mypage-profile"')
        ->toContain('id="apps"')
        ->toContain('アカウントと管理')
        ->toContain('https://n3s.nadesi.com/image.php?f=726.png');
});

test('素材ページは画像のプレビューと非画像の拡張子を表示する', function () {
    n3s_add_user('material@example.com', 'password1', '素材太郎');
    expect(n3s_login('material@example.com', 'password1'))->toBeTrue();
    $user = n3s_get_login_info();
    $now = time();

    db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,token,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['サンプル画像', '101.png', $user['user_id'], 'SELF', 'preview-token', $now, $now]
    );
    db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,token,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['説明書', '102.pdf', $user['user_id'], 'CC0', '', $now, $now]
    );

    $_GET['mode'] = 'material';
    $_GET['page'] = '0';
    $out = n3s_test_capture(fn() => n3s_web_mypage());

    expect($out)
        ->toContain('class="n3s-mypage-page n3s-material-page"')
        ->toContain('class="n3s-material-preview"')
        ->toContain('/image.php?t=preview-token&amp;f=101.png')
        ->toContain('<span>PDF</span>')
        ->toContain('サンプル画像')
        ->toContain('説明書');
});

test('マイページのプロフィール画像は500px版を表示する', function () {
    n3s_add_user('large-profile@example.com', 'password1', '画像太郎');
    expect(n3s_login('large-profile@example.com', 'password1'))->toBeTrue();
    $user = n3s_get_login_info();
    $now = time();
    $image_id = db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,token,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['プロフィール画像', '201.jpg', $user['user_id'], 'SELF', 'large-token', $now, $now]
    );
    db_exec('UPDATE users SET image_id=? WHERE user_id=?', [$image_id, $user['user_id']], 'users');

    $_GET['page'] = '0';
    $out = n3s_test_capture(fn() => n3s_web_mypage());

    expect($out)
        ->toContain('/image.php?f=201.jpg&amp;t=large-token')
        ->not->toContain('/image.php?f=201.jpg&amp;s=32');
});
