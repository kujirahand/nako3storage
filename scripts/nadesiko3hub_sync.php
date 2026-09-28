<?php
// ========================================================
// nako3storage scripts/nadesiko3hub_sync.php
// nadesiko3hubへの投稿取りこぼし補完バッチ (Issue #270)
//
// 投稿の保存・更新時には n3s_nadesiko3hub_save() が都度呼ばれて
// ./nadesiko3hub/ に .nako3 ファイルを出力しているが、何らかの理由で
// 反映が漏れた投稿を補うためのバッチ。1日1回程度 cron から実行する
// ことを想定し、既に出力済み(.nako3が存在する)の投稿はスキップして、
// 未出力の公開投稿だけを書き出す。
//
// 使い方:
//   php scripts/nadesiko3hub_sync.php        # 全期間を対象にスキャン
//   php scripts/nadesiko3hub_sync.php 7      # 直近7日以内に投稿・更新された作品のみスキャン
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

if (!n3s_get_config('nadesiko3hub_enabled', FALSE)) {
    echo "[INFO] nadesiko3hub_enabled が無効なため、処理をスキップしました。\n";
    exit(0);
}

// スキャン期間(日数)をオプションで指定する
$days_arg = isset($argv[1]) ? trim($argv[1]) : '';
$since_ts = 0;
if ($days_arg !== '') {
    if (!ctype_digit($days_arg) || intval($days_arg) <= 0) {
        echo "[ERROR] スキャン期間(日数)は正の整数で指定してください。\n";
        exit(1);
    }
    $since_ts = time() - intval($days_arg) * 86400;
    echo "[INFO] 直近{$days_arg}日以内に投稿・更新された作品のみをスキャンします。\n";
} else {
    echo "[INFO] 全期間の作品をスキャンします。\n";
}

$result = n3s_nadesiko3hub_sync($since_ts);
echo "[INFO] 対象 {$result['total']} 件 / 保存 {$result['saved']} 件 / スキップ(既存) {$result['skipped']} 件\n";
echo "[SUCCESS] nadesiko3hubへの補完バッチが完了しました。\n";
