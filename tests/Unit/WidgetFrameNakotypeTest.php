<?php
// tests/Unit/WidgetFrameNakotypeTest.php
// todo-security.md #1:
// widget_frame(app/action/widget_frame.inc.php)が本文を生MIMEで直接配信する際に使う
// nakotype は、必ずDB保存値を使い $_GET['nakotype'] の上書きを受け付けないことを検証する。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/widget_frame.inc.php';

test('GETのnakotypeでは上書きできず、保存値(wnako)が使われる (未認証XSS防止)', function () {
    // 攻撃者が ?nakotype=html を付けても…
    $_GET['nakotype'] = 'html';
    // …保存された作品は wnako(本文に任意テキストを保存可能)
    $a = ['nakotype' => 'wnako', 'body' => '<script>alert(1)</script>'];

    // 解決される nakotype は保存値の wnako であること
    expect(n3s_widget_frame_nakotype($a))->toBe('wnako');
    // wnako は生出力の対象外 → 本文が text/html として echo されない
    expect(n3s_widget_frame_is_raw_type(n3s_widget_frame_nakotype($a)))->toBeFalse();
});

test('保存されたnakotypeがhtmlのときだけ生出力の対象になる', function () {
    $_GET['nakotype'] = 'wnako'; // GET側は無視される
    $a = ['nakotype' => 'html', 'body' => '...'];
    expect(n3s_widget_frame_nakotype($a))->toBe('html');
    expect(n3s_widget_frame_is_raw_type('html'))->toBeTrue();
});

test('nakotypeが空・未設定のときは wnako にフォールバックする', function () {
    expect(n3s_widget_frame_nakotype(['nakotype' => '']))->toBe('wnako');
    expect(n3s_widget_frame_nakotype([]))->toBe('wnako');
});

test('nakotypeの記号はサニタイズで除去される', function () {
    // 英数字・_・- 以外は取り除かれる
    expect(n3s_widget_frame_nakotype(['nakotype' => 'j<s>']))->toBe('js');
    expect(n3s_widget_frame_nakotype(['nakotype' => 'wn ako/']))->toBe('wnako');
});

test('生出力の対象種別は html/text/js/csv/json に限られる', function () {
    foreach (['html', 'text', 'js', 'csv', 'json'] as $t) {
        expect(n3s_widget_frame_is_raw_type($t))->toBeTrue();
    }
    foreach (['wnako', 'cnako', 'svg', 'png', 'txt'] as $t) {
        expect(n3s_widget_frame_is_raw_type($t))->toBeFalse();
    }
});
