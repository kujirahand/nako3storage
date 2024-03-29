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
    $db = n3s_get_db();
    try {
        $r = $db->query("SELECT title,author,bad,fav_lastip FROM apps WHERE app_id={$app_id}")->fetch();
        if ($q === 'up') {
            if (! n3s_is_login()) {
                echo "error, please login.";
                return;
            }
            $ip = $_SERVER["REMOTE_ADDR"];
            $title = $r['title'];
            $author = $r['author'];
            // for test ?
            $ip_a = explode('.', $ip.'.0.0.0.0');
            if (($ip_a[0] === '192' && $ip_a[1] === '168') ||
                ($ip_a[0] === '100' && $ip_a[1] === '115')) {
                $ip = time(); // かぶらないように
            }
            if (n3s_is_admin()) {
                $ip = time();
            }
            if ($r['fav_lastip'] !== $ip) {
                // 管理者の通報は100人分
                $up_count = 1;
                if (n3s_is_admin()) {
                    $up_count = 100;
                }
                // 通報数を加算する
                $db->query('begin');
                $db->query("UPDATE apps SET bad=bad+{$up_count} WHERE app_id={$app_id}");
                $stmt = $db->prepare("UPDATE apps SET fav_lastip=?  WHERE app_id={$app_id}");
                $stmt->execute([$ip]);
                $r = $db->query("SELECT bad,fav_lastip FROM apps WHERE app_id={$app_id}")->fetch();
                $db->query('commit');
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
