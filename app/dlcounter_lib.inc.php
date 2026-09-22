<?php
// release/* CDN ダウンロードカウンタ共通処理 (Issue #252)

/**
 * カウンタDBの保存先を返す。
 */
function n3s_dlcounter_paths()
{
    global $n3s_config;
    $default_dir = dirname(__DIR__) . '/data';
    $dir_data = isset($n3s_config['dir_data']) ? $n3s_config['dir_data'] : $default_dir;
    $dir_data = rtrim((string) $dir_data, '/');
    if ($dir_data === '') {
        $dir_data = $default_dir;
    }
    return [
        'dir' => $dir_data,
        'main' => $dir_data . '/dlcounter-main.sqlite',
        'logs' => $dir_data . '/dlcounter-logs.sqlite',
    ];
}

/**
 * SQLite接続を作り、指定した初期化SQLを適用する。
 */
function n3s_dlcounter_open($file, $init_sql, $busy_timeout_ms = 3000)
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("カウンタDBの保存先を作成できません: {$dir}");
    }
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $busy_timeout_ms = max(0, (int) $busy_timeout_ms);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, max(1, (int) ceil($busy_timeout_ms / 1000)));
    $pdo->exec('PRAGMA busy_timeout = ' . $busy_timeout_ms);
    // 配信のたびにDDLを実行しない。新規DBまたは将来のスキーマ更新時だけ適用する。
    $schema_version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($schema_version < 1) {
        $sql = @file_get_contents($init_sql);
        if ($sql === false) {
            throw new RuntimeException("カウンタDBの初期化SQLを読めません: {$init_sql}");
        }
        $pdo->exec($sql);
    }
    return $pdo;
}

function n3s_dlcounter_open_main()
{
    $paths = n3s_dlcounter_paths();
    return n3s_dlcounter_open($paths['main'], __DIR__ . '/sql/init-dlcounter-main.sql');
}

function n3s_dlcounter_open_logs($busy_timeout_ms = 3000)
{
    $paths = n3s_dlcounter_paths();
    return n3s_dlcounter_open(
        $paths['logs'],
        __DIR__ . '/sql/init-dlcounter-logs.sql',
        $busy_timeout_ms
    );
}

/**
 * release/ 以下の実ファイルへのGETだけを記録対象とする。
 */
function n3s_dlcounter_should_record($file, $method = 'GET')
{
    if (strtoupper((string) $method) !== 'GET') {
        return false;
    }
    $file = (string) $file;
    return strpos($file, 'release/') === 0
        && strlen($file) > strlen('release/')
        && substr($file, -1) !== '/';
}

/**
 * 成功したCDN配信を生ログDBへ記録する。
 */
function n3s_record_cdn_download($file, $version, $ctime = null, $method = null)
{
    if ($method === null) {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    }
    if (!n3s_dlcounter_should_record($file, $method)) {
        return false;
    }
    if ($ctime === null) {
        $ctime = time();
    }
    // CDNレスポンスを長時間止めない。短時間で書けなければsafeラッパーで配信を優先する。
    $pdo = n3s_dlcounter_open_logs(250);
    $stmt = $pdo->prepare(
        'INSERT INTO cdn_download_logs (file, version, ctime) VALUES (?, ?, ?)'
    );
    $stmt->execute([(string) $file, (string) $version, (int) $ctime]);
    return true;
}

/**
 * CDN配信を妨げない記録用ラッパー。
 */
function n3s_record_cdn_download_safe($file, $version, $ctime = null, $method = null)
{
    try {
        return n3s_record_cdn_download($file, $version, $ctime, $method);
    } catch (Throwable $e) {
        static $reported = false;
        if (!$reported) {
            error_log('[dlcounter] ' . $e->getMessage());
            $reported = true;
        }
        return false;
    }
}

/**
 * 生ログを日付・時間・ファイル・バージョン別に集計する。
 *
 * 2つのDBをATTACHし、集計加算とログ削除を同一トランザクションで行う。
 */
function n3s_aggregate_cdn_downloads()
{
    $paths = n3s_dlcounter_paths();

    // ATTACH先にもテーブルが存在することを先に保証する。
    $logs = n3s_dlcounter_open_logs();
    $logs = null;

    $pdo = n3s_dlcounter_open_main();
    $attached = false;
    try {
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($paths['logs']) . ' AS dlcounter_logs');
        $attached = true;
        // BEGIN DEFERRED だと、先に生ログを読んだ後でCDN側のINSERTと競合した際に
        // 共有ロックから書込ロックへ昇格できず SQLITE_BUSY になることがある。
        // 最初に両DBの書込権を確保し、集計とログ削除を確実に同じ処理単位にする。
        $pdo->exec('BEGIN IMMEDIATE');

        $max_log_id = (int) $pdo->query(
            'SELECT COALESCE(MAX(log_id), 0) FROM dlcounter_logs.cdn_download_logs'
        )->fetchColumn();
        if ($max_log_id <= 0) {
            $pdo->commit();
            return [
                'max_log_id' => 0,
                'log_count' => 0,
                'stat_count' => 0,
            ];
        }

        $count_stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM dlcounter_logs.cdn_download_logs WHERE log_id <= ?'
        );
        $count_stmt->execute([$max_log_id]);
        $log_count = (int) $count_stmt->fetchColumn();

        $group_stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                SELECT 1
                  FROM dlcounter_logs.cdn_download_logs
                 WHERE log_id <= ?
                 GROUP BY date(ctime, 'unixepoch', '+9 hours'),
                          CAST(strftime('%H', ctime, 'unixepoch', '+9 hours') AS INTEGER),
                          file, version
            )"
        );
        $group_stmt->execute([$max_log_id]);
        $stat_count = (int) $group_stmt->fetchColumn();

        $aggregate = $pdo->prepare(
            "INSERT INTO cdn_download_stats (date, hour, file, version, count)
             SELECT date(ctime, 'unixepoch', '+9 hours') AS download_date,
                    CAST(strftime('%H', ctime, 'unixepoch', '+9 hours') AS INTEGER) AS download_hour,
                    file,
                    version,
                    COUNT(*)
               FROM dlcounter_logs.cdn_download_logs
              WHERE log_id <= ?
              GROUP BY download_date, download_hour, file, version
             ON CONFLICT(date, hour, file, version)
             DO UPDATE SET count = count + excluded.count"
        );
        $aggregate->execute([$max_log_id]);

        $delete = $pdo->prepare(
            'DELETE FROM dlcounter_logs.cdn_download_logs WHERE log_id <= ?'
        );
        $delete->execute([$max_log_id]);

        $meta = $pdo->prepare(
            'INSERT INTO cdn_download_meta (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $meta->execute(['last_aggregated_at', (string) time()]);
        $meta->execute(['last_max_log_id', (string) $max_log_id]);

        $pdo->commit();
        return [
            'max_log_id' => $max_log_id,
            'log_count' => $log_count,
            'stat_count' => $stat_count,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        if ($attached) {
            try {
                $pdo->exec('DETACH DATABASE dlcounter_logs');
            } catch (Throwable $e) {
                // 接続終了時にも解除されるため、ここでの失敗は無視する。
            }
        }
    }
}

