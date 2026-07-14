<?php
// app/action/list.inc.php, app/action/show.inc.php, app/action/save.inc.php の連携テスト

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/list.inc.php';
require_once N3S_TEST_ROOT . '/app/action/show.inc.php';
require_once N3S_TEST_ROOT . '/app/action/save.inc.php';

test('ログインなし状態で、プログラム一覧を見る。一番上のプログラムを開いて、プログラムを確認する。', function () {
    // 1. ダミーの公開プログラムを登録する
    // appsテーブルに挿入
    $app_id = db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, ctime, mtime, nakotype) VALUES (?,?,?,?,?,?,?)',
        ['テストプログラム1', 'ゲスト作者1', 0, 0, time(), time(), 'wnako']
    );

    // materialsテーブルに挿入（本文）
    $dbname = n3s_getMaterialDB($app_id);
    db_insert(
        'INSERT INTO materials (material_id, body) VALUES (?,?)',
        [$app_id, '「こんにちは」と表示するプログラム。テスト用です。'],
        $dbname
    );

    // 2. プログラム一覧を表示して一番上のプログラムを取得
    $_GET['mode'] = 'list';
    $list_res = n3s_list_get();

    // ログインなしで投稿されたプログラムは、ユーザーIDが0なので、n3s_list_get()のロジック上 $list2 に入ります。
    // (user_id == 0 のものは $list2 に分類されるため)
    expect($list_res['list2'])->not->toBeEmpty();
    $top_app = $list_res['list2'][0];
    expect($top_app['app_id'])->toEqual($app_id);
    expect($top_app['title'])->toBe('テストプログラム1');

    // 3. 一番上のプログラムを開いて、プログラムを確認する
    $_GET['page'] = (string)$top_app['app_id'];
    $show_res = n3s_show_get('show', 'web', true, true);

    expect($show_res['result'])->toBeTrue();
    expect($show_res['title'])->toBe('テストプログラム1');

    // materialsから本文が正しく取得できているか確認
    $material = n3s_getMaterialData($top_app['app_id']);
    expect($material)->not->toBeNull();
    expect(trim($material['body']))->toBe('「こんにちは」と表示するプログラム。テスト用です。');
});

test('ログインして、「こんにちは」と表示 というプログラムを書き込んで、プログラムが書き込まれているのを確認する。', function () {
    // 1. ユーザーを登録
    $email = 'test_save@example.com';
    $password = 'password123';
    $name = 'なでしこユーザー';
    $user_id = n3s_add_user($email, $password, $name);
    expect($user_id)->toBeGreaterThan(0);

    // 2. ログイン状態にする
    $token = n3s_getEditToken();
    $_POST['email'] = $email;
    $_POST['password'] = $password;
    $_REQUEST['edit_token'] = $token;

    // tryloginを呼んでログイン完了させる
    n3s_test_capture(fn () => n3s_web_login_trylogin());
    expect(n3s_is_login())->toBeTrue();

    // 3. 新しくプログラムを投稿する
    // CSRF用のトークンを取得
    $edit_token = n3s_getEditToken();

    // POSTデータを準備
    $_POST = [
        'title' => 'こんにちはのテスト',
        'author' => $name,
        'body' => '「こんにちは」と表示。# バリデーション回避用の十分な長さのテキスト',
        'nakotype' => 'wnako',
        'copyright' => 'MIT',
        'is_private' => 0,
        'agree' => 'checked',
        'version' => '3.3.0',
    ];
    $_REQUEST['edit_token'] = $edit_token;
    $_GET['mode'] = 'edit';
    $_GET['page'] = '0'; // 新規投稿

    // 保存を実行
    n3s_test_capture(fn () => n3s_web_save());

    // 4. DBに書き込まれているか検証する
    // apps テーブルから最新 of 投稿を取得
    $app = db_get1('SELECT * FROM apps WHERE user_id=? ORDER BY app_id DESC LIMIT 1', [$user_id]);
    expect($app)->not->toBeEmpty();
    expect($app['title'])->toBe('こんにちはのテスト');

    // materialsテーブルから本文を取得
    $material = n3s_getMaterialData($app['app_id']);
    expect($material)->not->toBeNull();
    expect(trim($material['body']))->toBe('「こんにちは」と表示。# バリデーション回避用の十分な長さのテキスト');
});
