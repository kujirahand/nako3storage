<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

function n3s_web_bad()
{
    global $n3s_config;
    $page = $n3s_config['page'];
    if ($page === 'about') {
        n3s_template_fw('bad_about.html', []);
        return;
    }
    echo_bad();
}
function n3s_api_bad()
{
    echo_bad();
}

function echo_bad()
{
    global $n3s_config;
    $app_id = (int) (empty($_GET['page']) ? '0' : $_GET['page']);
    $q = empty($_GET['q']) ? 'view' : $_GET['q'];
    if ($app_id <= 0) {
        echo "0";
        return;
    }
    // db
    try {
        $r = db_get1('SELECT title,author,bad FROM apps WHERE app_id=?', [$app_id]);
        if ($q === 'up') {
            if (! n3s_is_login()) {
                echo "error, please login.";
                return;
            }
            // 状態変更なので POST + edit_token を必須とする (CSRF対策, #284)
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !n3s_checkEditToken()) {
                echo "error, invalid token.";
                return;
            }
            $ip = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : '';
            $title = $r['title'];
            $author = $r['author'];
            $user_id = n3s_get_user_id();
            // 通報は1ユーザーが1作品につき1回だけ加算する (#286)。
            // broken_reports と同じ方式で、報告者を bad_reports に記録する。
            // (以前は apps.fav_lastip で直前のIPだけを見ていたため、複数の
            // IP・アカウントを交互に使うと加算し続けられた)
            db_begin();
            db_exec('INSERT OR IGNORE INTO bad_reports (app_id, user_id, ip, ctime) VALUES (?,?,?,?)',
                [$app_id, $user_id, $ip, time()]);
            $c = db_get1('SELECT changes() AS c');
            $added = ($c && intval($c['c']) > 0);
            if ($added) {
                // 管理者の通報は100人分
                $up_count = n3s_is_admin() ? 100 : 1;
                // 通報数を加算する
                db_exec('UPDATE apps SET bad=bad+? WHERE app_id=?', [$up_count, $app_id]);
            }
            $r = db_get1('SELECT bad FROM apps WHERE app_id=?', [$app_id]);
            db_commit();
            if ($added) {
                // 通報があった旨をメールする
                $admin_email = n3s_get_config('admin_email', '');
                if ($admin_email != '') {
                    $app_root_url = n3s_get_config('app_root_url', 'http://'.$_SERVER['HTTP_HOST'].'/');
                    $mail_from = n3s_get_config('mail_from', $admin_email);
                    $header = "".
                        "From: $mail_from\r\n".
                        "Reply-To: $admin_email\r\n".
                        "Content-Transfer-Encoding: 8bit\r\n";
                    $subject = "[nako3storage] 通報がありました";
                    $body = "■通報情報:\r\n".
                        "(app_id: $app_id) {$title} by {$author}\r\n".
                        "→通報者: {$ip}\r\n".
                        "{$app_root_url}id.php?{$app_id}\r\n";
                    @mb_send_mail($admin_email, $subject, $body, $header);
                }
            }
            echo $r['bad'];
        } else {
            echo $r['bad'];
        }
    } catch (Exception $e) {
        echo "<pre>";
        print_r($e);
        echo "0";
    }
}
