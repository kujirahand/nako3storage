<?php
// tests/Unit/AccessStatsTest.php
// 作品アクセス統計の単体テスト (Issue #217 / #246 と同じ「生ログ + 定期集計」方式)
//
// テスト対象:
//  - n3s_record_access() が app_access_log へ1行追加するだけで、書き込み時点では
//    重複除去も集計もしない
//  - n3s_aggregate_app_access() が (date, kind, app_id, ip) で重複除去して
//    access_stats / access_stats_monthly / apps.view へ加算し、ログを削除する
//  - n3s_get_app_access_count() が トータル / 今月 のアクセス数を返す
//  - n3s_db_migrate_access_stats() が既存 DB にテーブルを追加し、
//    既存の access_stats から月別統計と apps.view をバックフィルする

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/n3s_lib.inc.php';

// ------------------------------------------------
// ヘルパー
// ------------------------------------------------

/** app_access_log の行を全件取得する */
function _access_log_rows(): array
{
    return db_get('SELECT * FROM app_access_log ORDER BY log_id ASC', [], 'log') ?: [];
}

/** access_stats の count を取得する（行がなければ 0） */
function _stats_count(string $date, string $kind, int $app_id): int
{
    $row = db_get1(
        'SELECT count FROM access_stats WHERE date=? AND kind=? AND app_id=?',
        [$date, $kind, $app_id],
        'log'
    );
    return $row ? (int)$row['count'] : 0;
}

/** access_stats_monthly の count を取得する（行がなければ 0） */
function _monthly_count(string $month, string $kind, int $app_id): int
{
    $row = db_get1(
        'SELECT count FROM access_stats_monthly WHERE month=? AND kind=? AND app_id=?',
        [$month, $kind, $app_id],
        'log'
    );
    return $row ? (int)$row['count'] : 0;
}

/** apps.view を取得する */
function _app_view(int $app_id): int
{
    $row = db_get1('SELECT view FROM apps WHERE app_id=?', [$app_id], 'main');
    return $row ? (int)$row['view'] : 0;
}

/** apps テーブルにテスト用の作品を挿入して app_id を返す */
function _as_insert_app(): int
{
    return (int)db_insert(
        'INSERT INTO apps (title, ctime) VALUES (?,?)',
        ['テスト作品', time()],
        'main'
    );
}

/** app_access_log へ任意の ip / 時刻のログを直接書き込む */
function _as_insert_log(int $app_id, string $kind, string $ip, int $ctime): void
{
    db_exec(
        'INSERT INTO app_access_log (app_id, kind, ip, ctime) VALUES (?,?,?,?)',
        [$app_id, $kind, $ip, $ctime],
        'log'
    );
}

// ------------------------------------------------
// n3s_record_access(): 生ログの記録
// ------------------------------------------------

test('n3s_record_access() は app_access_log へ1行追加する', function () {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
    n3s_record_access('show', 10);

    $rows = _access_log_rows();
    expect($rows)->toHaveCount(1);
    expect((int)$rows[0]['app_id'])->toBe(10);
    expect($rows[0]['kind'])->toBe('show');
    expect($rows[0]['ip'])->toBe('198.51.100.1');
    expect((int)$rows[0]['ctime'])->toBeGreaterThan(0);
});

test('n3s_record_access() は書き込み時点では集計しない (access_stats は空のまま)', function () {
    n3s_record_access('show', 10);
    expect(_stats_count(date('Y-m-d'), 'show', 10))->toBe(0);
    expect(_monthly_count(date('Y-m'), 'show', 10))->toBe(0);
});

test('n3s_record_access() を複数回呼ぶと重複除去せずその都度1行追加される', function () {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.2';
    n3s_record_access('show', 20);
    n3s_record_access('show', 20);
    n3s_record_access('show', 20);
    expect(_access_log_rows())->toHaveCount(3);
});

test('n3s_record_access() は種別 (kind) をそのまま記録する', function () {
    n3s_record_access('show', 40);
    n3s_record_access('widget', 40);
    n3s_record_access('api', 40);
    expect(array_column(_access_log_rows(), 'kind'))->toBe(['show', 'widget', 'api']);
});

test('n3s_record_access() は app_id が 0 以下なら何も記録しない', function () {
    n3s_record_access('widget', 0);
    n3s_record_access('show', -1);
    expect(_access_log_rows())->toHaveCount(0);
});

// ------------------------------------------------
// n3s_aggregate_app_access(): 定期集計
// ------------------------------------------------

test('n3s_aggregate_app_access() はログが無ければ何もしない', function () {
    $r = n3s_aggregate_app_access();
    expect($r['max_log_id'])->toBe(0);
    expect($r['log_count'])->toBe(0);
});

