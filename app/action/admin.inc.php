<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');
define('LOG_COUNT', 100);

// no api login
function n3s_api_admin()
{
    n3s_api_output('ng', ['msg' => 'should use web access']);
}

function n3s_web_admin()
{
    if (!n3s_is_admin()) {
        n3s_error('admin only', '管理者専用のページです');
        return;
    }
    $notice = '';
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode']) && $_POST['mode'] === 'unblock_comment') {
        if (!n3s_checkEditToken()) {
            n3s_error('操作エラー', 'トークンが一致しません。');
            return;
        }
        $user_id = filter_var(isset($_POST['user_id']) ? $_POST['user_id'] : '', FILTER_VALIDATE_INT);
        if ($user_id === false || $user_id <= 0) {
            n3s_error('操作エラー', 'ユーザーIDが不正です。');
            return;
        }
        db_exec('UPDATE comment_user_blocks SET blocked = 0, ng_count = 0, mtime = ? WHERE user_id = ? AND blocked = 1', [time(), $user_id], 'main');
        // 管理操作の監査ログを残す (#286)
        $admin_id = n3s_get_user_id();
        n3s_log("user_id={$user_id} のコメントブロックを解除 (実施者 user_id={$admin_id})", 'admin_unblock_comment', 1);
        $notice = 'ユーザーID ' . $user_id . ' のコメント投稿ブロックを解除しました。';
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode']) && $_POST['mode'] === 'review_comments') {
        // 審査待ちコメントの手動反映 (#290)。AI審査バッチと同じ副作用を持つ
        if (!n3s_checkEditToken()) {
            n3s_error('操作エラー', 'トークンが一致しません。');
            return;
        }
        $comment_ids = isset($_POST['comment_ids']) && is_array($_POST['comment_ids']) ? $_POST['comment_ids'] : [];
        $ng_ids_raw = isset($_POST['ng_ids']) && is_array($_POST['ng_ids']) ? $_POST['ng_ids'] : [];
        $ng_ids = [];
        foreach ($ng_ids_raw as $v) {
            $v = filter_var($v, FILTER_VALIDATE_INT);
            if ($v !== false && $v > 0) { $ng_ids[$v] = true; }
        }
        $ok_count = 0;
        $ng_count = 0;
        db_begin();
        try {
            foreach (array_unique($comment_ids) as $v) {
                $cid = filter_var($v, FILTER_VALIDATE_INT);
                if ($cid === false || $cid <= 0) { continue; }
                $c = db_get1("SELECT comment_id, user_id, app_id FROM comments WHERE comment_id = ? AND status = 'pending'", [$cid], 'main');
                if (!$c) { continue; }
                $status = isset($ng_ids[$cid]) ? 'ng' : 'approved';
                db_exec("UPDATE comments SET status = ?, mtime = ? WHERE comment_id = ? AND status = 'pending'", [$status, time(), $cid], 'main');
                $chg = db_get1('SELECT changes() AS c', [], 'main');
                if (!$chg || intval($chg['c']) <= 0) { continue; }
                if ($status === 'approved') {
                    db_exec('UPDATE apps SET comment_count = comment_count + 1 WHERE app_id = ?', [$c['app_id']], 'main');
                    $ok_count++;
                } else {
                    $ng_count++;
                    if (intval($c['user_id']) > 0) {
                        db_exec(
                            'INSERT INTO comment_user_blocks (user_id, ng_count, blocked, mtime) VALUES (?, 1, 0, ?) ' .
                            'ON CONFLICT(user_id) DO UPDATE SET ng_count = ng_count + 1, mtime = excluded.mtime',
                            [$c['user_id'], time()],
                            'main'
                        );
                        db_exec('UPDATE comment_user_blocks SET blocked = 1 WHERE user_id = ? AND ng_count >= 3', [$c['user_id']], 'main');
                    }
                }
            }
            db_commit();
        } catch (Exception $e) {
            db_rollback();
            n3s_error('操作エラー', '反映に失敗しました。');
            return;
        }
        if ($ok_count + $ng_count > 0) {
            $admin_id = n3s_get_user_id();
            n3s_log("審査待ちコメントを手動反映 (承認 {$ok_count} 件、NG {$ng_count} 件, 実施者 user_id={$admin_id})", 'admin_review_comments', 1);
        }
        $notice = '審査待ちコメントを反映しました（承認 ' . $ok_count . ' 件、NG ' . $ng_count . ' 件）';
    }
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode']) && $_POST['mode'] === 'replace_comment_body') {
        // 公開中コメントの本文を削除文に置き換える (#290)
        if (!n3s_checkEditToken()) {
            n3s_error('操作エラー', 'トークンが一致しません。');
            return;
        }
        $comment_id = filter_var(isset($_POST['comment_id']) ? $_POST['comment_id'] : '', FILTER_VALIDATE_INT);
        if ($comment_id === false || $comment_id <= 0) {
            n3s_error('操作エラー', 'コメントIDが不正です。');
            return;
        }
        db_exec("UPDATE comments SET body = ?, mtime = ? WHERE comment_id = ? AND status = 'approved'", ['(すみません削除されました)', time(), $comment_id], 'main');
        $chg = db_get1('SELECT changes() AS c', [], 'main');
        if (!$chg || intval($chg['c']) <= 0) {
            n3s_error('操作エラー', '対象の公開中コメントが見つかりません。');
            return;
        }
        $admin_id = n3s_get_user_id();
        n3s_log("comment_id={$comment_id} の本文を置き換え (実施者 user_id={$admin_id})", 'admin_replace_comment_body', 1);
        $notice = 'コメントID ' . $comment_id . ' の本文を置き換えました。';
    }

    $pending_comments = db_get("SELECT comment_id, user_id, app_id, body, ctime FROM comments WHERE status = 'pending' ORDER BY comment_id DESC LIMIT 100", [], 'main');
    if (!$pending_comments) { $pending_comments = []; }
    $approved_comments = db_get("SELECT comment_id, user_id, app_id, body, ctime FROM comments WHERE status = 'approved' ORDER BY comment_id DESC LIMIT 100", [], 'main');
    if (!$approved_comments) { $approved_comments = []; }
    $ng_comments = db_get("SELECT comment_id, user_id, app_id, body, ctime FROM comments WHERE status = 'ng' ORDER BY comment_id DESC LIMIT 100", [], 'main');
    if (!$ng_comments) { $ng_comments = []; }
    $blocked_users = db_get('SELECT user_id, ng_count, mtime FROM comment_user_blocks WHERE blocked = 1 ORDER BY mtime DESC LIMIT 100', [], 'main');
    if (!$blocked_users) { $blocked_users = []; }
    $user_names = [];
    foreach (array_merge($pending_comments, $approved_comments, $ng_comments, $blocked_users) as $row) {
        $id = intval($row['user_id']);
        if ($id > 0 && !isset($user_names[$id])) {
            $user = db_get1('SELECT name FROM users WHERE user_id = ?', [$id], 'users');
            $user_names[$id] = $user ? $user['name'] : '(退会済み)';
        }
    }
    foreach ([&$pending_comments, &$approved_comments, &$ng_comments] as &$list) {
        foreach ($list as &$row) {
            $row['user_name'] = isset($user_names[intval($row['user_id'])]) ? $user_names[intval($row['user_id'])] : '(不明)';
        }
        unset($row);
    }
    unset($list);
    foreach ($blocked_users as &$row) {
        $row['user_name'] = isset($user_names[intval($row['user_id'])]) ? $user_names[intval($row['user_id'])] : '(不明)';
    }
    unset($row);
    $offset = empty($_GET['offset']) ? 0 : intval($_GET['offset']);
    $logs = db_get('SELECT * FROM logs ORDER BY log_id DESC LIMIT ? OFFSET ?', [LOG_COUNT, $offset], 'log');
    if (!$logs) { $logs = []; }
    $next_link = n3s_getURL('', 'admin', ['offset'=>$offset+LOG_COUNT]);
    $prev_link = n3s_getURL('', 'admin', ['offset' => $offset-LOG_COUNT]);
    if ($offset < LOG_COUNT) { $prev_link = ''; }
    n3s_template_fw('admin.html', [
        'logs'=>$logs,
        'offset'=>$offset, 
        'next_link'=>$next_link,
        'prev_link'=>$prev_link,
        'pending_comments'=>$pending_comments,
        'approved_comments'=>$approved_comments,
        'ng_comments'=>$ng_comments,
        'blocked_users'=>$blocked_users,
        'edit_token'=>n3s_getEditToken(),
        'notice'=>$notice
    ]);
}
