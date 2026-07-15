<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/list_nologin.inc.php';

test('ログインなし一覧(list_nologin)で非ログイン投稿のみが表示されることを確認', function () {
    $now = time();
    // ログインユーザーの投稿
    $userId = n3s_add_user('user@example.com', 'password1', '登録ユーザー');
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?)',
        ['ログインユーザーの作品', '登録ユーザー', $userId, 0, 10, 100, 'wnako', $now, $now]
    );

    // 非ログインユーザーの投稿 A (更新時間: $now, view: 10, fav: 1)
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?)',
        ['ゲスト作品A', 'ゲストA', 0, 0, 1, 10, 'wnako', $now, $now]
    );

    // 非ログインユーザーの投稿 B (更新時間: $now - 100, view: 50, fav: 5)
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?)',
        ['ゲスト作品B', 'ゲストB', 0, 0, 5, 50, 'wnako', $now - 100, $now - 100]
    );

    // 最新順 (sort = mtime)
    $_GET = ['sort' => 'mtime'];
    $out = n3s_test_capture(fn() => n3s_web_list_nologin());
    expect($out)->toContain('ゲスト作品A');
    expect($out)->toContain('ゲスト作品B');
    expect($out)->not->toContain('ログインユーザーの作品');
    // 最新順なので、A が先に表示されるはず
    $posA = strpos($out, 'ゲスト作品A');
    $posB = strpos($out, 'ゲスト作品B');
    expect($posA)->toBeLessThan($posB);

    // アクセス順 (sort = view)
    $_GET = ['sort' => 'view'];
    $out_view = n3s_test_capture(fn() => n3s_web_list_nologin());
    // アクセス順なので B (view: 50) が A (view: 10) より先に表示されるはず
    $posA_view = strpos($out_view, 'ゲスト作品A');
    $posB_view = strpos($out_view, 'ゲスト作品B');
    expect($posB_view)->toBeLessThan($posA_view);

    // お気に入り順 (sort = fav)
    $_GET = ['sort' => 'fav'];
    $out_fav = n3s_test_capture(fn() => n3s_web_list_nologin());
    // お気に入り順なので B (fav: 5) が A (fav: 1) より先に表示されるはず
    $posA_fav = strpos($out_fav, 'ゲスト作品A');
    $posB_fav = strpos($out_fav, 'ゲスト作品B');
    expect($posB_fav)->toBeLessThan($posA_fav);

    // コメント順 (sort = comment)
    $appA = db_get1('SELECT app_id FROM apps WHERE title = ?', ['ゲスト作品A']);
    $appB = db_get1('SELECT app_id FROM apps WHERE title = ?', ['ゲスト作品B']);
    $appA_id = intval($appA['app_id']);
    $appB_id = intval($appB['app_id']);

    // ゲスト作品Aに1つ
    db_insert(
        'INSERT INTO comments (app_id, body, status, ctime) VALUES (?,?,?,?)',
        [$appA_id, 'Aのコメント1', 'approved', $now]
    );
    // ゲスト作品Bに3つ
    db_insert(
        'INSERT INTO comments (app_id, body, status, ctime) VALUES (?,?,?,?)',
        [$appB_id, 'Bのコメント1', 'approved', $now]
    );
    db_insert(
        'INSERT INTO comments (app_id, body, status, ctime) VALUES (?,?,?,?)',
        [$appB_id, 'Bのコメント2', 'approved', $now]
    );
    db_insert(
        'INSERT INTO comments (app_id, body, status, ctime) VALUES (?,?,?,?)',
        [$appB_id, 'Bのコメント3', 'approved', $now]
    );

    $_GET = ['sort' => 'comment'];
    $out_comment = n3s_test_capture(fn() => n3s_web_list_nologin());
    // コメント順なので B (コメント3個) が A (コメント1個) より先に表示されるはず
    $posA_comment = strpos($out_comment, 'ゲスト作品A');
    $posB_comment = strpos($out_comment, 'ゲスト作品B');
    expect($posB_comment)->toBeLessThan($posA_comment);
});
