<?php
// tests/Unit/SessionCookieParamsTest.php
// todo-security.md #7:
// セッションクッキーの httponly/secure/samesite 属性を決める n3s_session_cookie_params() を検証する。
// 以前は session_start() 前に何も設定しておらず、php.ini 依存だった。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/n3s_lib.inc.php';

test('httponly は常に true (XSS発生時のクッキー窃取対策)', function () {
    unset($_SERVER['HTTPS']);
    expect(n3s_session_cookie_params()['httponly'])->toBeTrue();
});

test('samesite は Lax (OAuthコールバックのトップレベル遷移でも送られる必要があるため Strict にはしない)', function () {
    unset($_SERVER['HTTPS']);
    expect(n3s_session_cookie_params()['samesite'])->toBe('Lax');
});

test('HTTPS接続でなければ secure は false (ローカルhttp開発を壊さない)', function () {
    unset($_SERVER['HTTPS']);
    expect(n3s_session_cookie_params()['secure'])->toBeFalse();

    $_SERVER['HTTPS'] = 'off';
    expect(n3s_session_cookie_params()['secure'])->toBeFalse();
    unset($_SERVER['HTTPS']);
});

test('HTTPS接続なら secure は true', function () {
    $_SERVER['HTTPS'] = 'on';
    expect(n3s_session_cookie_params()['secure'])->toBeTrue();
    unset($_SERVER['HTTPS']);
});

test('path は /、domainは既定値(空文字=現在のドメイン)', function () {
    unset($_SERVER['HTTPS']);
    $p = n3s_session_cookie_params();
    expect($p['path'])->toBe('/');
    expect($p['domain'])->toBe('');
});
