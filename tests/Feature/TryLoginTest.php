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

test('存在しないメールアドレスでも失敗回数のカウントおよびip_checkへの記録が行われる', function () {
    n3s_test_trylogin_post('nobody2@example.com', 'whatever');

    n3s_test_capture(fn () => n3s_web_login_trylogin());

    expect($_SESSION['n3s_trylogin_count'])->toBe(1);

    $count = db_get1('SELECT count(*) AS c FROM ip_check WHERE memo=?', ['nobody2@example.com'], 'log');
    expect((int) $count['c'])->toBe(1);
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

test('ログイン失敗が多すぎる場合(IPブロック)にログインがブロックされ、例外が発生しない', function () {
    n3s_add_user('try4@example.com', 'right-password', '試行四郎');
    n3s_test_trylogin_post('try4@example.com', 'right-password');
    $ip = $_SERVER['REMOTE_ADDR'];
    for ($i = 0; $i < 11; $i++) {
        db_exec("INSERT INTO ip_check (key, ip, memo, ctime) VALUES(?,?,?,?)", [0, $ip, 'try4@example.com', time()], 'log');
    }

    $out = n3s_test_capture(fn () => n3s_web_login_trylogin());

    expect($out)->toContain('ログイン失敗が多すぎます');
    expect(n3s_is_login())->toBeFalse();
});

test('パスワード誤りを5回繰り返すとロックされてエラー画面が表示される', function () {
    n3s_add_user('try6@example.com', 'right-password', '試行六郎');

    // 5回まではエラーメッセージ
    for ($i = 1; $i <= 5; $i++) {
        n3s_test_trylogin_post('try6@example.com', 'wrong-password');
        n3s_test_capture(fn () => n3s_web_login_trylogin());
    }

    // 6回目
    n3s_test_trylogin_post('try6@example.com', 'wrong-password');
    $out = n3s_test_capture(fn () => n3s_web_login_trylogin());

    expect($out)->toContain('ログインに5回以上失敗しました');
    expect(n3s_is_login())->toBeFalse();
    expect($_SESSION)->not->toHaveKey('n3s_trylogin_count');
});

test('パスワード未設定ユーザーのログイン試行時は再設定を促す画面が表示される', function () {
    $user_id = n3s_add_user('try7@example.com', 'temp-password', '試行七郎');
    db_exec('UPDATE users SET password="" WHERE user_id=?', [$user_id], 'users');

    n3s_test_trylogin_post('try7@example.com', 'any-password');
    $out = n3s_test_capture(fn () => n3s_web_login_trylogin());

    expect($out)->toContain('パスワードの再設定が必要です');
    expect(n3s_is_login())->toBeFalse();
});
