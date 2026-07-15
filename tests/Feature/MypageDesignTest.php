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

test('マイページの素材検索機能：タイトル・解説で検索可能、自分の画像のみ検索、エスケープ検証', function () {
    $now = time();
    // ユーザーA (自分) を作成・ログイン
    n3s_add_user('my-search@example.com', 'password1', 'マイ検索太郎');
    expect(n3s_login('my-search@example.com', 'password1'))->toBeTrue();
    $userA = n3s_get_login_info();

    // ユーザーB (他人) を作成
    $userB_id = n3s_add_user('other-search@example.com', 'password1', '他検索太郎');

    // テストデータの投入
    // 1. 自分のタイトル一致画像
    $id_my_title = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['マイ青空画像', 'これは自分の青空です。', 'my1.png', $userA['user_id'], 'CC0', $now, $now]
    );
    // 2. 自分の解説一致画像
    $id_my_desc = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['マイ夕暮れ写真', '夕日の空が綺麗です。', 'my2.png', $userA['user_id'], 'CC0', $now, $now]
    );
    // 3. 他人のタイトル一致画像 (ヒットしてはいけない)
    $id_other_title = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['他人の青空画像', '他人の青空。', 'my3.png', $userB_id, 'CC0', $now, $now]
    );

    // テストA: 自分の「空」で検索
    $_GET = [
        'mode' => 'material',
        'search_word' => '空',
        'sort' => 'search',
        'page' => '0'
    ];
    $out = n3s_test_capture(fn() => n3s_web_mypage());

    // 自分の「マイ青空画像」と「マイ夕暮れ写真」が含まれていること
    expect($out)->toContain('マイ青空画像');
    expect($out)->toContain('マイ夕暮れ写真');
    // 他人の「他人の青空画像」が含まれていないこと
    expect($out)->not->toContain('他人の青空画像');

    // テストB: ヒットしない言葉で検索
    $_GET = [
        'mode' => 'material',
        'search_word' => '存在しないはずのキーワード',
        'sort' => 'search',
        'page' => '0'
    ];
    $out_empty = n3s_test_capture(fn() => n3s_web_mypage());
    expect($out_empty)->toContain('『存在しないはずのキーワード』に一致する素材はありません。');
});
