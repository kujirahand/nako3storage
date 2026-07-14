<?php
// app/action/login.inc.php の n3s_web_login_register()
// docs/user_login.md #4.1 の入力チェックに対応するテスト。
//
// 全項目が正しい場合は内部で n3s_web_login_setpw() を呼び、そちらが exit() するため、
// ここでは「登録が失敗するはずのケース」で新規ユーザーが作られないことを検証する。

test('必須項目が未入力なら登録されない', function () {
    n3s_test_capture(fn () => n3s_web_login_register());

    expect(n3s_get_user_id_by_email(''))->toBe(0);
});

test('いたずら防止の答えが間違っていると登録されない', function () {
    $_POST['email'] = 'newuser@example.com';
    $_POST['email2'] = 'newuser@example.com';
    $_POST['name'] = 'にゅーゆーざー';
    $_POST['itazura'] = '違う答え';

    $out = n3s_test_capture(fn () => n3s_web_login_register());

    expect($out)->toContain('イタズラ防止用の質問が間違っています')
        ->and(n3s_get_user_id_by_email('newuser@example.com'))->toBe(0);
});

test('確認用メールアドレスが一致しないと登録されない', function () {
    $_POST['email'] = 'a@example.com';
    $_POST['email2'] = 'b@example.com';
    $_POST['name'] = 'てすとたろう';
    $_POST['itazura'] = 'ニンゲン';

    $out = n3s_test_capture(fn () => n3s_web_login_register());

    expect($out)->toContain('確認用に入力されたメールアドレスが合致しません')
        ->and(n3s_get_user_id_by_email('a@example.com'))->toBe(0);
});

test('名前が4文字未満だと登録されない', function () {
    $_POST['email'] = 'short@example.com';
    $_POST['email2'] = 'short@example.com';
    $_POST['name'] = 'あい';
    $_POST['itazura'] = 'ニンゲン';

    $out = n3s_test_capture(fn () => n3s_web_login_register());

    expect($out)->toContain('4文字以上12文字以内')
        ->and(n3s_get_user_id_by_email('short@example.com'))->toBe(0);
});

test('メールアドレスの形式が不正だと登録されない', function () {
    $_POST['email'] = 'not-an-email';
    $_POST['email2'] = 'not-an-email';
    $_POST['name'] = 'てすとたろう';
    $_POST['itazura'] = 'ニンゲン';

    $out = n3s_test_capture(fn () => n3s_web_login_register());

    expect($out)->toContain('メールアドレスを正しく入力してください');
});

test('既に登録済みのメールアドレスでは新規登録できない', function () {
    n3s_add_user('exists@example.com', 'password1', '既存ユーザー');

    $_POST['email'] = 'exists@example.com';
    $_POST['email2'] = 'exists@example.com';
    $_POST['name'] = 'あたらしいひと';
    $_POST['itazura'] = 'ニンゲン';

    $out = n3s_test_capture(fn () => n3s_web_login_register());

    expect($out)->toContain('既に登録されています');

    $count = db_get1('SELECT count(*) AS c FROM users WHERE email=?', ['exists@example.com'], 'users');
    expect((int) $count['c'])->toBe(1);
});