test('n3s_aggregate_app_access() は同一IPの重複を除去して集計する', function () {
    $app_id = _as_insert_app();
    $now = time();
    $date = date('Y-m-d', $now);
    // 同一IPから3回 + 別IPから1回 => ユニークIPは2
    _as_insert_log($app_id, 'show', '203.0.113.1', $now);
    _as_insert_log($app_id, 'show', '203.0.113.1', $now);
    _as_insert_log($app_id, 'show', '203.0.113.1', $now);
    _as_insert_log($app_id, 'show', '203.0.113.2', $now);

    $r = n3s_aggregate_app_access();
    expect($r['log_count'])->toBe(4);
    expect($r['app_count'])->toBe(1);
    expect(_stats_count($date, 'show', $app_id))->toBe(2);
    expect(_app_view($app_id))->toBe(2);
});

test('n3s_aggregate_app_access() は集計済みのログを削除する', function () {
    $app_id = _as_insert_app();
    _as_insert_log($app_id, 'show', '203.0.113.1', time());
    n3s_aggregate_app_access();
    expect(_access_log_rows())->toHaveCount(0);
});

test('n3s_aggregate_app_access() は全体合計 (app_id=0) も同時に集計する', function () {
    $app1 = _as_insert_app();
    $app2 = _as_insert_app();
    $now = time();
    $date = date('Y-m-d', $now);
    _as_insert_log($app1, 'show', '203.0.113.1', $now);
    _as_insert_log($app2, 'show', '203.0.113.2', $now);

    n3s_aggregate_app_access();
    expect(_stats_count($date, 'show', 0))->toBe(2);
    expect(_monthly_count(date('Y-m', $now), 'show', 0))->toBe(2);
    // 全体合計は apps.view には含めない
    expect(_app_view($app1))->toBe(1);
    expect(_app_view($app2))->toBe(1);
});

test('n3s_aggregate_app_access() はログの ctime の日付・月で集計する', function () {
    $app_id = _as_insert_app();
    // 2026-06-30 と 2026-07-01 (別の日かつ別の月) のログを用意する
    $t_jun = mktime(12, 0, 0, 6, 30, 2026);
    $t_jul = mktime(12, 0, 0, 7, 1, 2026);
    _as_insert_log($app_id, 'show', '203.0.113.1', $t_jun);
    _as_insert_log($app_id, 'show', '203.0.113.1', $t_jul);

    n3s_aggregate_app_access();
    // 同一IPでも日が違えば別カウント
    expect(_stats_count('2026-06-30', 'show', $app_id))->toBe(1);
    expect(_stats_count('2026-07-01', 'show', $app_id))->toBe(1);
    expect(_monthly_count('2026-06', 'show', $app_id))->toBe(1);
    expect(_monthly_count('2026-07', 'show', $app_id))->toBe(1);
    expect(_app_view($app_id))->toBe(2);
});

test('n3s_aggregate_app_access() は種別ごとに独立して集計する', function () {
    $app_id = _as_insert_app();
    $now = time();
    $date = date('Y-m-d', $now);
    // 同一IPでも kind が違えば別カウント
    _as_insert_log($app_id, 'show',   '203.0.113.1', $now);
    _as_insert_log($app_id, 'widget', '203.0.113.1', $now);
    _as_insert_log($app_id, 'api',    '203.0.113.1', $now);

    n3s_aggregate_app_access();
    expect(_stats_count($date, 'show',   $app_id))->toBe(1);
    expect(_stats_count($date, 'widget', $app_id))->toBe(1);
    expect(_stats_count($date, 'api',    $app_id))->toBe(1);
    // apps.view は show + widget のみ (api は含めない)
    expect(_app_view($app_id))->toBe(2);
});

test('n3s_aggregate_app_access() を繰り返しても既存の集計値に加算される', function () {
    $app_id = _as_insert_app();
    $now = time();
    $date = date('Y-m-d', $now);

    _as_insert_log($app_id, 'show', '203.0.113.1', $now);
    n3s_aggregate_app_access();
    // 別のバッチ実行では同一IPでも重複除去されない (image_count.php と同じ割り切り)
    _as_insert_log($app_id, 'show', '203.0.113.1', $now);
    n3s_aggregate_app_access();

    expect(_stats_count($date, 'show', $app_id))->toBe(2);
    expect(_monthly_count(date('Y-m', $now), 'show', $app_id))->toBe(2);
    expect(_app_view($app_id))->toBe(2);
});

// ------------------------------------------------
// n3s_get_app_access_count(): 作品情報の表示用
// ------------------------------------------------

