<?php
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

global $n3s_config;
$api_key = isset($n3s_config['openrouter_api_key']) ? $n3s_config['openrouter_api_key'] : '';
$model = n3s_get_config('comment_audit_model', '~typesafe/jev-latest');
$auto_approve = isset($n3s_config['comment_audit_auto_approve']) ? $n3s_config['comment_audit_auto_approve'] : false;

// APIキーが空、または自動承認（auto_approve）が有効な場合は審査をパスする
$skip_ai_and_approve = empty($api_key) || $auto_approve;

require_once __DIR__ . '/../app/comment_audit_openrouter.inc.php';

// 審査待ちのコメントを取得
$comments = db_get(
    "SELECT * FROM comments WHERE status = 'pending'",
    [],
    'main'
);

if (empty($comments)) {
    echo "[INFO] 審査待ちのコメントはありませんでした。\n";
    exit(0);
}

$total_processed = count($comments);
$error_count = 0;

echo "[INFO] " . $total_processed . " 件の審査待ちコメントを処理します。\n";

foreach ($comments as $c) {
    $comment_id = $c['comment_id'];
    $body = $c['body'];
    
    $approved = false;
    if ($skip_ai_and_approve) {
        if (empty($api_key)) {
            echo "[INFO] openrouter_api_key が設定されていないため、審査をパスして自動承認（公開）します。\n";
        } else {
            echo "[INFO] 自動承認モードが有効なため、無条件で承認します。\n";
        }
        $approved = true;
    } else {
        // AI審査を行うため、まずキャッシュを検索
        $body_hash = hash('sha256', $model . "\n" . trim($body));
        $cache = db_get1(
            "SELECT * FROM comment_audit_cache WHERE body_hash = ?",
            [$body_hash],
            'main'
        );
        
        if ($cache) {
            echo "[INFO] キャッシュされた審査結果を適用します。(結果: {$cache['result']})\n";
            $approved = ($cache['result'] === 'approved');
        } else {
            // キャッシュがなければOpenRouter APIを叩く
            $audit_res = check_comment_with_openrouter($body, $api_key, $model);
            
            if ($audit_res === 'error') {
                $error_count++;
                echo "[WARNING] コメントID {$comment_id} の審査中にエラーが発生したため、判定結果を変更せず保留します。\n";
                continue;
            }
            
            $approved = ($audit_res === 'approved');
            
            // 結果をキャッシュに保存
            $cache_result = $approved ? 'approved' : 'ng';
            db_begin();
            try {
                db_exec(
                    "INSERT OR REPLACE INTO comment_audit_cache (body_hash, result, reason, ctime) VALUES (?, ?, ?, ?)",
                    [$body_hash, $cache_result, 'AI Audit', time()],
                    'main'
                );
                db_commit();
                echo "[INFO] 審査結果をキャッシュに保存しました。\n";
            } catch (Exception $e) {
                db_rollback();
                echo "[WARNING] キャッシュの保存に失敗しました: " . $e->getMessage() . "\n";
            }
        }
    }
    
    $status = $approved ? 'approved' : 'ng';
    
    db_begin();
    try {
        db_exec(
            "UPDATE comments SET status = ?, mtime = ? WHERE comment_id = ?",
            [$status, time(), $comment_id],
            'main'
        );
        if ($status === 'approved') {
            db_exec(
                "UPDATE apps SET comment_count = comment_count + 1 WHERE app_id = ?",
                [$c['app_id']],
                'main'
            );
        } elseif (intval($c['user_id']) > 0) {
            db_exec(
                'INSERT INTO comment_user_blocks (user_id, ng_count, blocked, mtime) VALUES (?, 1, 0, ?) ' .
                'ON CONFLICT(user_id) DO UPDATE SET ng_count = ng_count + 1, mtime = excluded.mtime',
                [$c['user_id'], time()],
                'main'
            );
            db_exec(
                'UPDATE comment_user_blocks SET blocked = 1 WHERE user_id = ? AND ng_count >= 3',
                [$c['user_id']],
                'main'
            );
        }
        db_commit();
        echo "[SUCCESS] ステータスを '{$status}' に更新しました。\n";
    } catch (Exception $e) {
        db_rollback();
        echo "[ERROR] DB更新に失敗しました: " . $e->getMessage() . "\n";
    }
}

// N件中N件すべてがエラーになった場合のメール通知処理
if ($total_processed > 0 && $error_count === $total_processed) {
    $admin_email = n3s_get_config('admin_email', '');
    if (!empty($admin_email)) {
        $mail_from = n3s_get_config('mail_from', $admin_email);
        $header = "".
            "From: $mail_from\r\n".
            "Reply-To: $admin_email\r\n".
            "Content-Transfer-Encoding: 8bit\r\n";
        $subject = "[nako3storage] OpenRouter API 審査エラー通知";
        
        $script_path = __FILE__;
        $server_name = gethostname();
        $body = "OpenRouter APIの状態を確認してください。({$script_path})({$server_name})\n";
        
        @mb_send_mail($admin_email, $subject, $body, $header);
        echo "[INFO] すべての判定がエラーになったため、管理者にアラートメールを送信しました。\n";
    } else {
        echo "[WARNING] 全件エラーになりましたが、admin_email が未設定のため、メール通知をスキップしました。\n";
    }
}

echo "[INFO] コメント審査バッチが完了しました。\n";
