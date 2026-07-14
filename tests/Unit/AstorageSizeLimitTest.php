<?php
// tests/Unit/AstorageSizeLimitTest.php
// todo-security.md #8:
// アプリ内ストレージAPI(app/action/api.inc.php)の書き込み系エンドポイントに追加した
// key/value のサイズ上限判定 n3s_astorage_key_size_ok() / n3s_astorage_value_size_ok() を検証する。
// 以前はサイズ制限が無く、ログインユーザーが巨大な文字列を繰り返し保存することで
// SQLiteファイルを際限なく肥大化させるストレージ枯渇DoSが可能だった。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/api.inc.php';

test('デフォルト設定でのkey上限は256バイト', function () {
    expect(n3s_astorage_key_size_ok(str_repeat('a', 256)))->toBeTrue();
    expect(n3s_astorage_key_size_ok(str_repeat('a', 257)))->toBeFalse();
});

test('デフォルト設定でのvalue上限は64KB', function () {
    $max = 1024 * 64;
    expect(n3s_astorage_value_size_ok(str_repeat('a', $max)))->toBeTrue();
    expect(n3s_astorage_value_size_ok(str_repeat('a', $max + 1)))->toBeFalse();
});

test('空のkey/valueは常に許可される', function () {
    expect(n3s_astorage_key_size_ok(''))->toBeTrue();
    expect(n3s_astorage_value_size_ok(''))->toBeTrue();
});

test('設定値(size_astorage_key_max / size_astorage_value_max)を変更すると判定に反映される', function () {
    global $n3s_config;
    $n3s_config['size_astorage_key_max'] = 10;
    $n3s_config['size_astorage_value_max'] = 20;

    expect(n3s_astorage_key_size_ok(str_repeat('a', 10)))->toBeTrue();
    expect(n3s_astorage_key_size_ok(str_repeat('a', 11)))->toBeFalse();
    expect(n3s_astorage_value_size_ok(str_repeat('a', 20)))->toBeTrue();
    expect(n3s_astorage_value_size_ok(str_repeat('a', 21)))->toBeFalse();
});

test('マルチバイト文字はバイト数で判定される(文字数ではない)', function () {
    global $n3s_config;
    $n3s_config['size_astorage_value_max'] = 10;
    // "あ" はUTF-8で3バイト。4文字で12バイト → 上限10バイトを超える
    expect(n3s_astorage_value_size_ok(str_repeat('あ', 4)))->toBeFalse();
    expect(n3s_astorage_value_size_ok(str_repeat('あ', 3)))->toBeTrue(); // 9バイト
});
