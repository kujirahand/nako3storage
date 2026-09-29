<?php
// tests/Unit/LoginAllowedHostTest.php
// ログインフォームを表示・ログインセッションを使えるホストの制限 (#194, docs/api.md)。
// サンドボックスは全作品が同一オリジンを共有するため、そこでログインさせると
// 任意の作品からアカウントを乗っ取れてしまう。

declare(strict_types=1);

test('app_root_url のホストと localhost ではログインできる', function () {
    n3s_set_config('app_root_url', 'https://main.example.com/');
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');

    $_SERVER['HTTP_HOST'] = 'main.example.com';
    expect(n3s_login_allowed_host())->toBeTrue();
    $_SERVER['HTTP_HOST'] = 'localhost:8000';
    expect(n3s_login_allowed_host())->toBeTrue();
});

test('sandbox_url のホストや無関係なホストではログインできない', function () {
    n3s_set_config('app_root_url', 'https://main.example.com/');
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');

    $_SERVER['HTTP_HOST'] = 'sandbox.example.com';
    expect(n3s_login_allowed_host())->toBeFalse();
    $_SERVER['HTTP_HOST'] = 'evil.example.com';
    expect(n3s_login_allowed_host())->toBeFalse();
});

test('login_allowed_hosts を設定するとそのホストだけ許可する(localhostも明示が必要)', function () {
    n3s_set_config('app_root_url', 'https://main.example.com/');
    n3s_set_config('login_allowed_hosts', ['www.example.com', 'Main.Example.com']);

    $_SERVER['HTTP_HOST'] = 'www.example.com';
    expect(n3s_login_allowed_host())->toBeTrue();
    $_SERVER['HTTP_HOST'] = 'main.example.com';
    expect(n3s_login_allowed_host())->toBeTrue();
    $_SERVER['HTTP_HOST'] = 'localhost';
    expect(n3s_login_allowed_host())->toBeFalse();
});

test('login_allowed_hosts に sandbox のホストを書いても許可しない', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    n3s_set_config('login_allowed_hosts', ['sandbox.example.com']);

    $_SERVER['HTTP_HOST'] = 'sandbox.example.com';
    expect(n3s_login_allowed_host())->toBeFalse();
});

test('許可されていないホストに残ったログインセッションは未ログイン扱いになる', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = 3;

    $_SERVER['HTTP_HOST'] = 'localhost';
    expect(n3s_is_login())->toBeTrue()
        ->and(n3s_get_user_id())->toBe(3);

    $_SERVER['HTTP_HOST'] = 'sandbox.example.com';
    expect(n3s_is_login())->toBeFalse()
        ->and(n3s_get_user_id())->toBe(0);
});

test('許可されていないホストではログインフォームを出さず、本体のログインページへ案内する', function () {
    n3s_set_config('app_root_url', 'https://main.example.com/');
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    $_SERVER['HTTP_HOST'] = 'sandbox.example.com';

    $out = n3s_test_capture(fn () => n3s_web_login());

    expect($out)->toContain('このページではログインできません')
        ->toContain('https://main.example.com/index.php?action=login')
        ->not->toContain('type="password"');
});
