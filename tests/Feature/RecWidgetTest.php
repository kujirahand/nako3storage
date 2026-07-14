<?php
// tests/Feature/RecWidgetTest.php
// n3s_api_rec_widget() の統合テスト (Issue #217 / P1 security fix)
//
// テスト対象:
//  - 公開作品は count が記録される
//  - 非公開 (is_private=1) は editkey 不問でカウントされない
//  - 限定公開 (is_private=2) は editkey なしではカウントされない (P1 修正の回帰防止)
//  - 限定公開は正しい editkey があればカウントされる
//  - ログイン投稿オーナー本人の実行はカウントされない (テスト実行除外)
//  - 匿名投稿 (user_id=0) はオーナー特定不可のため全件カウントする
//  - app_id が不正 / 存在しないときはエラーなく空レスポンスを返す

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/n3s_lib.inc.php';
require_once N3S_TEST_ROOT . '/app/action/rec_widget.inc.php';

// ------------------------------------------------
// ヘルパー
// ------------------------------------------------

/** access_stats から当日の widget カウントを取得する */
function _rw_widget_count(int $app_id): int
{
    $date = date('Y-m-d');
    $row = db_get1(
        'SELECT count FROM access_stats WHERE date=? AND kind=? AND app_id=?',
        [$date, 'widget', $app_id],
        'log'
    );
    return $row ? (int)$row['count'] : 0;
}

/** apps テーブルにテスト用の作品を挿入して app_id を返す */
function _rw_insert_app($user_id, int $is_private, string $editkey = ''): int
{
    $user_id = intval($user_id); // n3s_add_user() は string を返す場合がある
    return (int)db_insert(
        'INSERT INTO apps (title, user_id, is_private, editkey, ctime) VALUES (?,?,?,?,?)',
        ['テスト作品', $user_id, $is_private, $editkey, time()]
    );
}

/** $_GET を設定して n3s_api_rec_widget() を呼び、JSON 文字列を返す */
function _rw_call(int $app_id, string $editkey = ''): string
{
    $_GET['page'] = (string)$app_id;
    if ($editkey !== '') {
        $_GET['editkey'] = $editkey;
    } else {
        unset($_GET['editkey']);
    }
    return n3s_test_capture(fn () => n3s_api_rec_widget());
}

// ------------------------------------------------
// テスト
// ------------------------------------------------

test('公開作品 (is_private=0) は editkey なしでカウントされる', function () {
    $app_id = _rw_insert_app(0, 0);
    _rw_call($app_id);
    expect(_rw_widget_count($app_id))->toBe(1);
});

test('非公開作品 (is_private=1) は editkey を渡してもカウントされない', function () {
    $owner_id = n3s_add_user('priv1@example.com', 'pass', '非公開オーナー');
    $app_id = _rw_insert_app($owner_id, 1, 'secret');
    _rw_call($app_id, 'secret');
    expect(_rw_widget_count($app_id))->toBe(0);
});

test('限定公開 (is_private=2) を editkey なしで呼んでもカウントされない (P1 回帰防止)', function () {
    $owner_id = n3s_add_user('limited1@example.com', 'pass', '限定公開オーナー');
    $app_id = _rw_insert_app($owner_id, 2, 'sharekey');
    _rw_call($app_id); // editkey なし
    expect(_rw_widget_count($app_id))->toBe(0);
});

test('限定公開 (is_private=2) は正しい editkey があればカウントされる', function () {
    $owner_id = n3s_add_user('limited2@example.com', 'pass', '限定公開オーナー2');
    $app_id = _rw_insert_app($owner_id, 2, 'sharekey');
    _rw_call($app_id, 'sharekey'); // 正しい editkey
    expect(_rw_widget_count($app_id))->toBe(1);
});

test('限定公開 (is_private=2) は間違った editkey ではカウントされない', function () {
    $owner_id = n3s_add_user('limited3@example.com', 'pass', '限定公開オーナー3');
    $app_id = _rw_insert_app($owner_id, 2, 'sharekey');
    _rw_call($app_id, 'wrongkey');
    expect(_rw_widget_count($app_id))->toBe(0);
});

test('ログイン投稿のオーナー本人が実行してもカウントされない (テスト実行除外)', function () {
    $owner_id = n3s_add_user('owner1@example.com', 'pass', 'オーナー');
    $app_id = _rw_insert_app($owner_id, 0);

    // オーナー本人でログイン
    n3s_web_login_execute('owner1@example.com', 'pass');
    expect(n3s_get_user_id())->toBe((int)$owner_id);

    _rw_call($app_id);
    expect(_rw_widget_count($app_id))->toBe(0);
});

test('ログイン投稿の作品でも他人の実行はカウントされる', function () {
    $owner_id = n3s_add_user('owner2@example.com', 'pass', 'オーナー2');
    $other_id  = n3s_add_user('other@example.com', 'pass', '他ユーザー');
    $app_id = _rw_insert_app($owner_id, 0);

    // 他人でログイン
    n3s_web_login_execute('other@example.com', 'pass');
    _rw_call($app_id);
    expect(_rw_widget_count($app_id))->toBe(1);
});

test('ログアウト状態でのアクセスはカウントされる（匿名ユーザー）', function () {
    $owner_id = n3s_add_user('owner3@example.com', 'pass', 'オーナー3');
    $app_id = _rw_insert_app($owner_id, 0);
    // $_SESSION を空にしてログアウト状態を再現（beforeEach でリセット済みだが念のため）
    $_SESSION = [];
    _rw_call($app_id);
    expect(_rw_widget_count($app_id))->toBe(1);
});

test('匿名投稿 (user_id=0) の作品はオーナー特定不可のため常にカウントされる', function () {
    $app_id = _rw_insert_app(0, 0); // user_id=0 = 匿名投稿
    _rw_call($app_id);
    expect(_rw_widget_count($app_id))->toBe(1);
});

test('app_id が 0 以下のときはエラーなく false レスポンスを返す', function () {
    $_GET['page'] = '0';
    $out = n3s_test_capture(fn () => n3s_api_rec_widget());
    $json = json_decode($out, true);
    expect($json['result'])->toBeFalse();
});

test('存在しない app_id を渡してもエラーなく true レスポンスを返す（情報漏洩防止）', function () {
    $out = _rw_call(99999);
    $json = json_decode($out, true);
    expect($json['result'])->toBeTrue();
    // カウントは記録されない
    expect(_rw_widget_count(99999))->toBe(0);
});

test('公開作品を複数回呼んでも日別集計として累積される（二重カウントの意図的な動作確認）', function () {
    // 同じセッション内で「実行」ボタンを複数回押した場合のサーバー側は
    // リクエストごとにカウントする（JS 側で _n3s_widget_counted フラグにより
    // 1回のページロードにつき 1 回のみ fetch することを前提とする）
    $app_id = _rw_insert_app(0, 0);
    _rw_call($app_id);
    _rw_call($app_id);
    _rw_call($app_id);
    expect(_rw_widget_count($app_id))->toBe(3);
});
