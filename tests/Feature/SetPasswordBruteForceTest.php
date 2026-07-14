<?php
// tests/Feature/SetPasswordBruteForceTest.php
// todo-security.md #5:
// パスワード再設定の認証番号(pass_token)に対する総当たり対策を検証する。
// n3s_web_login_setpw() のブロック分岐は exit しない(return する)ためテスト可能。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/login.inc.php';

// 認証番号メールを送って "XXX-XXXX" を取り出す (db_insert は文字列IDを返すため型は緩める)
function n3s_test_issue_pass_token($user_id, $email): string
{
    $out = n3s_test_capture(fn () => n3s_web_login_setpw_sendmail($user_id, $email, 'forgot'));
    preg_match('/(\d{3}-\d{4})/', $out, $m);
    return $m[1];
}

test('認証番号を1回間違えると失敗が記録され、やり直し案内が表示される', function () {
    $user_id = n3s_add_user('bf1@example.com', 'password1', 'ミス子');
    $token = n3s_test_issue_pass_token($user_id, 'bf1@example.com');
    [$good1] = explode('-', $token);
    $wrong1 = ($good1 === '999') ? '000' : '999';

    $csrf = n3s_getEditToken('setpw', false);
    $_POST = ['email' => 'bf1@example.com', 'pass1' => $wrong1, 'pass2' => '0000', 'token' => $csrf];
    $out = n3s_test_capture(fn () => n3s_web_login_setpw('bf1@example.com'));

    expect($out)->toContain('登録失敗');
    expect(n3s_setpw_recent_failures('bf1@example.com', $_SERVER['REMOTE_ADDR']))->toBe(1);
});

test('認証番号を上限まで間違えるとブロックされ、以降は正しい番号でも拒否・トークン無効化される', function () {
    $user_id = n3s_add_user('bf2@example.com', 'password1', 'ブルート太郎');
    $token = n3s_test_issue_pass_token($user_id, 'bf2@example.com');
    [$good1, $good2] = explode('-', $token);
    $wrong1 = ($good1 === '999') ? '000' : '999'; // 正解と必ず異なる pass1
    $csrf = n3s_getEditToken('setpw', false);

    // 上限回数ぶん、誤った認証番号を送る
    for ($i = 0; $i < N3S_SETPW_MAX_TRIES; $i++) {
        $_POST = ['email' => 'bf2@example.com', 'pass1' => $wrong1, 'pass2' => '0000', 'token' => $csrf];
        n3s_test_capture(fn () => n3s_web_login_setpw('bf2@example.com'));
    }
    expect(n3s_setpw_recent_failures('bf2@example.com', $_SERVER['REMOTE_ADDR']))
        ->toBe(N3S_SETPW_MAX_TRIES);

    // 上限到達後は、正しい認証番号 + 正しい新パスワードでもブロックされる
    $_POST = [
        'email' => 'bf2@example.com',
        'pass1' => $good1,
        'pass2' => $good2,
        'token' => $csrf,
        'password' => 'newpassword123',
        'password2' => 'newpassword123',
    ];
    $out = n3s_test_capture(fn () => n3s_web_login_setpw('bf2@example.com'));
    expect($out)->toContain('試行回数が多すぎます');

    // トークンは無効化され、パスワードは更新されていない
    $row = db_get1('SELECT pass_token, password FROM users WHERE user_id=?', [$user_id], 'users');
    expect($row['pass_token'])->toBe('');
    expect(n3s_password_verify('newpassword123', $row['password']))->toBeFalse();
});

test('認証番号を再発行しても、過去の総当たり失敗カウントはリセットされない (指摘の回帰防止)', function () {
    // 以前は再発行のたびに失敗カウントを0にリセットしていたため、
    // 「数回試行→再発行→数回試行」を繰り返すことで N3S_SETPW_MAX_TRIES の制限を
    // 実質無制限に回避できてしまっていた。再発行してもリセットされないことを確認する。
    $user_id = n3s_add_user('bf3@example.com', 'password1', 'リセット');
    $ip = $_SERVER['REMOTE_ADDR'];
    n3s_setpw_record_failure('bf3@example.com', $ip);
    n3s_setpw_record_failure('bf3@example.com', $ip);
    expect(n3s_setpw_recent_failures('bf3@example.com', $ip))->toBe(2);

    n3s_test_capture(fn () => n3s_web_login_setpw_sendmail($user_id, 'bf3@example.com', 'forgot'));

    expect(n3s_setpw_recent_failures('bf3@example.com', $ip))->toBe(2);
});

