<?php
// tests/Feature/WidgetUiModeTest.php
// 実行画面(widget)の表示モード ui パラメータのテスト (#250)。
// ui=1 のときだけ貯蔵庫のヘッダ・フッタ付き表示、それ以外は従来の表示(iframe埋め込み用)。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/widget_frame.inc.php';

test('ui=1 のときだけ UI付きモードになる', function () {
    expect(n3s_widget_ui_mode(['ui' => '1']))->toBe(1);
});

test('ui 未指定・0・その他の値は従来の表示モードになる', function () {
    expect(n3s_widget_ui_mode([]))->toBe(0);
    expect(n3s_widget_ui_mode(['ui' => '0']))->toBe(0);
    expect(n3s_widget_ui_mode(['ui' => '2']))->toBe(0);
    expect(n3s_widget_ui_mode(['ui' => 'abc']))->toBe(0);
});

test('タグに w_noname を含む作品を判定できる', function () {
    expect(n3s_widget_is_noname(['tag' => 'ゲーム, w_noname']))->toBeTrue();
    expect(n3s_widget_is_noname(['tag' => 'ゲーム,w_nonamex']))->toBeFalse();
    expect(n3s_widget_is_noname([]))->toBeFalse();
});
