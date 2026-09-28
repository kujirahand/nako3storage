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
        $notice = 'ユーザーID ' . $user_id . ' のコメント投稿ブロックを解除しました。';
    }

    $ng_comments = db_get("SELECT comment_id, user_id, app_id, body, ctime FROM comments WHERE status = 'ng' ORDER BY comment_id DESC LIMIT 100", [], 'main');
    if (!$ng_comments) { $ng_comments = []; }
    $blocked_users = db_get('SELECT user_id, ng_count, mtime FROM comment_user_blocks WHERE blocked = 1 ORDER BY mtime DESC LIMIT 100', [], 'main');
    if (!$blocked_users) { $blocked_users = []; }
    $user_names = [];
    foreach (array_merge($ng_comments, $blocked_users) as $row) {
        $id = intval($row['user_id']);
        if ($id > 0 && !isset($user_names[$id])) {
            $user = db_get1('SELECT name FROM users WHERE user_id = ?', [$id], 'users');
            $user_names[$id] = $user ? $user['name'] : '(退会済み)';
        }
    }
    foreach ($ng_comments as &$row) {
        $row['user_name'] = isset($user_names[intval($row['user_id'])]) ? $user_names[intval($row['user_id'])] : '(不明)';
    }
    unset($row);
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
        'ng_comments'=>$ng_comments,
        'blocked_users'=>$blocked_users,
        'edit_token'=>n3s_getEditToken(),
        'notice'=>$notice
    ]);
}