test('「試行→再発行→試行」を繰り返しても、累積の失敗回数でブロックされる (再発行によるブロック回避を閉じる回帰テスト)', function () {
    $user_id = n3s_add_user('bf4@example.com', 'password1', '回避太郎');
    $csrf = n3s_getEditToken('setpw', false);
    $ip = $_SERVER['REMOTE_ADDR'];

    // 1周目: 9回誤った番号を試す (まだブロックされない)
    $token1 = n3s_test_issue_pass_token($user_id, 'bf4@example.com');
    [$good1] = explode('-', $token1);
    $wrong = ($good1 === '999') ? '000' : '999';
    for ($i = 0; $i < 9; $i++) {
        $_POST = ['email' => 'bf4@example.com', 'pass1' => $wrong, 'pass2' => '0000', 'token' => $csrf];
        $out = n3s_test_capture(fn () => n3s_web_login_setpw('bf4@example.com'));
        expect($out)->not->toContain('試行回数が多すぎます');
    }
    expect(n3s_setpw_recent_failures('bf4@example.com', $ip))->toBe(9);

    // 再発行 (新しい番号を取得。以前はこれで失敗カウントが0にリセットされ、
    // 再び9回まで自由に試行できてしまっていた)
    $token2 = n3s_test_issue_pass_token($user_id, 'bf4@example.com');
    [$good2a, $good2b] = explode('-', $token2);
    expect(n3s_setpw_recent_failures('bf4@example.com', $ip))->toBe(9); // リセットされていない

    // 2周目: 新しい番号でさらに誤った番号を試すと、累積で早い段階でブロックされる
    $blocked = false;
    for ($i = 0; $i < 9; $i++) {
        $_POST = ['email' => 'bf4@example.com', 'pass1' => $wrong, 'pass2' => '0000', 'token' => $csrf];
        $out = n3s_test_capture(fn () => n3s_web_login_setpw('bf4@example.com'));
        if (strpos($out, '試行回数が多すぎます') !== false) {
            $blocked = true;
            break;
        }
    }
    expect($blocked)->toBeTrue();

    // ブロック後は、2周目で発行された正しい番号を使っても合格できない
    $_POST = [
        'email' => 'bf4@example.com',
        'pass1' => $good2a,
        'pass2' => $good2b,
        'token' => $csrf,
        'password' => 'newpassword123',
        'password2' => 'newpassword123',
    ];
    $out = n3s_test_capture(fn () => n3s_web_login_setpw('bf4@example.com'));
    expect($out)->toContain('試行回数が多すぎます');
});

test('認証番号の再発行はIP単位で回数制限される', function () {
    $user_id = n3s_add_user('bf5@example.com', 'password1', '再発行太郎');
    $ip = $_SERVER['REMOTE_ADDR'];

    for ($i = 0; $i < N3S_SETPW_REISSUE_MAX_TRIES; $i++) {
        $sent = n3s_test_call(fn () => n3s_web_login_setpw_sendmail($user_id, 'bf5@example.com', 'forgot'));
        expect($sent)->toBeTrue();
    }
    expect(n3s_setpw_recent_reissues($ip))->toBe(N3S_SETPW_REISSUE_MAX_TRIES);

    // 上限到達後は再発行されない(戻り値false、pass_tokenも更新されない)
    $before = db_get1('SELECT pass_token FROM users WHERE user_id=?', [$user_id], 'users')['pass_token'];
    $sent = n3s_test_call(fn () => n3s_web_login_setpw_sendmail($user_id, 'bf5@example.com', 'forgot'));
    expect($sent)->toBeFalse();
    $after = db_get1('SELECT pass_token FROM users WHERE user_id=?', [$user_id], 'users')['pass_token'];
    expect($after)->toBe($before);
});

test('n3s_web_login_forgot() は再発行の上限到達時にエラーを表示する', function () {
    n3s_add_user('bf6@example.com', 'password1', '再発行六郎');
    $ip = $_SERVER['REMOTE_ADDR'];
    // 上限まで再発行枠を使い切る
    for ($i = 0; $i < N3S_SETPW_REISSUE_MAX_TRIES; $i++) {
        n3s_setpw_record_reissue($ip);
    }

    $_POST = ['email' => 'bf6@example.com', 'email2' => 'bf6@example.com', 'quiz' => 'くさばな'];
    $out = n3s_test_capture(fn () => n3s_web_login_forgot());

    expect($out)->toContain('再発行できません');
});
