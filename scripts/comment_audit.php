<?php
// CLIからの実行専用 (Web経由で未認証に実行できないようにする, #284)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: command line only.');
}
// ========================================================
// nako3storage scripts/comment_audit.php
// コメントの自動審査バッチスクリプト
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

// 多重起動防止 (#286): 審査と加算が二重に走らないようにする
$batch_lock = n3s_cli_batch_lock('comment_audit');
if ($batch_lock === false) {
    echo "[INFO] 既に実行中のため、処理をスキップしました。\n";
    exit(0);
}

// 審査本体は管理画面と共用 (#290)。ロックは取得済みなので $lock_held=true で呼ぶ
require_once __DIR__ . '/../app/comment_audit_run.inc.php';
n3s_comment_audit_run(function ($msg) { echo $msg; }, true);
