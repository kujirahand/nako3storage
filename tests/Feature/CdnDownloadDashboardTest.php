<?php
// CDNダウンロード統計ダッシュボード (Issue #252)

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/dlcounter.inc.php';

test('CDNダウンロード統計は管理者だけが閲覧できる', function () {
    $guest_html = n3s_test_capture(function () {
        n3s_web_dlcounter();
    });
    expect($guest_html)->toContain('管理者専用のページです');

    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = 1;
    $admin_html = n3s_test_capture(function () {
        n3s_web_dlcounter();
    });

    expect($admin_html)->toContain('CDNダウンロード統計')
        ->and($admin_html)->toContain('直近7日間')
        ->and($admin_html)->toContain('月間ダウンロード数')
        ->and($admin_html)->toContain('年間ダウンロード数')
        ->and($admin_html)->toContain('月別ダウンロード推移')
        ->and($admin_html)->toContain('週別ダウンロード推移')
        ->and($admin_html)->toContain('wnako3.js')
        ->and($admin_html)->toContain('使用バージョン')
        ->and($admin_html)->toContain('ダウンロードされたファイル');
});
