<?php
// tests/Unit/PlainContentTypeTest.php
// todo-security.md #2:
// plain アクション(app/action/plain.inc.php)がユーザー投稿本文をインライン配信する際、
// スクリプトが実行され得る MIME 型を text/plain に落とすことを検証する。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/plain.inc.php';

test('危険な型(svg)はインライン配信で text/plain に落とされる (Stored XSS防止)', function () {
    // 修正前は image/svg+xml で配信され、SVG内スクリプトが主オリジンで実行できた
    expect(n3s_plain_safe_content_type('svg'))->toBe('text/plain; charset=utf-8');
});

test('html/xml/js も安全でないため text/plain になる', function () {
    expect(n3s_plain_safe_content_type('html'))->toBe('text/plain; charset=utf-8');
    expect(n3s_plain_safe_content_type('xml'))->toBe('text/plain; charset=utf-8');
    expect(n3s_plain_safe_content_type('js'))->toBe('text/plain; charset=utf-8');
});

test('wnako/cnako は text/plain で配信される', function () {
    expect(n3s_plain_safe_content_type('wnako'))->toBe('text/plain; charset=utf-8');
    expect(n3s_plain_safe_content_type('cnako'))->toBe('text/plain; charset=utf-8');
});

test('安全と分かっている型(csv/tsv/json)は本来のMIMEを保持する', function () {
    expect(n3s_plain_safe_content_type('csv'))->toBe('text/csv; charset=utf-8');
    expect(n3s_plain_safe_content_type('tsv'))->toBe('text/tsv; charset=utf-8');
    expect(n3s_plain_safe_content_type('json'))->toBe('application/json; charset=utf-8');
});

test('未知の型は text/plain にフォールバックする', function () {
    expect(n3s_plain_safe_content_type('unknown_type_xyz'))->toBe('text/plain; charset=utf-8');
});
