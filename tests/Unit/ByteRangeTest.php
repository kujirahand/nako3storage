<?php
// tests/Unit/ByteRangeTest.php
// todo-security.md #3:
// image.php が音声を HTTP Range 対応で配信する際のレンジ解釈 n3s_parse_byte_range() を検証する。
// (images/ への直接アクセスを禁止し、Webサーバー任せのリダイレクトを廃止したための実装)

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/image.inc.php';

test('Range指定なしは null(全体配信)', function () {
    expect(n3s_parse_byte_range('', 1000))->toBeNull();
});

test('サイズ0や不正な形式は null(全体配信)', function () {
    expect(n3s_parse_byte_range('bytes=0-99', 0))->toBeNull();
    expect(n3s_parse_byte_range('bytes=abc', 1000))->toBeNull();
    // 複数レンジは未対応 → 全体を返す
    expect(n3s_parse_byte_range('bytes=0-99,200-299', 1000))->toBeNull();
});

test('通常のレンジ bytes=START-END を解釈する', function () {
    expect(n3s_parse_byte_range('bytes=0-99', 1000))->toBe([0, 99]);
    expect(n3s_parse_byte_range('bytes=100-199', 1000))->toBe([100, 199]);
});

test('終端省略 bytes=START- はファイル末尾まで', function () {
    expect(n3s_parse_byte_range('bytes=100-', 1000))->toBe([100, 999]);
});

test('末尾Nバイト指定 bytes=-N を解釈する', function () {
    expect(n3s_parse_byte_range('bytes=-100', 1000))->toBe([900, 999]);
});

test('終端がサイズを超える場合は末尾までにクランプされる', function () {
    expect(n3s_parse_byte_range('bytes=500-5000', 1000))->toBe([500, 999]);
});

test('末尾指定がサイズを超える場合は全体になる', function () {
    expect(n3s_parse_byte_range('bytes=-5000', 1000))->toBe([0, 999]);
});

test('満たせないレンジは false(416相当)', function () {
    // 開始がファイルサイズ以上
    expect(n3s_parse_byte_range('bytes=1000-', 1000))->toBeFalse();
    expect(n3s_parse_byte_range('bytes=2000-3000', 1000))->toBeFalse();
    // 開始 > 終了
    expect(n3s_parse_byte_range('bytes=500-499', 1000))->toBeFalse();
    // "bytes=-" や "bytes=-0"
    expect(n3s_parse_byte_range('bytes=-', 1000))->toBeFalse();
    expect(n3s_parse_byte_range('bytes=-0', 1000))->toBeFalse();
});
