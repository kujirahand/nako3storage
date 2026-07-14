<?php
// tests/Unit/PrivateAccessAllowedTest.php
// todo-security.md #6:
// show.inc.php / widget_frame.inc.php で重複していた「非公開・限定公開の閲覧可否判定」を
// n3s_lib.inc.php の n3s_private_access_allowed() に共通化した。
// 以前 widget_frame.inc.php は保存時に指定される editkey ではなく、常に未使用のまま
// 空文字が入っている access_key カラムを見ていたため、限定公開の作品が実質誰でも
// 閲覧できてしまっていた(空文字同士が一致してしまう)。その回帰を防ぐ。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/n3s_lib.inc.php';

test('公開作品(is_private=0)は誰でも閲覧できる', function () {
    $a = ['is_private' => 0, 'user_id' => 5, 'editkey' => 'secret'];
    expect(n3s_private_access_allowed($a, ''))->toBeTrue();
});

test('管理者は非公開・限定公開ともにキー無しで閲覧できる', function () {
    n3s_add_user('admin@example.com', 'password', '管理者'); // user_id=1 (admin_users=[1])
    n3s_web_login_execute('admin@example.com', 'password');

    $private = ['is_private' => 1, 'user_id' => 999, 'editkey' => ''];
    $limited = ['is_private' => 2, 'user_id' => 999, 'editkey' => 'secret'];
    expect(n3s_private_access_allowed($private, ''))->toBeTrue();
    expect(n3s_private_access_allowed($limited, ''))->toBeTrue();
});

test('匿名投稿(user_id=0)は editkey が一致したときだけ閲覧できる (以前のバグの回帰防止)', function () {
    // access_key(常に空)ではなく editkey を見ること、かつ「両方空だから一致」で
    // 突破できないことを確認する。
    $a = ['is_private' => 2, 'user_id' => 0, 'editkey' => 'mykey123'];
    expect(n3s_private_access_allowed($a, ''))->toBeFalse();          // キー無し → 不可
    expect(n3s_private_access_allowed($a, 'wrong'))->toBeFalse();     // 不一致 → 不可
    expect(n3s_private_access_allowed($a, 'mykey123'))->toBeTrue();   // 一致 → 可

    // editkeyが空のまま保存された匿名投稿は、キー無しの閲覧のみ許可される(既存挙動を踏襲)
    $noKey = ['is_private' => 2, 'user_id' => 0, 'editkey' => ''];
    expect(n3s_private_access_allowed($noKey, ''))->toBeTrue();
    expect(n3s_private_access_allowed($noKey, 'anything'))->toBeFalse();
});

test('ログインユーザーの非公開(1)は本人以外、editkeyがあっても閲覧できない', function () {
    $owner_id = n3s_add_user('owner1@example.com', 'password', 'オーナー1');
    $a = ['is_private' => 1, 'user_id' => $owner_id, 'editkey' => 'secret'];

    // 誰もログインしていない
    expect(n3s_private_access_allowed($a, 'secret'))->toBeFalse();

    // 別のユーザーでログイン
    n3s_add_user('other@example.com', 'password', '他人');
    n3s_web_login_execute('other@example.com', 'password');
    expect(n3s_private_access_allowed($a, 'secret'))->toBeFalse();
});

test('ログインユーザーの非公開(1)は本人ならキー無しで閲覧できる', function () {
    $owner_id = n3s_add_user('owner2@example.com', 'password', 'オーナー2');
    n3s_web_login_execute('owner2@example.com', 'password');
    $a = ['is_private' => 1, 'user_id' => $owner_id, 'editkey' => ''];
    expect(n3s_private_access_allowed($a, ''))->toBeTrue();
});

test('ログインユーザーの限定公開(2)は editkey が一致すれば第三者でも閲覧できる', function () {
    $owner_id = n3s_add_user('owner3@example.com', 'password', 'オーナー3');
    $a = ['is_private' => 2, 'user_id' => $owner_id, 'editkey' => 'sharekey'];

    // 未ログインの第三者
    expect(n3s_private_access_allowed($a, 'sharekey'))->toBeTrue();
    expect(n3s_private_access_allowed($a, 'wrong'))->toBeFalse();
    expect(n3s_private_access_allowed($a, ''))->toBeFalse();
});

test('存在しない作品(false/null)は閲覧不可', function () {
    expect(n3s_private_access_allowed(false, 'any'))->toBeFalse();
    expect(n3s_private_access_allowed(null, 'any'))->toBeFalse();
});
