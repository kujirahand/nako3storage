<?php

test('n3s_getEditToken は64文字の16進トークンを生成しセッションに保存する', function () {
    $token = n3s_getEditToken('default');

    expect($token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($_SESSION['n3s_edit_token_default'])->toBe($token);
});

test('n3s_getEditToken は同一リクエスト内では同じトークンを返す', function () {
    $first = n3s_getEditToken('default');
    $second = n3s_getEditToken('default');

    expect($second)->toBe($first);
});

test('n3s_getEditToken(update:false) はセッションに既存トークンが無ければ新規発行する', function () {
    $token = n3s_getEditToken('setpw', false);

    expect($token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($_SESSION['n3s_edit_token_setpw'])->toBe($token);
});

test('n3s_getEditToken(update:false) はセッションに既存トークンがあればそれを再利用する', function () {
    $_SESSION['n3s_edit_token_setpw'] = 'preset-token-value';

    $token = n3s_getEditToken('setpw', false);

    expect($token)->toBe('preset-token-value');
});

test('n3s_checkEditToken はセッションとリクエストのトークンが一致すればtrue', function () {
    $token = n3s_getEditToken('default');
    $_REQUEST['edit_token'] = $token;

    expect(n3s_checkEditToken('default'))->toBeTrue();
});

test('n3s_checkEditToken はトークンが不一致ならfalse', function () {
    n3s_getEditToken('default');
    $_REQUEST['edit_token'] = 'invalid-token';

    expect(n3s_checkEditToken('default'))->toBeFalse();
});

test('n3s_checkEditToken はセッションにトークンが無ければfalse', function () {
    $_REQUEST['edit_token'] = 'anything';

    expect(n3s_checkEditToken('default'))->toBeFalse();
});

test('異なるキーで連続して発行すると、後発行分がセッションに保存されない (既知の不具合)', function () {
    // n3s_getEditToken() は $update=true のとき、トークンのキャッシュを
    // $n3s_config['edit_token'] という単一の変数に持っている(キーごとに
    // 分かれていない)。そのため同一リクエスト内で異なる $key を渡して連続で
    // 呼び出すと、2回目以降は既にキャッシュがあるとみなされて新規発行がスキップされ、
    // $_SESSION["n3s_edit_token_<2回目以降のkey>"] が一切書き込まれない。
    // 例えば login → register → setpw と複数のフォームを続けて発行するようなケースで
    // 想定外のトークン共有・未設定が起きうる。現状の挙動として固定化しておく。
    $defaultToken = n3s_getEditToken('default');
    $setpwToken = n3s_getEditToken('setpw');

    expect($setpwToken)->toBe($defaultToken) // 新規発行されず、defaultのキャッシュを使い回す
        ->and($_SESSION)->not->toHaveKey('n3s_edit_token_setpw');
});
