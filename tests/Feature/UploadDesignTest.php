<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/upload.inc.php';

test('アップロード画面はマイページ共通レイアウトで描画される', function () {
    n3s_add_user('upload-design@example.com', 'password1', '素材太郎');
    expect(n3s_login('upload-design@example.com', 'password1'))->toBeTrue();

    $_GET['mode'] = '';
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)
        ->toContain('class="n3s-mypage-page n3s-upload-page"')
        ->toContain('n3s-upload-form')
        ->toContain('素材をアップロード')
        ->toContain('<legend>規約同意</legend>')
        ->toContain('<legend>ライセンス選択</legend>')
        ->toContain('name="edit_token"');
});

test('素材一覧は画像プレビューと非画像形式を安全に描画する', function () {
    $now = time();
    db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?)',
        ['公開画像', '401.png', 1, 'CC0', $now, $now]
    );
    db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?)',
        ['公開PDF', '402.pdf', 1, 'CC0', $now, $now]
    );

    $_GET['mode'] = 'list';
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)
        ->toContain('n3s-upload-list-row')
        ->toContain('/image.php?f=401.png')
        ->toContain('<span>PDF</span>')
        ->toContain('公開画像')
        ->toContain('公開PDF');
});

test('素材詳細はプレビューと管理操作を共通レイアウトで描画する', function () {
    n3s_add_user('upload-detail@example.com', 'password1', '詳細太郎');
    expect(n3s_login('upload-detail@example.com', 'password1'))->toBeTrue();
    $user = n3s_get_login_info();
    $now = time();
    $image_id = db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,token,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['詳細画像', '403.png', $user['user_id'], 'SELF', 'detail-token', $now, $now]
    );

    $_GET['mode'] = 'show';
    $_GET['image_id'] = (string)$image_id;
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)
        ->toContain('n3s-upload-detail-grid')
        ->toContain('n3s-upload-danger-zone')
        ->toContain('/image.php?t=detail-token&amp;f=403.png')
        ->toContain('素材を削除');
});