test('n3s_get_app_access_count() はトータルと今月のアクセス数を返す', function () {
    $app_id = _as_insert_app();
    $now = time();
    // 今月分 (show 1件 + widget 1件) と、先月分 (show 1件)
    $last_month = strtotime('-1 month', $now);
    _as_insert_log($app_id, 'show',   '203.0.113.1', $now);
    _as_insert_log($app_id, 'widget', '203.0.113.2', $now);
    _as_insert_log($app_id, 'show',   '203.0.113.3', $last_month);
    n3s_aggregate_app_access();

    $r = n3s_get_app_access_count($app_id);
    expect($r['view_total'])->toBe(3);   // 累計 = 今月2 + 先月1
    expect($r['view_monthly'])->toBe(2); // 今月のみ
});

test('n3s_get_app_access_count() は api を除いた show + widget を数える', function () {
    $app_id = _as_insert_app();
    $now = time();
    _as_insert_log($app_id, 'show', '203.0.113.1', $now);
    _as_insert_log($app_id, 'api',  '203.0.113.2', $now);
    n3s_aggregate_app_access();

    $r = n3s_get_app_access_count($app_id);
    expect($r['view_total'])->toBe(1);
    expect($r['view_monthly'])->toBe(1);
});

test('n3s_get_app_access_count() はアクセスが無ければ 0 を返す', function () {
    $app_id = _as_insert_app();
    expect(n3s_get_app_access_count($app_id))->toBe(['view_total' => 0, 'view_monthly' => 0]);
});

test('n3s_get_app_access_count() は app_id が 0 以下なら 0 を返す', function () {
    expect(n3s_get_app_access_count(0))->toBe(['view_total' => 0, 'view_monthly' => 0]);
    expect(n3s_get_app_access_count(-1))->toBe(['view_total' => 0, 'view_monthly' => 0]);
});

// ------------------------------------------------
// n3s_db_migrate_access_stats(): マイグレーションとバックフィル
// ------------------------------------------------

test('n3s_db_migrate_access_stats() は冪等で、呼び出し後も記録・集計ができる', function () {
    n3s_db_migrate_access_stats();
    n3s_db_migrate_access_stats(); // 2回目もエラーにならない

    $app_id = _as_insert_app();
    _as_insert_log($app_id, 'show', '203.0.113.1', time());
    n3s_aggregate_app_access();
    expect(_stats_count(date('Y-m-d'), 'show', $app_id))->toBe(1);
});

test('n3s_db_migrate_access_stats() は既存 DB にテーブルを作成する', function () {
    // access_stats_monthly / app_access_log が無い旧DBを再現する
    db_exec('DROP TABLE access_stats_monthly', [], 'log');
    db_exec('DROP TABLE app_access_log', [], 'log');

    n3s_db_migrate_access_stats();

    $app_id = _as_insert_app();
    n3s_record_access('show', $app_id);
    expect(_access_log_rows())->toHaveCount(1);
    n3s_aggregate_app_access();
    expect(_monthly_count(date('Y-m'), 'show', $app_id))->toBe(1);
});

test('n3s_db_migrate_access_stats() は既存の日別統計から月別統計と apps.view を復元する', function () {
    // 移行前 (リアルタイム集計) の access_stats だけがある旧DBを再現する
    db_exec('DROP TABLE access_stats_monthly', [], 'log');
    $app_id = _as_insert_app();
    foreach ([
        ['2026-06-30', 'show',   $app_id, 5],
        ['2026-07-01', 'show',   $app_id, 3],
        ['2026-07-02', 'widget', $app_id, 2],
        ['2026-07-02', 'api',    $app_id, 9], // api は apps.view に含めない
    ] as $row) {
        db_exec(
            'INSERT INTO access_stats (date, kind, app_id, count) VALUES (?,?,?,?)',
            $row,
            'log'
        );
    }

    n3s_db_migrate_access_stats();

    // 月別統計が日別統計から作られる
    expect(_monthly_count('2026-06', 'show',   $app_id))->toBe(5);
    expect(_monthly_count('2026-07', 'show',   $app_id))->toBe(3);
    expect(_monthly_count('2026-07', 'widget', $app_id))->toBe(2);
    expect(_monthly_count('2026-07', 'api',    $app_id))->toBe(9);
    // apps.view は show + widget の累計 (5 + 3 + 2 = 10)
    expect(_app_view($app_id))->toBe(10);
});

test('バックフィルは2回目のマイグレーションでは実行されない (二重計上しない)', function () {
    db_exec('DROP TABLE access_stats_monthly', [], 'log');
    $app_id = _as_insert_app();
    db_exec(
        'INSERT INTO access_stats (date, kind, app_id, count) VALUES (?,?,?,?)',
        ['2026-07-01', 'show', $app_id, 4],
        'log'
    );

    n3s_db_migrate_access_stats();
    n3s_db_migrate_access_stats(); // テーブルが既にあるのでバックフィルはスキップされる

    expect(_monthly_count('2026-07', 'show', $app_id))->toBe(4);
    expect(_app_view($app_id))->toBe(4);
});
