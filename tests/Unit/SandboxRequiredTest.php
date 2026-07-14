<?php
// tests/Unit/SandboxRequiredTest.php
// todo-security.md #4:
// sandbox_url(別オリジン)が未設定の場合、投稿プログラムを主オリジンで実行させないための
// 判定 n3s_is_sandbox_configured() と、案内メッセージ n3s_sandbox_not_configured_message() を検証する。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/widget_frame.inc.php';

test('sandbox_url が空なら未設定(false)と判定される', function () {
    n3s_set_config('sandbox_url', '');
    expect(n3s_is_sandbox_configured())->toBeFalse();
});

test('sandbox_url が空白のみでも未設定(false)と判定される', function () {
    n3s_set_config('sandbox_url', '   ');
    expect(n3s_is_sandbox_configured())->toBeFalse();
});

test('sandbox_url が設定されていれば true と判定される', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    expect(n3s_is_sandbox_configured())->toBeTrue();
});

test('未設定メッセージに設定手順へのリンクと指定文言が含まれる', function () {
    $msg = n3s_sandbox_not_configured_message();
    expect($msg)->toContain('貯蔵庫の管理者に連絡して');
    expect($msg)->toContain('サンドボックスURLを設定');
    // 指定されたGitHubの案内アンカーであること
    expect($msg)->toContain(
        '<a href="https://github.com/kujirahand/nako3storage#' .
        '%E5%AE%89%E5%85%A8%E3%81%AB%E9%81%8B%E7%94%A8%E3%81%99%E3%82%8B%E3%81%9F%E3%82%81%E3%81%AEtips">' .
        'サンドボックスURLを設定</a>'
    );
});

test('HTTP_HOSTが localhost(ポート付き含む)/127.0.0.1/::1 ならlocalhostリクエストと判定される', function () {
    $hosts = [
        'localhost', 'localhost:8000',
        '127.0.0.1', '127.0.0.1:8000',
        '::1', '[::1]', '[::1]:8000',
    ];
    foreach ($hosts as $host) {
        $_SERVER['HTTP_HOST'] = $host;
        expect(n3s_is_localhost_request())->toBeTrue("host={$host}");
    }
});

test('外部ホスト名はlocalhostリクエストと判定されない', function () {
    foreach (['n3s.nadesi.com', 'example.com', 'evil-localhost.com'] as $host) {
        $_SERVER['HTTP_HOST'] = $host;
        expect(n3s_is_localhost_request())->toBeFalse("host={$host}");
    }
});

test('sandbox_url未設定でもlocalhostからのアクセスならブロックされない(exitしない)', function () {
    n3s_set_config('sandbox_url', '');
    $_SERVER['HTTP_HOST'] = 'localhost:8000';

    n3s_require_sandbox_or_error();

    expect(true)->toBeTrue(); // exitせずここまで到達すればOK
});

test('sandbox_urlが設定済みなら、localhostでなくてもブロックされない(exitしない)', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    $_SERVER['HTTP_HOST'] = 'n3s.nadesi.com';

    n3s_require_sandbox_or_error();

    expect(true)->toBeTrue(); // exitせずここまで到達すればOK
});
