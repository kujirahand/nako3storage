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
        'prev_link'=>$prev_link
    ]);
}
