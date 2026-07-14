<?php

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
