<?php
// tests/Unit/AstorageTokenTest.php
// 貯蔵庫API用の署名付き実行トークン (#194, docs/api.md)。
// app_id と user_id をセッションに頼らず検証できること、改ざん・期限切れを拒否することを確認する。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/widget.inc.php';

test('発行したトークンを検証すると app_id と user_id が取り出せる', function () {
    $token = n3s_astorage_token_create(123, 45);
    $ctx = n3s_astorage_token_verify($token);

    expect($ctx['app_id'])->toBe(123)
        ->and($ctx['user_id'])->toBe(45)
        ->and($ctx['expire'])->toBeGreaterThan(time());
});

test('app_id が 0 以下(未保存の作品)ならトークンを発行しない', function () {
    expect(n3s_astorage_token_create(0, 1))->toBe('');
    expect(n3s_astorage_token_create(-1, 1))->toBe('');
});

test('ペイロードを書き換えて別の app_id を名乗ったトークンは拒否される', function () {
    $token = n3s_astorage_token_create(1, 2);
    [, $sig] = explode('.', $token);
    $forged = n3s_base64url_encode(json_encode(['a' => 999, 'u' => 2, 'e' => time() + 3600]));

    expect(n3s_astorage_token_verify("$forged.$sig"))->toBeNull();
});

test('署名を改ざんしたトークンや形式が不正なトークンは拒否される', function () {
    $token = n3s_astorage_token_create(1, 2);
    [$payload] = explode('.', $token);

    expect(n3s_astorage_token_verify("$payload.AAAA"))->toBeNull();
    expect(n3s_astorage_token_verify(''))->toBeNull();
    expect(n3s_astorage_token_verify('abc'))->toBeNull();
    expect(n3s_astorage_token_verify(['x']))->toBeNull();
    // 旧方式(セッション紐づけのランダム文字列)のトークンも受け付けない
    expect(n3s_astorage_token_verify(bin2hex(random_bytes(16))))->toBeNull();
});

test('期限切れのトークンは拒否される', function () {
    $token = n3s_astorage_token_create(1, 2, -10);
    expect(n3s_astorage_token_verify($token))->toBeNull();
});

test('署名鍵は未設定なら info テーブルに生成・保存され、再利用される', function () {
    $s1 = n3s_astorage_token_secret();
    $s2 = n3s_astorage_token_secret();

    expect(strlen($s1))->toBe(64)
        ->and($s2)->toBe($s1)
        ->and(n3s_getInfoTag('astorage_token_secret'))->toBe($s1);
});

test('署名鍵が変わると以前のトークンは無効になる', function () {
    n3s_set_config('astorage_token_secret', str_repeat('a', 32));
    $token = n3s_astorage_token_create(1, 2);
    expect(n3s_astorage_token_verify($token))->not->toBeNull();

    n3s_set_config('astorage_token_secret', str_repeat('b', 32));
    expect(n3s_astorage_token_verify($token))->toBeNull();
});

test('widget のトークンは ui=1 のときだけログイン中の user_id を含む', function () {
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = 7;
    $a = ['app_id' => 10];

    expect(n3s_astorage_token_verify(n3s_widget_api_token($a, 1))['user_id'])->toBe(7);
    // iframe埋め込み用(ui=0)はゲスト扱い
    expect(n3s_astorage_token_verify(n3s_widget_api_token($a, 0))['user_id'])->toBe(0);
});

test('widget_frame へ直接リダイレクトする互換作品(991)は ui=1 でもゲストのトークンになる', function () {
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = 7;

    expect(n3s_widget_is_redirect_app(991))->toBeTrue();
    expect(n3s_astorage_token_verify(n3s_widget_api_token(['app_id' => 991], 1))['user_id'])->toBe(0);
});

test('サンドボックスのホストでは ui=1 でもゲストのトークンになる', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    $_SERVER['HTTP_HOST'] = 'sandbox.example.com';
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = 7;

    expect(n3s_astorage_token_verify(n3s_widget_api_token(['app_id' => 10], 1))['user_id'])->toBe(0);
});
