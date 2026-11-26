<?php
// ========================================================
// nako3storage app/comment_audit_run.inc.php
// コメントのAI審査本体 (scripts/comment_audit.php と管理画面から共用, #290)
// ========================================================

require_once __DIR__ . '/comment_audit_openrouter.inc.php';

if (!function_exists('n3s_comment_audit_run')) {
    /**
     * 審査待ち (pending) のコメントをすべて審査して反映する。
     *
     * @param callable|null $logger 進捗メッセージ(文字列)を受け取る関数。null なら出力しない
     * @param bool $lock_held 呼び出し側で既に多重起動ロックを取得済みなら true
     * @return array ['locked'=>bool,'total'=>int,'approved'=>int,'ng'=>int,'errors'=>int]
     */
    function n3s_comment_audit_run($logger = null, $lock_held = false)
    {
        $log = function ($msg) use ($logger) {
            if ($logger !== null) {
                call_user_func($logger, $msg);
            }
        };
        $result = ['locked' => false, 'total' => 0, 'approved' => 0, 'ng' => 0, 'errors' => 0];

        // 多重起動防止 (#286)
        $own_lock = null;
        if (!$lock_held) {
            $own_lock = n3s_cli_batch_lock('comment_audit');
            if ($own_lock === false) {
                $result['locked'] = true;
                return $result;
            }
        }

        try {
            global $n3s_config;
            $api_key = isset($n3s_config['openrouter_api_key']) ? $n3s_config['openrouter_api_key'] : '';
            $model = n3s_get_config('comment_audit_model', '~typesafe/jev-latest');
            $auto_approve = isset($n3s_config['comment_audit_auto_approve']) ? $n3s_config['comment_audit_auto_approve'] : false;

            // APIキーが空、または自動承認（auto_approve）が有効な場合は審査をパスする
            $skip_ai_and_approve = empty($api_key) || $auto_approve;

            $comments = db_get("SELECT * FROM comments WHERE status = 'pending'", [], 'main');
            if (empty($comments)) {
                $log("[INFO] 審査待ちのコメントはありませんでした。\n");
                return $result;
            }

            $result['total'] = count($comments);
            $log("[INFO] " . $result['total'] . " 件の審査待ちコメントを処理します。\n");

            foreach ($comments as $c) {
                $comment_id = $c['comment_id'];
                $body = $c['body'];

                $approved = false;
                if ($skip_ai_and_approve) {
                    if (empty($api_key)) {
                        $log("[INFO] openrouter_api_key が設定されていないため、審査をパスして自動承認（公開）します。\n");
                    } else {
                        $log("[INFO] 自動承認モードが有効なため、無条件で承認します。\n");
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
                        $log("[INFO] キャッシュされた審査結果を適用します。(結果: {$cache['result']})\n");
                        $approved = ($cache['result'] === 'approved');
                    } else {
                        // キャッシュがなければOpenRouter APIを叩く
                        $audit_res = check_comment_with_openrouter($body, $api_key, $model);

                        if ($audit_res === 'error') {
                            $result['errors']++;
                            $log("[WARNING] コメントID {$comment_id} の審査中にエラーが発生したため、判定結果を変更せず保留します。\n");
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
                            $log("[INFO] 審査結果をキャッシュに保存しました。\n");
                        } catch (Exception $e) {
                            db_rollback();
                            $log("[WARNING] キャッシュの保存に失敗しました: " . $e->getMessage() . "\n");
                        }
                    }
                }

                $status = $approved ? 'approved' : 'ng';

                db_begin();
                try {
                    // 並行実行や再実行で加算が二重にならないよう、pending の行だけを
                    // 更新し、実際に更新された場合のみ加算する (#286)
                    db_exec(
                        "UPDATE comments SET status = ?, mtime = ? WHERE comment_id = ? AND status = 'pending'",
                        [$status, time(), $comment_id],
                        'main'
                    );
                    $chg = db_get1('SELECT changes() AS c', [], 'main');
                    $changed = ($chg && intval($chg['c']) > 0);
                    if ($changed && $status === 'approved') {
                        db_exec(
                            "UPDATE apps SET comment_count = comment_count + 1 WHERE app_id = ?",
                            [$c['app_id']],
                            'main'
                        );
                    } elseif ($changed && intval($c['user_id']) > 0) {
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
                    if ($changed) {
                        if ($status === 'approved') {
                            $result['approved']++;
                        } else {
                            $result['ng']++;
                        }
                        $log("[SUCCESS] ステータスを '{$status}' に更新しました。\n");
                    } else {
                        $log("[INFO] コメント {$comment_id} は既に処理済みのためスキップしました。\n");
                    }
                } catch (Exception $e) {
                    db_rollback();
                    $log("[ERROR] DB更新に失敗しました: " . $e->getMessage() . "\n");
                }
            }

            // N件中N件すべてがエラーになった場合のメール通知処理
            if ($result['total'] > 0 && $result['errors'] === $result['total']) {
                $admin_email = n3s_get_config('admin_email', '');
                if (!empty($admin_email)) {
                    $mail_from = n3s_get_config('mail_from', $admin_email);
                    $header = "" .
                        "From: $mail_from\r\n" .
                        "Reply-To: $admin_email\r\n" .
                        "Content-Transfer-Encoding: 8bit\r\n";
                    $subject = "[nako3storage] OpenRouter API 審査エラー通知";

                    $server_name = gethostname();
                    $mail_body = "OpenRouter APIの状態を確認してください。(" . __FILE__ . ")({$server_name})\n";

                    @mb_send_mail($admin_email, $subject, $mail_body, $header);
                    $log("[INFO] すべての判定がエラーになったため、管理者にアラートメールを送信しました。\n");
                } else {
                    $log("[WARNING] 全件エラーになりましたが、admin_email が未設定のため、メール通知をスキップしました。\n");
                }
            }

            $log("[INFO] コメント審査バッチが完了しました。\n");
            return $result;
        } finally {
            if (is_resource($own_lock)) {
                fclose($own_lock);
            }
        }
    }
}
