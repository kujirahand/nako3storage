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
