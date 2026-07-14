<?php

test('正しいメールアドレスとパスワードでログインするとセッションにログイン情報が保存される', function () {
    // n3s_add_user() は PDO::lastInsertId() 由来の文字列を返すため、int化して比較する
    $user_id = (int) n3s_add_user('login@example.com', 'correct-horse', 'ログインユーザー');

    $ok = n3s_login('login@example.com', 'correct-horse');

    expect($ok)->toBeTrue()
        ->and($_SESSION['n3s_login'])->toBeTrue()
        ->and($_SESSION['user_id'])->toBe($user_id)
        ->and($_SESSION['name'])->toBe('ログインユーザー')
        ->and($_SESSION['screen_name'])->toBe('ログインユーザー')
        ->and($_SESSION['profile_url'])->toBe('');
});

test('パスワードが間違っているとログインは失敗し、セッションは変更されない', function () {
    n3s_add_user('login2@example.com', 'correct-horse', 'ユーザー');

    $ok = n3s_login('login2@example.com', 'wrong-password');

    expect($ok)->toBeFalse()
        ->and($_SESSION)->not->toHaveKey('n3s_login');
});

test('存在しないメールアドレスはログインに失敗する', function () {
    $ok = n3s_login('nobody@example.com', 'whatever');

    expect($ok)->toBeFalse();
});

test('salt列が空の既存ユーザー (def:: 後方互換) でもログインできる', function () {
    n3s_test_add_legacy_user('legacy@example.com', 'legacy-pass', 'レガシー');

    $ok = n3s_login('legacy@example.com', 'legacy-pass');

    expect($ok)->toBeTrue()
        ->and($_SESSION['n3s_login'])->toBeTrue();
});

test('def:: 後方互換ユーザーも誤ったパスワードでは失敗する', function () {
    n3s_test_add_legacy_user('legacy2@example.com', 'legacy-pass', 'レガシー');

    $ok = n3s_login('legacy2@example.com', 'incorrect');

    expect($ok)->toBeFalse();
});

test('パスワード未設定 (password="") のユーザーはどんな入力でもログインできない', function () {
    // docs/user_login.md #4.5 手順5, #9: 新規登録直後はダミーパスワードのハッシュが
    // 設定され、パスワード設定フローを経るまで本来のログインはできない設計になっている。
    // ここではその前提となる「password列が空ならn3s_login()は常にfalseを返す」という
    // 不変条件をユニットレベルで検証する
    // (n3s_web_login_trylogin() 側のガード分岐は exit() するため直接は呼べない)。
    $user_id = db_insert(
        'INSERT INTO users (email, password, name, salt) VALUES (?,?,?,?)',
        ['nopassword@example.com', '', '未設定太郎', ''],
        'users'
    );
    expect($user_id)->toBeGreaterThan(0);

    expect(n3s_login('nopassword@example.com', ''))->toBeFalse()
        ->and(n3s_login('nopassword@example.com', 'anything'))->toBeFalse();
});

test('n3s_is_login / n3s_get_user_id はログイン前はfalse/0を返す', function () {
    expect(n3s_is_login())->toBeFalse()
        ->and(n3s_get_user_id())->toBe(0);
});

test('n3s_get_login_info はログイン前はデフォルト値を返す', function () {
    $info = n3s_get_login_info();

    expect($info)->toBe([
        'user_id' => 0,
        'name' => '?',
        'screen_name' => '?',
        'profile_url' => 'skin/def/user-icon.png',
    ]);
});

test('n3s_get_login_info はログイン後はセッションの値を返す', function () {
    $user_id = (int) n3s_add_user('info@example.com', 'password1', '情報太郎');
    n3s_login('info@example.com', 'password1');

    $info = n3s_get_login_info();

    expect($info)->toBe([
        'user_id' => $user_id,
        'name' => '情報太郎',
        'screen_name' => '情報太郎',
        'profile_url' => '',
    ]);
});

test('n3s_is_admin は admin_users に含まれるユーザーIDのみtrueを返す', function () {
    n3s_test_setup(['admin_users' => [42]]);
    $admin_id = n3s_add_user('admin@example.com', 'adminpass', '管理者');
    $user_id = n3s_add_user('normal@example.com', 'userpass', '一般ユーザー');

    // 管理者IDを42に固定できないため、42になりきったセッションを模擬する
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = 42;
    expect(n3s_is_admin())->toBeTrue();

    $_SESSION['user_id'] = $user_id;
    expect(n3s_is_admin())->toBeFalse();

    unset($admin_id);
});

test('n3s_logout はログイン関連のセッションを消去する', function () {
    n3s_add_user('logout@example.com', 'password1', 'ログアウト太郎');
    n3s_login('logout@example.com', 'password1');
    n3s_setBackURL('index.php?action=list');

    n3s_logout();

    expect(n3s_is_login())->toBeFalse()
        ->and($_SESSION)->not->toHaveKeys(['n3s_login', 'user_id', 'n3s_backurl', 'name']);

    $log = db_get1("SELECT * FROM logs WHERE kind='logout' ORDER BY log_id DESC LIMIT 1", [], 'log');
    expect($log)->not->toBeFalse();
});

test('ログインしていない状態で n3s_logout を呼んでもログは記録されない', function () {
    n3s_logout();

    $count = db_get1("SELECT count(*) AS c FROM logs WHERE kind='logout'", [], 'log');
    expect((int) $count['c'])->toBe(0);
});

test('n3s_get_user_name はログイン前は "?" を返す', function () {
    expect(n3s_get_user_name())->toBe('?');
});

test('n3s_get_user_name はログイン後、名前ではなく常に0を返す (既知の不具合)', function () {
    // n3s_get_user_name() は $_SESSION['name'] を (int) キャストして返しており、
    // 数字始まりでない名前(通常のユーザー名)は必ず int(0) になってしまう。
    // n3s_lib.inc.php 内で `$a['author'] = n3s_get_user_name();` として投稿の
    // 著者名に使われている箇所があり、本来ログインユーザー名が入るべき場所が
    // 0になってしまう実装バグ。修正はせず、現状の挙動として固定化しておく。
    n3s_add_user('username@example.com', 'password1', '名前太郎');
    n3s_login('username@example.com', 'password1');

    expect(n3s_get_user_name())->toBe(0)
        ->and(n3s_get_user_name())->not->toBe('名前太郎');
});
