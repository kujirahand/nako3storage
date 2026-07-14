<?php
// tests/Feature/WidgetPrivacyIntegrationTest.php
// todo-security.md #6:
// show.inc.php の n3s_check_private() と widget_frame.inc.php の
// n3s_widgetd_check_private() が、共通の n3s_private_access_allowed() に委譲していることを、
// 実際のラッパー関数越しに検証する。
//
// 注意: 両関数とも閲覧不可時は exit() するため(このリポジトリの既存方針: docs/tests.md /
// AGENTS.md #13、n3s_web_login_setpw() 等と同様)、プロセスを終了させない「許可される」経路のみを
// ここで検証する。拒否側の判定ロジックの網羅的な検証は tests/Unit/PrivateAccessAllowedTest.php で行う。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/show.inc.php';
require_once N3S_TEST_ROOT . '/app/action/widget_frame.inc.php';

test('公開作品は show/widget どちらの非公開チェックも通過する(exitしない)', function () {
    $a = ['app_id' => 1, 'user_id' => 0, 'is_private' => 0, 'editkey' => '', 'author' => 'テスト'];

    n3s_check_private($a, 'web', 'show');
    expect($a['result'])->toBeTrue(); // exitせずここまで到達すればOK

    // widget_frame側も同じ配列で通過すること(共通ロジックへの委譲を確認)
    n3s_widgetd_check_private($a, 'widget_frame');
    expect(true)->toBeTrue(); // exitせずここまで到達すればOK
});

test('匿名投稿の限定公開は、show/widget いずれも editkey が一致すれば通過する', function () {
    $a = [
        'app_id' => 2, 'user_id' => 0, 'is_private' => 2,
        'editkey' => 'sharedkey', 'author' => 'テスト',
    ];
    $_GET['editkey'] = 'sharedkey';

    n3s_check_private($a, 'web', 'show');
    expect($a['result'])->toBeTrue();

    n3s_widgetd_check_private($a, 'widget_frame');
    expect(true)->toBeTrue();
});

test('管理者は show/widget いずれの非公開作品もキー無しで通過できる', function () {
    n3s_add_user('admin_wp@example.com', 'password', '管理者WP'); // user_id=1
    n3s_web_login_execute('admin_wp@example.com', 'password');
    unset($_GET['editkey']);

    $a = ['app_id' => 3, 'user_id' => 999, 'is_private' => 1, 'editkey' => '', 'author' => 'テスト'];

    n3s_check_private($a, 'web', 'show');
    expect($a['result'])->toBeTrue();

    n3s_widgetd_check_private($a, 'widget_frame');
    expect(true)->toBeTrue();
});
