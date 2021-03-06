<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

function n3s_web_bad()
{
  global $n3s_config;
  $page = $n3s_config['page'];
  if ($page == 'about') {
    n3s_template_fw('bad_about.html', []);
    return;
  }
  echo_bad();
}
function n3s_api_bad()
{
    echo_bad();
}

function echo_bad() {
    global $n3s_config;
    $app_id = intval(empty($_GET['page']) ? '0' : $_GET['page']);
    $q = empty($_GET['q']) ? 'view' : $_GET['q'];
    if ($app_id <= 0) {
        echo "0";
        return;
    }
    // db
    $db = n3s_get_db();
    try {
        $r = $db->query("SELECT bad,fav_lastip FROM apps WHERE app_id={$app_id}")->fetch();
        if ($q === 'up') {
            $ip = $_SERVER["REMOTE_ADDR"];
            // for test ?
            $ip_a = explode('.', $ip.'.0.0.0.0');
            if (($ip_a[0] == '192' && $ip_a[1] == '168') ||
                ($ip_a[0] == '100' && $ip_a[1] == '115')) {
                $ip = time(); // かぶらないように
            }
            if ($r['fav_lastip'] != $ip) {
                $db->query('begin');
                $db->query("UPDATE apps SET bad=bad+1 WHERE app_id={$app_id}");
                $stmt = $db->prepare("UPDATE apps SET fav_lastip=?  WHERE app_id={$app_id}");
                $stmt->execute([$ip]);
                $r = $db->query("SELECT bad,fav_lastip FROM apps WHERE app_id={$app_id}")->fetch();
                $db->query('commit');
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
