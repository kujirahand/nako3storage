<?php
// release/* CDNダウンロードカウンタ (Issue #252)

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/dlcounter_lib.inc.php';

function dlcounter_log_rows(): array
{
    $pdo = n3s_dlcounter_open_logs();
    return $pdo->query('SELECT * FROM cdn_download_logs ORDER BY log_id')->fetchAll();
}

function dlcounter_stat_rows(): array
{
    $pdo = n3s_dlcounter_open_main();
    return $pdo->query(
        'SELECT date, hour, file, version, count FROM cdn_download_stats
         ORDER BY date, hour, file, version'
    )->fetchAll();
}

test('release配下のGETだけをダウンロード対象とする', function () {
    expect(n3s_dlcounter_should_record('release/wnako3.js', 'GET'))->toBeTrue()
        ->and(n3s_dlcounter_should_record('release/plugin_system.js', 'get'))->toBeTrue()
        ->and(n3s_dlcounter_should_record('release/', 'GET'))->toBeFalse()
        ->and(n3s_dlcounter_should_record('src/wnako3.js', 'GET'))->toBeFalse()
        ->and(n3s_dlcounter_should_record('release/wnako3.js', 'HEAD'))->toBeFalse();
});

test('生ログと集計を別々のSQLiteへ保存する', function () {
    global $n3s_config;

    expect(n3s_record_cdn_download('release/wnako3.js', '3.8.7', 0, 'GET'))->toBeTrue()
        ->and(n3s_record_cdn_download('release/plugin_system.js', '3.8.6', 3600, 'GET'))->toBeTrue();

    $paths = n3s_dlcounter_paths();
    expect($paths['main'])->toBe($n3s_config['dir_data'] . '/dlcounter-main.sqlite')
        ->and($paths['logs'])->toBe($n3s_config['dir_data'] . '/dlcounter-logs.sqlite')
        ->and(file_exists($paths['main']))->toBeFalse()
        ->and(file_exists($paths['logs']))->toBeTrue()
        ->and(dlcounter_log_rows())->toHaveCount(2);

    n3s_dlcounter_open_main();
    expect(file_exists($paths['main']))->toBeTrue();
});

test('対象外ファイルとHEADはログへ保存しない', function () {
    expect(n3s_record_cdn_download('src/wnako3.js', '3.8.7', 100, 'GET'))->toBeFalse()
        ->and(n3s_record_cdn_download('release/wnako3.js', '3.8.7', 100, 'HEAD'))->toBeFalse()
        ->and(dlcounter_log_rows())->toBeEmpty();
});

test('CDNログ用接続は指定したロック待機時間を使用する', function () {
    $pdo = n3s_dlcounter_open_logs(250);
    $busy_timeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();

    expect($busy_timeout)->toBe(250);
});

test('日付・時間・ファイル・バージョン別に集計して生ログを削除する', function () {
    // Unix epoch 0 は JST で 1970-01-01 09時。
    n3s_record_cdn_download('release/wnako3.js', '3.8.7', 0, 'GET');
    n3s_record_cdn_download('release/wnako3.js', '3.8.7', 30, 'GET');
    n3s_record_cdn_download('release/plugin_system.js', '3.8.7', 3600, 'GET');
    n3s_record_cdn_download('release/wnako3.js', '3.8.6', 86400, 'GET');

    $result = n3s_aggregate_cdn_downloads();

    expect($result['log_count'])->toBe(4)
        ->and($result['stat_count'])->toBe(3)
        ->and(dlcounter_log_rows())->toBeEmpty()
        ->and(dlcounter_stat_rows())->toBe([
            [
                'date' => '1970-01-01', 'hour' => 9,
                'file' => 'release/wnako3.js', 'version' => '3.8.7', 'count' => 2,
            ],
            [
                'date' => '1970-01-01', 'hour' => 10,
                'file' => 'release/plugin_system.js', 'version' => '3.8.7', 'count' => 1,
            ],
            [
                'date' => '1970-01-02', 'hour' => 9,
                'file' => 'release/wnako3.js', 'version' => '3.8.6', 'count' => 1,
            ],
        ]);
});

test('複数回の集計は既存統計へ加算し空実行では変更しない', function () {
    n3s_record_cdn_download('release/wnako3.js', '3.8.7', 0, 'GET');
    n3s_aggregate_cdn_downloads();
    n3s_record_cdn_download('release/wnako3.js', '3.8.7', 1, 'GET');
    n3s_aggregate_cdn_downloads();
    $empty = n3s_aggregate_cdn_downloads();

    expect(dlcounter_stat_rows()[0]['count'])->toBe(2)
        ->and($empty)->toBe([
            'max_log_id' => 0,
            'log_count' => 0,
            'stat_count' => 0,
        ]);
});

test('集計途中で失敗した場合は統計加算と生ログ削除を両方ロールバックする', function () {
    n3s_record_cdn_download('release/wnako3.js', '3.8.7', 0, 'GET');
    $main = n3s_dlcounter_open_main();
    $main->exec(
        "CREATE TRIGGER force_cdn_aggregate_failure
         BEFORE INSERT ON cdn_download_stats
         BEGIN
           SELECT RAISE(ABORT, 'forced aggregate failure');
         END"
    );

    $failed = false;
    try {
        n3s_aggregate_cdn_downloads();
    } catch (Throwable $e) {
        $failed = true;
    }

    expect($failed)->toBeTrue()
        ->and(dlcounter_stat_rows())->toBeEmpty()
        ->and(dlcounter_log_rows())->toHaveCount(1);

    $main->exec('DROP TRIGGER force_cdn_aggregate_failure');
    n3s_aggregate_cdn_downloads();
    expect(dlcounter_stat_rows()[0]['count'])->toBe(1)
        ->and(dlcounter_log_rows())->toBeEmpty();
});

test('ダッシュボードは週間・月間・時間・バージョン・ファイルを集計する', function () {
    $now = strtotime('2026-09-22 12:00:00');
    n3s_record_cdn_download('release/wnako3.js', '3.8.7', $now, 'GET');
    n3s_record_cdn_download('release/wnako3.js', '3.8.7', $now, 'GET');
    n3s_record_cdn_download('release/plugin_system.js', '3.8.6', $now, 'GET');
    n3s_aggregate_cdn_downloads();
    n3s_record_cdn_download('release/pending.js', '3.8.7', $now, 'GET');

    $data = n3s_get_cdn_download_dashboard($now);

    expect($data['week_total'])->toBe(3)
        ->and($data['month_total'])->toBe(3)
        ->and($data['pending_count'])->toBe(1)
        ->and($data['version_rows'][0])->toBe(['version' => '3.8.7', 'count' => 2])
        ->and($data['file_rows'][0])->toBe(['file' => 'release/wnako3.js', 'count' => 2])
        ->and(array_sum($data['daily_counts']))->toBe(3)
        ->and(array_sum($data['hour_counts']))->toBe(3);
});
