<?php
// ========================================================
// nako3storage scripts/image_count.php
// 素材(image.php)アクセスログの集計バッチ
//
// image.php へのアクセスは n3s_record_image_access() により
// log DB の image_access_log テーブルへ (image_id, ip, ctime) として
// 都度追記されている(重複除去はしない)。
// このスクリプトは1時間に1回程度 cron から実行することを想定し、
// 未処理のログを (image_id, ip) で重複除去して images.view に加算し、
// 処理済みのログ行は削除する。
// ========================================================

// 実行ディレクトリをルートにする
chdir(dirname(__DIR__));

// 基本設定の読み込み
require_once __DIR__ . '/../app/n3s_config.def.php';
if (file_exists(__DIR__ . '/../n3s_config.ini.php')) {
    require_once __DIR__ . '/../n3s_config.ini.php';
}
require_once __DIR__ . '/../app/n3s_lib.inc.php';

// DB初期化
n3s_db_init();

// 処理対象を実行開始時点までのログに固定する(実行中に増える分は次回に回す)
$max_row = db_get1('SELECT MAX(log_id) AS max_log_id FROM image_access_log', [], 'log');
$max_log_id = $max_row ? intval($max_row['max_log_id']) : 0;

if ($max_log_id <= 0) {
    echo "[INFO] 未処理の素材アクセスログはありませんでした。\n";
    exit(0);
}

$rows = db_get(
    'SELECT image_id, ip FROM image_access_log WHERE log_id <= ?',
    [$max_log_id],
    'log'
);

if (empty($rows)) {
    echo "[INFO] 未処理の素材アクセスログはありませんでした。\n";
    exit(0);
}

// (image_id, ip) の組で重複除去し、image_idごとのユニークIP数を数える
$seen = [];
$counts = [];
foreach ($rows as $row) {
    $image_id = intval($row['image_id']);
    $ip = (string) $row['ip'];
    $key = $image_id . '::' . $ip;
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;
    if (!isset($counts[$image_id])) {
        $counts[$image_id] = 0;
    }
    $counts[$image_id]++;
}

echo "[INFO] " . count($rows) . " 件のログを " . count($counts) . " 件の素材に集計します。\n";

$updated = 0;
db_begin('main');
try {
    foreach ($counts as $image_id => $count) {
        db_exec(
            'UPDATE images SET view = view + ? WHERE image_id = ?',
            [$count, $image_id],
            'main'
        );
        $updated++;
    }
    db_commit('main');
    echo "[SUCCESS] {$updated} 件の素材の閲覧数を更新しました。\n";
} catch (Exception $e) {
    db_rollback('main');
    echo "[ERROR] 閲覧数の更新に失敗したため、ログの削除をスキップします: " . $e->getMessage() . "\n";
    exit(1);
}

// 集計済みのログを削除する
db_begin('log');
try {
    db_exec('DELETE FROM image_access_log WHERE log_id <= ?', [$max_log_id], 'log');
    db_commit('log');
    echo "[SUCCESS] log_id <= {$max_log_id} のログを削除しました。\n";
} catch (Exception $e) {
    db_rollback('log');
    echo "[WARNING] ログの削除に失敗しました(次回集計時に重複カウントされる可能性があります): " . $e->getMessage() . "\n";
}

echo "[INFO] 素材アクセス集計バッチが完了しました。\n";
