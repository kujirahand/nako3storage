<?php
// app/action/login.inc.php の n3s_web_login_trylogin()
// docs/user_login.md #4.5 のフローに対応するテスト。
//
// 5回連続失敗時・IPブロック時・パスワード未設定ユーザー時は内部で exit() する実装のため、
// プロセスを落とさずに検証できる範囲(1回のログイン試行)に絞ってテストする。

function n3s_test_trylogin_post(string $email, string $password): void
{
    $token = n3s_getEditToken();
    $_POST['email'] = $email;
    $_POST['password'] = $password;
    $_REQUEST['edit_token'] = $token;
}

test('正しいメールアドレスとパスワードでログインに成功する', function () {
    n3s_add_user('try1@example.com', 'right-password', '試行太郎');
    n3s_test_trylogin_post('try1@example.com', 'right-password');

    n3s_test_capture(fn () => n3s_web_login_trylogin());

    expect(n3s_is_login())->toBeTrue();
});

test('パスワードが間違っていると失敗回数がセッションに記録され、エラー画面が表示される', function () {
    n3s_add_user('try2@example.com', 'right-password', '試行二郎');
    n3s_test_trylogin_post('try2@example.com', 'wrong-password');

    $out = n3s_test_capture(fn () => n3s_web_login_trylogin());

    expect(n3s_is_login())->toBeFalse()
        ->and($_SESSION['n3s_trylogin_count'])->toBe(1)
        ->and($out)->toContain('メールアドレスかパスワードが間違っています');

    $ipCheck = db_get1('SELECT * FROM ip_check WHERE memo=?', ['try2@example.com'], 'log');
    expect($ipCheck)->not->toBeFalse();
});

test('CSRFトークンが不正な場合はログインできない', function () {
    n3s_add_user('try3@example.com', 'right-password', '試行三郎');
    $_POST['email'] = 'try3@example.com';
    $_POST['password'] = 'right-password';
    $_REQUEST['edit_token'] = 'invalid-token';
    n3s_getEditToken(); // セッション側には別のトークンが発行される

    $out = n3s_test_capture(fn () => n3s_web_login_trylogin());

    expect(n3s_is_login())->toBeFalse()
        ->and($out)->toContain('セッションが切れました');
});

test('存在しないメールアドレスではログインできない', function () {
    n3s_test_trylogin_post('nobody@example.com', 'whatever');

    n3s_test_capture(fn () => n3s_web_login_trylogin());

    expect(n3s_is_login())->toBeFalse();
});

test('存在しないメールアドレスでは失敗回数のカウントもip_checkへの記録もされない (既知の非対称性)', function () {
    // 実在メールアドレスへのパスワード誤りだけがセッションの失敗カウントと
    // ip_check への記録の対象になっており (n3s_get_user_id_by_email() の結果が
    // $user_id > 0 の分岐内でのみ処理される)、未登録メールアドレスへの試行は
    // 完全に素通りする。ブルートフォース対策・メールアドレス在否の推測しやすさに
    // 関わる挙動なので、現状の仕様として固定化しておく。
    n3s_test_trylogin_post('nobody2@example.com', 'whatever');

    n3s_test_capture(fn () => n3s_web_login_trylogin());

    expect($_SESSION)->not->toHaveKey('n3s_trylogin_count');

    $count = db_get1('SELECT count(*) AS c FROM ip_check', [], 'log');
    expect((int) $count['c'])->toBe(0);
});

test('パスワード誤りを繰り返すと失敗回数が1件ずつ積み上がる (5回未満)', function () {
    n3s_add_user('try5@example.com', 'right-password', '試行五郎');

    foreach ([1, 2, 3, 4] as $expectedCount) {
        n3s_test_trylogin_post('try5@example.com', 'wrong-password');
        $out = n3s_test_capture(fn () => n3s_web_login_trylogin());

        expect($_SESSION['n3s_trylogin_count'])->toBe($expectedCount)
            ->and($out)->toContain("メールアドレスかパスワードが間違っています。#{$expectedCount}");
    }

    expect(n3s_is_login())->toBeFalse();

    $count = db_get1('SELECT count(*) AS c FROM ip_check WHERE memo=?', ['try5@example.com'], 'log');
    expect((int) $count['c'])->toBe(4);
});

test('REMOTE_ADDRが空のときはip_checkの参照先DBを取り違えて例外になる (既知の不具合)', function () {
    // docs/user_login.md #9 が指摘する通り、REMOTE_ADDRが空文字のときだけ
    // ブルートフォース検出のカウントクエリが走る。しかし app/action/login.inc.php の
    // db_get('SELECT count(*) FROM ip_check ...', [...]) はdbname引数を省略しており、
    // 既定の'main'DBを見に行ってしまう。ip_checkテーブルは'log'DBにしか無いため、
    // 通常はほぼ通らないこの分岐に限って例外(PDOException)で落ちる。
    // これは仕様ではなく現状のバグを固定化するテストなので、修正時は本テストの更新も検討すること。
    n3s_add_user('try4@example.com', 'right-password', '試行四郎');
    n3s_test_trylogin_post('try4@example.com', 'right-password');
    unset($_SERVER['REMOTE_ADDR']);

    expect(fn () => n3s_test_capture(fn () => n3s_web_login_trylogin()))
        ->toThrow(PDOException::class, 'no such table: ip_check');
});
