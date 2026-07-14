<?php
// app/action/login.inc.php の n3s_web_login_setpw_sendmail() / n3s_web_login_setpw()
// docs/user_login.md #4.2, #4.3 に対応するテスト。
//
// n3s_web_login_setpw() はほとんどの分岐で exit() する実装のため、
// 「exit()しない2つの分岐」だけを自動テストの対象にする:
//   - CSRFとpass_tokenの検証は通ったが、新パスワード未入力 (入力フォームを再表示)
//   - 新パスワードの検証エラー (確認用不一致 / 8文字未満)

/**
 * pass_tokenメールを送信し、"XXX-XXXX" 形式の認証番号を取り出す。
 */
function n3s_test_send_pass_token(int $user_id, string $email): string
{
    $out = n3s_test_capture(fn () => n3s_web_login_setpw_sendmail($user_id, $email, 'register'));
    preg_match('/(\d{3}-\d{4})/', $out, $m);
    return $m[1];
}

test('n3s_web_login_setpw_sendmail は7桁の認証番号を発行しDBへ保存する', function () {
    $user_id = n3s_add_user('sendmail@example.com', 'password1', '送信太郎');

    $token = n3s_test_send_pass_token($user_id, 'sendmail@example.com');

    $row = db_get1('SELECT pass_token FROM users WHERE user_id=?', [$user_id], 'users');
    expect($token)->toMatch('/^\d{3}-\d{4}$/')
        ->and($row['pass_token'])->toBe($token);
});

test('認証番号とCSRFトークンが正しければ、パスワード未入力時は入力フォームを再表示する (更新はしない)', function () {
    $user_id = n3s_add_user('setpw1@example.com', 'password1', '設定太郎');
    $token = n3s_test_send_pass_token($user_id, 'setpw1@example.com');
    [$pass1, $pass2] = explode('-', $token);

    $csrf = n3s_getEditToken('setpw', false);
    $_POST['email'] = 'setpw1@example.com';
    $_POST['pass1'] = $pass1;
    $_POST['pass2'] = $pass2;
    $_POST['token'] = $csrf;
    // password / password2 は未入力

    $out = n3s_test_capture(fn () => n3s_web_login_setpw($_POST['email']));

    expect($out)->toContain('パスワードの設定');

    $row = db_get1('SELECT password FROM users WHERE user_id=?', [$user_id], 'users');
    expect($row['password'])->toStartWith('hash::'); // 元のパスワードのまま変わっていない
});

test('新パスワードが8文字未満だとエラーになりDBは更新されない', function () {
    $user_id = n3s_add_user('setpw2@example.com', 'password1', '設定二郎');
    $token = n3s_test_send_pass_token($user_id, 'setpw2@example.com');
    [$pass1, $pass2] = explode('-', $token);
    $original = db_get1('SELECT password FROM users WHERE user_id=?', [$user_id], 'users')['password'];

    $csrf = n3s_getEditToken('setpw', false);
    $_POST['email'] = 'setpw2@example.com';
    $_POST['pass1'] = $pass1;
    $_POST['pass2'] = $pass2;
    $_POST['token'] = $csrf;
    $_POST['password'] = 'short1';
    $_POST['password2'] = 'short1';

    $out = n3s_test_capture(fn () => n3s_web_login_setpw($_POST['email']));

    expect($out)->toContain('パスワードは8文字以上で入力してください');

    $row = db_get1('SELECT password FROM users WHERE user_id=?', [$user_id], 'users');
    expect($row['password'])->toBe($original);
});

test('新パスワードと確認用が一致しないとエラーになりDBは更新されない', function () {
    $user_id = n3s_add_user('setpw3@example.com', 'password1', '設定三郎');
    $token = n3s_test_send_pass_token($user_id, 'setpw3@example.com');
    [$pass1, $pass2] = explode('-', $token);
    $original = db_get1('SELECT password FROM users WHERE user_id=?', [$user_id], 'users')['password'];

    $csrf = n3s_getEditToken('setpw', false);
    $_POST['email'] = 'setpw3@example.com';
    $_POST['pass1'] = $pass1;
    $_POST['pass2'] = $pass2;
    $_POST['token'] = $csrf;
    $_POST['password'] = 'password-A';
    $_POST['password2'] = 'password-B';

    $out = n3s_test_capture(fn () => n3s_web_login_setpw($_POST['email']));

    expect($out)->toContain('パスワード(確認用)が合致しません');

    $row = db_get1('SELECT password FROM users WHERE user_id=?', [$user_id], 'users');
    expect($row['password'])->toBe($original);
});

test('n3s_web_login() 経由 (page=setpw, 引数なし呼び出し) でも $_REQUEST の email で動作する', function () {
    // register()/forgot() は n3s_web_login_setpw($email) と引数付きで呼ぶが、
    // ルーター (n3s_web_login()) 経由の page=setpw は引数なしで呼ぶため、
    // 関数内部の「$email が空なら $_REQUEST['email'] にフォールバックする」分岐を通る。
    // これまでのテストは常に $email を明示的に渡していたため、この経路は未検証だった。
    $user_id = n3s_add_user('setpw4@example.com', 'password1', '設定四郎');
    $token = n3s_test_send_pass_token($user_id, 'setpw4@example.com');
    [$pass1, $pass2] = explode('-', $token);

    global $n3s_config;
    $n3s_config['page'] = 'setpw';
    $csrf = n3s_getEditToken('setpw', false);
    $_REQUEST['email'] = 'setpw4@example.com'; // $_POST ではなく $_REQUEST 側に用意する
    $_POST['pass1'] = $pass1;
    $_POST['pass2'] = $pass2;
    $_POST['token'] = $csrf;

    $out = n3s_test_capture(fn () => n3s_web_login());

    expect($out)->toContain('パスワードの設定')
        ->and($out)->not->toContain('メール情報が失われました');
});
