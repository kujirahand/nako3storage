<?php
// tests/Feature/ProgramPrivacyTest.php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/show.inc.php';

test('限定公開の作品に対し、正しいeditkeyを渡した場合はエラーにならずに閲覧可能であること', function () {
    $editkey = 'valid-key';
    
    // 1. 限定公開(is_private = 2)の作品を作成して挿入
    $app_id = db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, editkey, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['限定公開の作品', '限定作者', 0, 2, $editkey, time(), time()]
    );
    
    $dbname = n3s_getMaterialDB($app_id);
    db_insert(
        'INSERT INTO materials (material_id, body) VALUES (?,?)',
        [$app_id, '「限定公開のプログラム」と表示する。'],
        $dbname
    );

    // 2. 正しいeditkeyでアクセス
    $_GET['page'] = (string)$app_id;
    $_GET['editkey'] = $editkey;
    
    $show_res = n3s_show_get('show', 'web', true, true);
    expect($show_res['result'])->toBeTrue();
    expect($show_res['title'])->toBe('限定公開の作品');
});

test('非公開の作品に対し、本人がログイン中の場合は閲覧可能であること', function () {
    // 1. ユーザーAを登録
    $email = 'user_a@example.com';
    $password = 'password';
    $user_id_a = n3s_add_user($email, $password, 'ユーザーA');

    // 2. ユーザーAの非公開(is_private = 1)作品を作成
    $app_id = db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, ctime, mtime) VALUES (?,?,?,?,?,?)',
        ['非公開の作品', 'ユーザーA', $user_id_a, 1, time(), time()]
    );
    
    $dbname = n3s_getMaterialDB($app_id);
    db_insert(
        'INSERT INTO materials (material_id, body) VALUES (?,?)',
        [$app_id, '「非公開のプログラム」と表示する。'],
        $dbname
    );

    // 3. 本人(ユーザーA)でログインしてアクセス
    n3s_web_login_execute($email, $password);
    expect(n3s_is_login())->toBeTrue();
    
    $_GET['page'] = (string)$app_id;
    
    $show_res = n3s_show_get('show', 'web', true, true);
    expect($show_res['result'])->toBeTrue();
    expect($show_res['title'])->toBe('非公開の作品');
});

test('非公開の作品に対し、管理者がログイン中の場合は閲覧可能であること', function () {
    // 1. 先に管理者(user_id=1)を登録する
    $admin_user_id = n3s_add_user('admin@example.com', 'password', '管理者');
    expect($admin_user_id)->toEqual(1);

    // 2. 次にユーザーAを登録
    $user_id_a = n3s_add_user('user_a@example.com', 'password', 'ユーザーA');

    // 3. ユーザーAの非公開(is_private = 1)作品を作成
    $app_id = db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, ctime, mtime) VALUES (?,?,?,?,?,?)',
        ['非公開の作品', 'ユーザーA', $user_id_a, 1, time(), time()]
    );
    
    $dbname = n3s_getMaterialDB($app_id);
    db_insert(
        'INSERT INTO materials (material_id, body) VALUES (?,?)',
        [$app_id, '「非公開のプログラム」と表示する。'],
        $dbname
    );

    // 4. 管理者(user_id=1)でログインしてアクセス
    n3s_web_login_execute('admin@example.com', 'password');
    expect(n3s_is_login())->toBeTrue();
    expect(n3s_is_admin())->toBeTrue();
    
    $_GET['page'] = (string)$app_id;
    
    $show_res = n3s_show_get('show', 'web', true, true);
    expect($show_res['result'])->toBeTrue();
    expect($show_res['title'])->toBe('非公開の作品');
});
