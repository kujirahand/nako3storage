<?php
// app/action/login.inc.php の n3s_web_login_execute()
// (login()成功後のセッション確定とリダイレクト先解決)

test('ログイン成功時はセッションが確定し、trueを返す', function () {
    n3s_add_user('exec@example.com', 'password123', '実行太郎');

    $ok = n3s_test_call(fn () => n3s_web_login_execute('exec@example.com', 'password123'));

    expect($ok)->toBeTrue()
        ->and(n3s_is_login())->toBeTrue();
});

test('ログイン成功時、back_urlが設定されていれば消費される (リダイレクト先として使われる)', function () {
    n3s_add_user('exec2@example.com', 'password123', '実行二郎');
    n3s_setBackURL('index.php?action=mypage');

    n3s_test_call(fn () => n3s_web_login_execute('exec2@example.com', 'password123'));

    // n3s_getBackURL() は取得と同時にセッションから消える
    expect($_SESSION)->not->toHaveKey('n3s_backurl');
});

test('パスワードが間違っている場合はfalseを返し、ログイン状態にならない', function () {
    n3s_add_user('exec3@example.com', 'password123', '実行三郎');

    $ok = n3s_test_call(fn () => n3s_web_login_execute('exec3@example.com', 'wrong-password'));

    expect($ok)->toBeFalse()
        ->and(n3s_is_login())->toBeFalse();
});
