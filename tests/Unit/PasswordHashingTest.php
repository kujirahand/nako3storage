<?php
// n3s_login_password_to_hash() / n3s_generate_salt() は旧方式 (SHA-256 1回計算 + salt) の
// ハッシュ生成関数。新規パスワードの保存には使われなくなったが、既存ユーザーの
// def::/salt:: 形式ハッシュを検証するために n3s_password_verify() 内部で使い続けている。
// 新方式 (password_hash()/password_verify()、'hash::' プレフィックス) のテストは
// このファイルの後半にある。

test('salt付きのハッシュは salt:: プレフィックスとsha256(password::salt)になる', function () {
    $hash = n3s_login_password_to_hash('mypassword', 'abc123');

    expect($hash)->toBe('salt::' . hash('sha256', 'mypassword::abc123'));
});

test('saltが空文字の場合は def:: プレフィックスと共通ソルトが使われる (後方互換)', function () {
    $hash = n3s_login_password_to_hash('mypassword', '');

    expect($hash)->toBe('def::' . hash('sha256', 'mypassword::' . LOGIN_HASH_SALT_DEFAULT));
});

test('saltを省略した場合も def:: プレフィックスになる', function () {
    $hash = n3s_login_password_to_hash('mypassword');

    expect($hash)->toBe('def::' . hash('sha256', 'mypassword::' . LOGIN_HASH_SALT_DEFAULT));
});

test('saltがnullの場合も def:: プレフィックスになる', function () {
    $hash = n3s_login_password_to_hash('mypassword', null);

    expect($hash)->toBe('def::' . hash('sha256', 'mypassword::' . LOGIN_HASH_SALT_DEFAULT));
});

test('同じパスワードでもsaltが異なればハッシュも異なる', function () {
    $hash1 = n3s_login_password_to_hash('mypassword', 'salt-a');
    $hash2 = n3s_login_password_to_hash('mypassword', 'salt-b');

    expect($hash1)->not->toBe($hash2);
});

test('n3s_generate_salt は64文字の16進文字列を返す', function () {
    $salt = n3s_generate_salt();

    expect($salt)->toHaveLength(64)
        ->and($salt)->toMatch('/^[0-9a-f]{64}$/');
});

test('n3s_generate_salt は呼ぶたびに異なる値を返す', function () {
    $a = n3s_generate_salt();
    $b = n3s_generate_salt();

    expect($a)->not->toBe($b);
});

// --- ここから新方式 (password_hash()/PASSWORD_DEFAULT、'hash::' プレフィックス) ---

test('n3s_password_hash は hash:: プレフィックス付きの password_hash() 文字列を返す', function () {
    $stored = n3s_password_hash('mypassword');

    expect($stored)->toStartWith('hash::');

    $real_hash = substr($stored, strlen('hash::'));
    expect(password_verify('mypassword', $real_hash))->toBeTrue()
        ->and(password_get_info($real_hash)['algoName'])->not->toBe('unknown');
});

test('n3s_password_hash は同じパスワードでも呼ぶたびに異なるハッシュ文字列を返す (ソルトを内包)', function () {
    $a = n3s_password_hash('mypassword');
    $b = n3s_password_hash('mypassword');

    expect($a)->not->toBe($b);
});

test('n3s_password_verify は hash:: 形式のハッシュを正しいパスワードで検証できる', function () {
    $stored = n3s_password_hash('correct-password');

    expect(n3s_password_verify('correct-password', $stored))->toBeTrue()
        ->and(n3s_password_verify('wrong-password', $stored))->toBeFalse();
});

test('n3s_password_verify は旧方式 (salt:: 形式) のハッシュも検証できる (後方互換)', function () {
    $salt = n3s_generate_salt();
    $stored = n3s_login_password_to_hash('legacy-password', $salt);

    expect(n3s_password_verify('legacy-password', $stored, $salt))->toBeTrue()
        ->and(n3s_password_verify('wrong-password', $stored, $salt))->toBeFalse();
});

test('n3s_password_verify は旧方式 (def:: 形式、salt空) のハッシュも検証できる (後方互換)', function () {
    $stored = n3s_login_password_to_hash('legacy-password', '');

    expect(n3s_password_verify('legacy-password', $stored, ''))->toBeTrue()
        ->and(n3s_password_verify('wrong-password', $stored, ''))->toBeFalse();
});

test('n3s_password_needs_upgrade は旧方式 (def::/salt::) のハッシュに対して true を返す', function () {
    $defHash = n3s_login_password_to_hash('mypassword', '');
    $saltHash = n3s_login_password_to_hash('mypassword', n3s_generate_salt());

    expect(n3s_password_needs_upgrade($defHash))->toBeTrue()
        ->and(n3s_password_needs_upgrade($saltHash))->toBeTrue();
});

test('n3s_password_needs_upgrade は現行コストのhash::形式に対して false を返す', function () {
    $stored = n3s_password_hash('mypassword');

    expect(n3s_password_needs_upgrade($stored))->toBeFalse();
});
