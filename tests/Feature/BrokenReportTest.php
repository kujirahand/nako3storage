<?php
// tests/Feature/BrokenReportTest.php
// 「作品が動かない」報告 (#267) のテスト。
// 実行画面(widget)はサンドボックスオリジンで表示されログイン状態が分からないため、
// 報告ボタンは常に表示し、本体オリジンの確認ページ(action=broken)で報告する。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/broken.inc.php';

function n3s_test_broken_login(int $userId): void
{
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['n3s_login_info'] = ['user_id' => $userId, 'name' => '報告者', 'email' => 'broken@example.com'];
}

function n3s_test_broken_app(): int
{
    $now = time();
    return (int) db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['動かない作品', '作者', 0, 0, 'wnako', $now, $now]
    );
}

test('実行画面の報告ボタンはログイン判定なしで本体オリジンの確認ページへリンクする', function () {
    $template = file_get_contents(N3S_TEST_ROOT . '/app/template/widget_frame.html');
    expect($template)
        ->toContain('{{ $app_root_url }}index.php?action=broken&amp;page={{ $app_id }}')
        ->not->toContain('{{ if n3s_is_login() }}');
});

test('未ログインでは確認ページにログイン案内を表示し、件数は増えない', function () {
    $appId = n3s_test_broken_app();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_REQUEST = ['page' => (string) $appId, 'q' => 'up'];
    $out = n3s_test_capture(fn() => n3s_web_broken());
    expect($out)->toContain('ログインが必要です');
    $r = db_get1('SELECT broken_report FROM apps WHERE app_id=?', [$appId]);
    expect(intval($r['broken_report']))->toBe(0);
});

test('ログイン時はGETで確認フォームを表示し、POST+edit_tokenで報告できる', function () {
    $appId = n3s_test_broken_app();
    n3s_add_user('admin@example.com', 'password123', '管理者'); // user_id=1 は管理者
    $userId = n3s_add_user('broken@example.com', 'password123', '報告者');
    n3s_test_broken_login((int) $userId);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_REQUEST = ['page' => (string) $appId];
    $out = n3s_test_capture(fn() => n3s_web_broken());
    expect($out)->toContain('報告する')->toContain('動かない作品');

    // トークンなしのPOSTは拒否
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    $_REQUEST = ['page' => (string) $appId, 'q' => 'up'];
    $out = n3s_test_capture(fn() => n3s_web_broken());
    expect($out)->toContain('報告に失敗しました');
    expect(intval(db_get1('SELECT broken_report FROM apps WHERE app_id=?', [$appId])['broken_report']))->toBe(0);

    // 正しいトークンで報告
    $_REQUEST = ['page' => (string) $appId, 'q' => 'up', 'edit_token' => n3s_getEditToken()];
    $out = n3s_test_capture(fn() => n3s_web_broken());
    expect($out)->toContain('報告しました');
    expect(intval(db_get1('SELECT broken_report FROM apps WHERE app_id=?', [$appId])['broken_report']))->toBe(1);

    // 同じIPからの再報告は加算しない
    $_REQUEST = ['page' => (string) $appId, 'q' => 'up', 'edit_token' => n3s_getEditToken()];
    n3s_test_capture(fn() => n3s_web_broken());
    expect(intval(db_get1('SELECT broken_report FROM apps WHERE app_id=?', [$appId])['broken_report']))->toBe(1);
});
