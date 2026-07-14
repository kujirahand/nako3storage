<?php
// API経由でのログイン・ログアウトは禁止されている (docs/user_login.md #4.6, #9)

test('n3s_api_login はAPI経由のログインを常に拒否する', function () {
    $out = n3s_test_capture(fn () => n3s_api_login());
    $data = json_decode($out, true);

    expect($data['result'])->toBe('ng')
        ->and($data['msg'])->toBe('should use web access');
});

test('n3s_api_logout はAPI経由のログアウトを常に拒否する', function () {
    $out = n3s_test_capture(fn () => n3s_api_logout());
    $data = json_decode($out, true);

    expect($data['result'])->toBe('ng')
        ->and($data['msg'])->toBe('should use web access');
});
