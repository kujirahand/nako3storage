<?php
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
