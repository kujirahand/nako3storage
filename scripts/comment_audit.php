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
$api_key = isset($n3s_config['gemini_api_key']) ? $n3s_config['gemini_api_key'] : '';
$auto_approve = isset($n3s_config['comment_audit_auto_approve']) ? $n3s_config['comment_audit_auto_approve'] : false;

// APIキーが空、または自動承認（auto_approve）が有効な場合は審査をパスする
$skip_ai_and_approve = empty($api_key) || $auto_approve;

if (!function_exists('check_comment_with_gemini')) {
    /**
     * Gemini API を使ってコメントを審査する関数
     * 戻り値: 'approved' | 'ng' | 'error'
     */
    function check_comment_with_gemini($body, $api_key)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=" . urlencode($api_key);
        
        $prompt = "以下のコメントが、プログラミング投稿共有サイトのコメントとして適切か判断してください。いたずら、スパム、他者への誹謗中傷、不適切な言葉、過度な個人情報などが含まれる場合は不承認としてください。\n\n" .
                  "コメント内容:\n\"\"\"\n" . $body . "\n\"\"\"\n\n" .
                  "返答は必ず以下のJSONフォーマットのみで返してください。余計な説明やマークダウンの囲み（```json など）は一切含めず、純粋なJSON文字列としてください。\n" .
                  "{\"approved\": true} または {\"approved\": false}";

        $data = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt]
                    ]
                ]
            ],
            "generationConfig" => [
                "responseMimeType" => "application/json"
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 接続タイムアウト 10秒
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);        // 全体タイムアウト 30秒
        
        $response = curl_exec($ch);
        
        $curl_error = curl_errno($ch);
        $curl_error_msg = curl_error($ch);
        
        if (PHP_VERSION_ID < 80000 && is_resource($ch)) {
            curl_close($ch);
        }
        
        if ($curl_error) {
            echo "[ERROR] API接続エラー: " . $curl_error_msg . "\n";
            return 'error';
        }
        
        $res_data = json_decode($response, true);
        if (isset($res_data['error'])) {
            $code = isset($res_data['error']['code']) ? $res_data['error']['code'] : 500;
            $msg = isset($res_data['error']['message']) ? $res_data['error']['message'] : 'Unknown error';
            echo "[ERROR] APIエラーレスポンス (code: {$code}): {$msg}\n";
            return 'error';
        }
        
        if (isset($res_data['candidates'][0]['content']['parts'][0]['text'])) {
            $text = trim($res_data['candidates'][0]['content']['parts'][0]['text']);
            $json = json_decode($text, true);
            if (isset($json['approved'])) {
                return $json['approved'] ? 'approved' : 'ng';
            }
        }
        
        $snippet = substr($response, 0, 500);
        echo "[WARNING] API応答が解析できませんでした。応答(先頭500文字):\n" . $snippet . "\n";
        return 'error';
    }
}

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
            echo "[INFO] gemini_api_key が設定されていないため、審査をパスして自動承認（公開）します。\n";
        } else {
            echo "[INFO] 自動承認モードが有効なため、無条件で承認します。\n";
        }
        $approved = true;
    } else {
        // AI審査を行うため、まずキャッシュを検索
        $body_hash = hash('sha256', trim($body));
        $cache = db_get1(
            "SELECT * FROM comment_audit_cache WHERE body_hash = ?",
            [$body_hash],
            'main'
        );
        
        if ($cache) {
            echo "[INFO] キャッシュされた審査結果を適用します。(結果: {$cache['result']})\n";
            $approved = ($cache['result'] === 'approved');
        } else {
            // キャッシュがなければGemini APIを叩く
            $audit_res = check_comment_with_gemini($body, $api_key);
            
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
        $subject = "[nako3storage] Gemini API 審査エラー通知";
        
        $script_path = __FILE__;
        $server_name = gethostname();
        $body = "Gemini APIの状態を確認するようにして。({$script_path})({$server_name})\n";
        
        @mb_send_mail($admin_email, $subject, $body, $header);
        echo "[INFO] すべての判定がエラーになったため、管理者にアラートメールを送信しました。\n";
    } else {
        echo "[WARNING] 全件エラーになりましたが、admin_email が未設定のため、メール通知をスキップしました。\n";
    }
}

echo "[INFO] コメント審査バッチが完了しました。\n";
