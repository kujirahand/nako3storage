<?php
// tests/Unit/SandboxOriginTest.php
// todo-security.md #4 追加対応:
// sandbox_url が設定済みであっても、主オリジンへ直接
// index.php?action=widget_frame&page=<id> でアクセスすることでサンドボックス分離を
// 迂回でき、保存済み nakotype=html 等の本文が主オリジンで生出力できてしまっていた問題の修正。
// n3s_is_sandbox_configured() は「設定されているか」しか見ておらず、
// widget_frame の実行前に「現在のリクエストが実際にそのsandboxオリジンから来ているか」
// までは検証していなかった。n3s_is_request_from_sandbox_origin() と
// n3s_require_widget_frame_origin_or_error() でこれを検証する。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/widget_frame.inc.php';

test('n3s_current_origin はスキームとホスト(ポート込み)を返す', function () {
    unset($_SERVER['HTTPS']);
    $_SERVER['HTTP_HOST'] = 'localhost:7450';
    expect(n3s_current_origin())->toBe('http://localhost:7450');

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'main.example';
    expect(n3s_current_origin())->toBe('https://main.example');
    unset($_SERVER['HTTPS']);
});

test('n3s_sandbox_origin は sandbox_url未設定なら空文字を返す', function () {
    n3s_set_config('sandbox_url', '');
    expect(n3s_sandbox_origin())->toBe('');
});

test('n3s_sandbox_origin は設定済みURLから scheme://host(ポート込み) を取り出す', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    expect(n3s_sandbox_origin())->toBe('https://sandbox.example.com');

    n3s_set_config('sandbox_url', 'https://sandbox.example.com:8443/some/path');
    expect(n3s_sandbox_origin())->toBe('https://sandbox.example.com:8443');
});

test('n3s_sandbox_origin は scheme が無い不正なURLに対して空文字を返す(フェイルクローズ)', function () {
    n3s_set_config('sandbox_url', 'sandbox.example.com');
    expect(n3s_sandbox_origin())->toBe('');
});

test('sandbox_url未設定なら、どんなリクエストも sandboxオリジンとは判定されない', function () {
    n3s_set_config('sandbox_url', '');
    $_SERVER['HTTP_HOST'] = 'main.example';
    unset($_SERVER['HTTPS']);
    expect(n3s_is_request_from_sandbox_origin())->toBeFalse();
});

test('主オリジンから直接アクセスした場合、sandboxオリジンとは判定されない (今回のバグの回帰防止)', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'main.example'; // 主オリジンから直接アクセス
    expect(n3s_is_request_from_sandbox_origin())->toBeFalse();
    unset($_SERVER['HTTPS']);
});

test('sandboxオリジンと完全一致するリクエストのみ許可される', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'sandbox.example.com';
    expect(n3s_is_request_from_sandbox_origin())->toBeTrue();
    unset($_SERVER['HTTPS']);
});

test('スキームが違えば(http vs https)sandboxオリジンとは判定されない', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    unset($_SERVER['HTTPS']); // http でアクセス
    $_SERVER['HTTP_HOST'] = 'sandbox.example.com';
    expect(n3s_is_request_from_sandbox_origin())->toBeFalse();
});

test('ポート番号が違えばsandboxオリジンとは判定されない', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com:8443/');
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'sandbox.example.com'; // ポート指定なし(デフォルト443扱い)
    expect(n3s_is_request_from_sandbox_origin())->toBeFalse();
    unset($_SERVER['HTTPS']);
});

test('sandboxオリジンからの正当なリクエストは n3s_require_widget_frame_origin_or_error() をブロックしない(exitしない)', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'sandbox.example.com';

    n3s_require_widget_frame_origin_or_error();

    expect(true)->toBeTrue(); // exitせずここまで到達すればOK
    unset($_SERVER['HTTPS']);
});

test('sandbox_url未設定・localhostからのリクエストは n3s_require_widget_frame_origin_or_error() をブロックしない(exitしない)', function () {
    n3s_set_config('sandbox_url', '');
    unset($_SERVER['HTTPS']);
    $_SERVER['HTTP_HOST'] = 'localhost:8000';

    n3s_require_widget_frame_origin_or_error();

    expect(true)->toBeTrue(); // exitせずここまで到達すればOK
});
