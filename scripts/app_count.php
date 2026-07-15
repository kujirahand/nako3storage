<?php
// ========================================================
// nako3storage scripts/app_count.php
// 作品アクセスログの集計バッチ
//
// 作品ページ(show) / ウィジェット(widget) / API(api) へのアクセスは
// n3s_record_access() により log DB の app_access_log テーブルへ
// (app_id, kind, ip, ctime) として都度追記されている(重複除去はしない)。
// このスクリプトは1時間に1回程度 cron から実行することを想定し、
// 未処理のログを (date, kind, app_id, ip) で重複除去して
//   - access_stats         (日別集計)
//   - access_stats_monthly (月別集計)
//   - apps.view            (トータルアクセス数 = show + widget)
// へ加算し、処理済みのログ行を削除する。
//
// 集計処理の本体は n3s_lib.inc.php の n3s_aggregate_app_access()。
// ここはCLIから呼び出して結果を表示するだけのラッパー。
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

try {
    $r = n3s_aggregate_app_access();
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

if ($r['max_log_id'] <= 0) {
    echo "[INFO] 未処理の作品アクセスログはありませんでした。\n";
    exit(0);
}

echo "[INFO] {$r['log_count']} 件のログを {$r['app_count']} 件の作品に集計しました。\n";
echo "[SUCCESS] 日別 {$r['daily_count']} 件・月別 {$r['monthly_count']} 件の統計を更新し、"
    . "log_id <= {$r['max_log_id']} のログを削除しました。\n";
echo "[INFO] 作品アクセス集計バッチが完了しました。\n";
