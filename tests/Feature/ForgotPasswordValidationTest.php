<?php
// app/action/login.inc.php の n3s_web_login_forgot()
// docs/user_login.md #4.4 の入力チェックに対応するテスト。
//
// クイズ正解 & メール一致の成功パターンは内部で n3s_web_login_setpw() を呼び、
// そちらが exit() するため、失敗パターンのみを検証する。

test('クイズの答えが間違っていると再設定メールは送られない', function () {
    n3s_add_user('forgot1@example.com', 'password1', '忘れた太郎');

    $_POST['email'] = 'forgot1@example.com';
    $_POST['email2'] = 'forgot1@example.com';
    $_POST['quiz'] = 'ちがうこたえ';

    $out = n3s_test_capture(fn () => n3s_web_login_forgot());

    expect($out)->toContain('クイズの答えを入力してください');

    $row = db_get1('SELECT pass_token FROM users WHERE email=?', ['forgot1@example.com'], 'users');
    expect($row['pass_token'])->toBe('');
});

test('確認用メールアドレスが一致しないと再設定メールは送られない', function () {
    n3s_add_user('forgot2@example.com', 'password1', '忘れた二郎');

    $_POST['email'] = 'forgot2@example.com';
    $_POST['email2'] = 'different@example.com';
    $_POST['quiz'] = 'くさばな';

    $out = n3s_test_capture(fn () => n3s_web_login_forgot());

    expect($out)->toContain('メールアドレスが合致しません');
});

// メモ: クイズ正解 & メール一致 (成功パターン、未登録メールでも同じ遷移になる)は
// 内部で n3s_web_login_setpw() を呼び、そちらが exit() してテストプロセスごと
// 終了してしまうため自動テストの対象外とする。手動確認 (ローカルサーバー起動)で担保する。
