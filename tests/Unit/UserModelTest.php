<?php

test('n3s_add_user は新しいユーザーを作成し、password_hash()形式 (hash::) で保存する', function () {
    $user_id = n3s_add_user('taro@example.com', 'plain-password', 'たろう');

    expect($user_id)->toBeGreaterThan(0);

    $row = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], 'users');
    expect($row['email'])->toBe('taro@example.com')
        ->and($row['name'])->toBe('たろう')
        // password_hash() がソルトを内包するため、users.salt 列はもう使わない
        ->and($row['salt'])->toBe('')
        ->and($row['password'])->toStartWith('hash::');

    $real_hash = substr($row['password'], strlen('hash::'));
    expect(password_verify('plain-password', $real_hash))->toBeTrue();
});

test('n3s_get_user_id_by_email は未登録メールアドレスに対して0を返す', function () {
    expect(n3s_get_user_id_by_email('nobody@example.com'))->toBe(0);
});

test('n3s_get_user_id_by_email は登録済みユーザーのIDを返す', function () {
    // n3s_add_user() は PDO::lastInsertId() 由来の文字列を返すため、int化して比較する
    $user_id = (int) n3s_add_user('hanako@example.com', 'secret1234', 'はなこ');

    expect(n3s_get_user_id_by_email('hanako@example.com'))->toBe($user_id);
});

test('同じメールアドレスのユーザーは二重登録できない (UNIQUE制約)', function () {
    n3s_add_user('dup@example.com', 'secret1234', 'ユーザーA');

    expect(fn () => n3s_add_user('dup@example.com', 'other-pass', 'ユーザーB'))
        ->toThrow(PDOException::class);
});