function n3s_dlcounter_fetch_all($pdo, $sql, $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function n3s_dlcounter_fetch_value($pdo, $sql, $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * 管理画面で使う統計一式を返す。
 */
function n3s_get_cdn_download_dashboard($now = null)
{
    if ($now === null) {
        $now = time();
    }
    $main = n3s_dlcounter_open_main();
    $logs = n3s_dlcounter_open_logs();
    $today = date('Y-m-d', $now);
    $week_since = date('Y-m-d', strtotime('-6 days', $now));
    $month = date('Y-m', $now);
    $month_since = $month . '-01';
    $daily_since = date('Y-m-d', strtotime('-29 days', $now));

    $week_total = n3s_dlcounter_fetch_value(
        $main,
        'SELECT COALESCE(SUM(count), 0) FROM cdn_download_stats WHERE date BETWEEN ? AND ?',
        [$week_since, $today]
    );
    $month_total = n3s_dlcounter_fetch_value(
        $main,
        'SELECT COALESCE(SUM(count), 0) FROM cdn_download_stats WHERE date >= ? AND date <= ?',
        [$month_since, $today]
    );
    $daily_rows = n3s_dlcounter_fetch_all(
        $main,
        'SELECT date, SUM(count) AS count FROM cdn_download_stats
          WHERE date BETWEEN ? AND ? GROUP BY date ORDER BY date',
        [$daily_since, $today]
    );
    $hour_rows = n3s_dlcounter_fetch_all(
        $main,
        'SELECT hour, SUM(count) AS count FROM cdn_download_stats
          WHERE date BETWEEN ? AND ? GROUP BY hour ORDER BY hour',
        [$month_since, $today]
    );
    $version_rows = n3s_dlcounter_fetch_all(
        $main,
        'SELECT version, SUM(count) AS count FROM cdn_download_stats
          WHERE date BETWEEN ? AND ? GROUP BY version ORDER BY count DESC, version DESC',
        [$month_since, $today]
    );
    $file_rows = n3s_dlcounter_fetch_all(
        $main,
        'SELECT file, SUM(count) AS count FROM cdn_download_stats
          WHERE date BETWEEN ? AND ? GROUP BY file ORDER BY count DESC, file ASC',
        [$month_since, $today]
    );
    $version_file_rows = n3s_dlcounter_fetch_all(
        $main,
        'SELECT version, file, SUM(count) AS count FROM cdn_download_stats
          WHERE date BETWEEN ? AND ? GROUP BY version, file
          ORDER BY count DESC, version DESC, file ASC LIMIT 100',
        [$month_since, $today]
    );
    $pending_count = n3s_dlcounter_fetch_value(
        $logs,
        'SELECT COUNT(*) FROM cdn_download_logs'
    );
    $meta_rows = n3s_dlcounter_fetch_all($main, 'SELECT key, value FROM cdn_download_meta');
    $meta = [];
    foreach ($meta_rows as $row) {
        $meta[$row['key']] = $row['value'];
    }

    $daily_map = [];
    foreach ($daily_rows as $row) {
        $daily_map[$row['date']] = (int) $row['count'];
    }
    $daily_labels = [];
    $daily_counts = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days", $now));
        $daily_labels[] = $date;
        $daily_counts[] = isset($daily_map[$date]) ? $daily_map[$date] : 0;
    }

    $hour_map = [];
    foreach ($hour_rows as $row) {
        $hour_map[(int) $row['hour']] = (int) $row['count'];
    }
    $hour_labels = [];
    $hour_counts = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $hour_labels[] = sprintf('%02d時', $hour);
        $hour_counts[] = isset($hour_map[$hour]) ? $hour_map[$hour] : 0;
    }

    return [
        'week_since' => $week_since,
        'month' => $month,
        'week_total' => $week_total,
        'month_total' => $month_total,
        'pending_count' => $pending_count,
        'last_aggregated_at' => isset($meta['last_aggregated_at'])
            ? (int) $meta['last_aggregated_at'] : 0,
        'daily_labels' => $daily_labels,
        'daily_counts' => $daily_counts,
        'hour_labels' => $hour_labels,
        'hour_counts' => $hour_counts,
        'version_rows' => $version_rows,
        'file_rows' => $file_rows,
        'version_file_rows' => $version_file_rows,
    ];
}
