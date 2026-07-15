<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/list_login.inc.php';

test('ログインあり一覧(list_login)で登録ユーザーの投稿のみが表示されることを確認', function () {
    $now = time();
    
    // ログインユーザーA
    $userIdA = n3s_add_user('userA@example.com', 'password1', '登録ユーザーA');
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?)',
        ['ユーザー作品A', '登録ユーザーA', $userIdA, 0, 1, 10, 'wnako', $now, $now]
    );

    // ログインユーザーB
    $userIdB = n3s_add_user('userB@example.com', 'password1', '登録ユーザーB');
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?)',
        ['ユーザー作品B', '登録ユーザーB', $userIdB, 0, 5, 50, 'wnako', $now - 100, $now - 100]
    );

    // 非ログインユーザーの投稿
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?)',
        ['ゲスト作品', 'ゲスト', 0, 0, 10, 100, 'wnako', $now, $now]
    );

    // 最新順 (sort = mtime)
    $_GET = ['sort' => 'mtime'];
    $out = n3s_test_capture(fn() => n3s_web_list_login());
    expect($out)->toContain('ユーザー作品A');
    expect($out)->toContain('ユーザー作品B');
    expect($out)->not->toContain('ゲスト作品');
    // 最新順なので、A が先に表示されるはず
    $posA = strpos($out, 'ユーザー作品A');
    $posB = strpos($out, 'ユーザー作品B');
    expect($posA)->toBeLessThan($posB);

    // アクセス順 (sort = view)
    $_GET = ['sort' => 'view'];
    $out_view = n3s_test_capture(fn() => n3s_web_list_login());
    // アクセス順なので B (view: 50) が A (view: 10) より先に表示されるはず
    $posA_view = strpos($out_view, 'ユーザー作品A');
    $posB_view = strpos($out_view, 'ユーザー作品B');
    expect($posB_view)->toBeLessThan($posA_view);

    // お気に入り順 (sort = fav)
    $_GET = ['sort' => 'fav'];
    $out_fav = n3s_test_capture(fn() => n3s_web_list_login());
    // お気に入り順なので B (fav: 5) が A (fav: 1) より先に表示されるはず
    $posA_fav = strpos($out_fav, 'ユーザー作品A');
    $posB_fav = strpos($out_fav, 'ユーザー作品B');
    expect($posB_fav)->toBeLessThan($posA_fav);

    // コメント順 (sort = comment)
    $appA = db_get1('SELECT app_id FROM apps WHERE title = ?', ['ユーザー作品A']);
    $appB = db_get1('SELECT app_id FROM apps WHERE title = ?', ['ユーザー作品B']);
    $appA_id = intval($appA['app_id']);
    $appB_id = intval($appB['app_id']);

    // ユーザー作品Aに1つ
    db_insert(
        'INSERT INTO comments (app_id, body, status, ctime) VALUES (?,?,?,?)',
        [$appA_id, 'Aのコメント1', 'approved', $now]
    );
    // ユーザー作品Bに3つ
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
    $out_comment = n3s_test_capture(fn() => n3s_web_list_login());
    // コメント順なので B (コメント3個) が A (コメント1個) より先に表示されるはず
    $posA_comment = strpos($out_comment, 'ユーザー作品A');
    $posB_comment = strpos($out_comment, 'ユーザー作品B');
    expect($posB_comment)->toBeLessThan($posA_comment);
});
