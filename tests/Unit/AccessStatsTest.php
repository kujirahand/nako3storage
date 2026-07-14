<?php
// tests/Unit/AccessStatsTest.php
// n3s_record_access() と n3s_db_migrate_access_stats() の単体テスト (Issue #217)
//
// テスト対象:
//  - 日別アップサート（同日同種別は count が加算される）
//  - 異なる日・異なる種別は独立したレコードになる
//  - app_id=0 のグローバル合計が自動で加算される
//  - n3s_db_migrate_access_stats() が既存 DB にテーブルを追加できる

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/n3s_lib.inc.php';

// ------------------------------------------------
// ヘルパー
// ------------------------------------------------

/** access_stats から 1 行取得する */
function _stats_get(string $date, string $kind, int $app_id): ?array
{
    $row = db_get1(
        'SELECT * FROM access_stats WHERE date=? AND kind=? AND app_id=?',
        [$date, $kind, $app_id],
        'log'
    );
    return $row ?: null;
}

/** access_stats の count を取得する（行がなければ 0） */
function _stats_count(string $date, string $kind, int $app_id): int
{
    $row = _stats_get($date, $kind, $app_id);
    return $row ? (int)$row['count'] : 0;
}

// ------------------------------------------------
// テスト
// ------------------------------------------------

test('n3s_record_access() は同日同種別 app_id のカウントを 1 増やす', function () {
    $date = date('Y-m-d');
    n3s_record_access('show', 10);
    expect(_stats_count($date, 'show', 10))->toBe(1);
});

test('n3s_record_access() を複数回呼ぶと count が累積される', function () {
    $date = date('Y-m-d');
    n3s_record_access('show', 20);
    n3s_record_access('show', 20);
    n3s_record_access('show', 20);
    expect(_stats_count($date, 'show', 20))->toBe(3);
});

test('n3s_record_access() は app_id=0 のグローバル合計も同時にカウントアップする', function () {
    $date = date('Y-m-d');
    n3s_record_access('show', 30);
    n3s_record_access('show', 30);
    // アプリ単位
    expect(_stats_count($date, 'show', 30))->toBe(2);
    // 全体合計
    expect(_stats_count($date, 'show', 0))->toBeGreaterThanOrEqual(2);
});

test('n3s_record_access() を app_id=0 で呼んでも全体合計を二重計上しない', function () {
    $date = date('Y-m-d');
    // app_id=0 を直接指定した場合は全体合計への自動追加はスキップされる
    n3s_record_access('widget', 0);
    n3s_record_access('widget', 0);
    expect(_stats_count($date, 'widget', 0))->toBe(2);
});

test('種別 (kind) が異なれば独立したレコードになる', function () {
    $date = date('Y-m-d');
    n3s_record_access('show',   40);
    n3s_record_access('widget', 40);
    n3s_record_access('api',    40);
    expect(_stats_count($date, 'show',   40))->toBe(1);
    expect(_stats_count($date, 'widget', 40))->toBe(1);
    expect(_stats_count($date, 'api',    40))->toBe(1);
});

test('n3s_db_migrate_access_stats() は既存 DB に access_stats テーブルを作成する', function () {
    // bootstrap で database_set() 済みの log DB を対象に再度マイグレーションを呼んでも
    // CREATE TABLE IF NOT EXISTS なので冪等であることも確認する
    n3s_db_migrate_access_stats();
    n3s_db_migrate_access_stats(); // 2回目もエラーにならない

    n3s_record_access('api', 1);
    $date = date('Y-m-d');
    expect(_stats_count($date, 'api', 1))->toBe(1);
});
