<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

// 「作品が動かない」報告 (#267)
function n3s_web_broken()
{
    echo_broken();
}
function n3s_api_broken()
{
    echo_broken();
}

function echo_broken()
{
    $app_id = (int) (empty($_REQUEST['page']) ? '0' : $_REQUEST['page']);
    $q = empty($_REQUEST['q']) ? 'view' : $_REQUEST['q'];
    if ($app_id <= 0) {
        echo "0";
        return;
    }
    try {
        $r = db_get1('SELECT broken_report,broken_lastip FROM apps WHERE app_id=?', [$app_id]);
        if (!$r) {
            echo "0";
            return;
        }
        if ($q === 'up') {
            // 報告はDBを更新するため、CSRF対策としてPOST + edit_tokenを必須にする
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo "error, must use POST.";
                return;
            }
            if (! n3s_checkEditToken()) {
                echo "error, invalid token.";
                return;
            }
            if (! n3s_is_login()) {
                echo "error, please login.";
                return;
            }
            $ip = $_SERVER["REMOTE_ADDR"];
            // for test ?
            $ip_a = explode('.', $ip.'.0.0.0.0');
            if (($ip_a[0] === '192' && $ip_a[1] === '168') ||
                ($ip_a[0] === '100' && $ip_a[1] === '115')) {
                $ip = time(); // かぶらないように
            }
            if (n3s_is_admin()) {
                $ip = time();
            }
            if ($r['broken_lastip'] !== $ip) {
                // 管理者の報告は一発で+3
                $up_count = n3s_is_admin() ? 3 : 1;
                db_begin();
                db_exec('UPDATE apps SET broken_report=broken_report+? WHERE app_id=?', [$up_count, $app_id]);
                db_exec('UPDATE apps SET broken_lastip=? WHERE app_id=?', [$ip, $app_id]);
                $r = db_get1('SELECT broken_report,broken_lastip FROM apps WHERE app_id=?', [$app_id]);
                db_commit();
            }
            echo $r['broken_report'];
        } else {
            echo $r['broken_report'];
        }
    } catch (Exception $e) {
        echo "<pre>";
        print_r($e);
        echo "0";
    }
}
