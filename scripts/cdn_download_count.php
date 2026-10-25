<?php
// CLIからの実行専用 (Web経由で未認証に実行できないようにする, #284)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: command line only.');
}
// ========================================================
// nako3storage scripts/cdn_download_count.php
// release/* CDNダウンロード生ログの定期集計
// ========================================================

chdir(dirname(__DIR__));

require_once __DIR__ . '/../app/n3s_config.def.php';
if (file_exists(__DIR__ . '/../n3s_config.ini.php')) {
    require_once __DIR__ . '/../n3s_config.ini.php';
}
require_once __DIR__ . '/../app/dlcounter_lib.inc.php';

// 多重起動防止 (#286)。このスクリプトは n3s_lib を読み込まないため、
// n3s_cli_batch_lock() と同じ方式 (一時ディレクトリ上の flock) をここに書く
$batch_lock_fp = @fopen(sys_get_temp_dir() . '/n3s_batch_cdn_download_count.lock', 'c');
if ($batch_lock_fp !== false && !flock($batch_lock_fp, LOCK_EX | LOCK_NB)) {
    echo "[INFO] 既に実行中のため、処理をスキップしました。\n";
    exit(0);
}

try {
    $result = n3s_aggregate_cdn_downloads();
} catch (Throwable $e) {
    echo '[ERROR] ' . $e->getMessage() . "\n";
    exit(1);
}

if ($result['max_log_id'] <= 0) {
    echo "[INFO] 未処理のCDNダウンロードログはありませんでした。\n";
    exit(0);
}

echo "[SUCCESS] {$result['log_count']} 件のログを {$result['stat_count']} 件の統計へ集計し、"
    . "log_id <= {$result['max_log_id']} のログを削除しました。\n";
