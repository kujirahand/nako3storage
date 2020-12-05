<?php
function n3s_web_fav()
{
    echo_fav();
}
function n3s_api_fav()
{
    echo_fav();
}

function echo_fav() {
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
        $r = $db->query("SELECT fav,fav_lastip FROM apps WHERE app_id={$app_id}")->fetch();
        if ($q === 'up') {
            $ip = $_SERVER["REMOTE_ADDR"];
            if ($r['fav_lastip'] != $ip) {
                $db->query('begin');
                $db->query("UPDATE apps SET fav=fav+1 WHERE app_id={$app_id}");
                $stmt = $db->prepare("UPDATE apps SET fav_lastip=?  WHERE app_id={$app_id}");
                $stmt->execute([$ip]);
                $r = $db->query("SELECT fav,fav_lastip FROM apps WHERE app_id={$app_id}")->fetch();
                $db->query('commit');
            }
            echo $r['fav'];
        } else {
            echo $r['fav'];
        }
    } catch (Exception $e) {
        echo "<pre>";
        print_r($e);
        echo "0";
    }
  }
