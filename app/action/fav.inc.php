<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');
@session_start();

function n3s_web_fav()
{
    $m = empty($_GET['m']) ? 'view' : $_GET['m'];
    if ($m == 'who') {
        fav_who();
        return;
    }
    echo_fav();
}
function n3s_api_fav()
{
    echo_fav();
}

function fav_who()
{
    $app_id = intval(empty($_GET['page']) ? '0' : $_GET['page']);
    $rows = db_get(
        'SELECT * FROM bookmarks WHERE app_id=?',
        [$app_id]
    );
    $backURL = "id.php?$app_id";
    if (!$rows) {
        n3s_error(
            '残念',
            'まだ誰も🌟つけていません。'.
      "<a href='{$backURL}'>→戻る</a>",
            true
        );
        return;
    }
    $html = "<ul>";
    foreach ($rows as $r) {
        $user_id = $r['user_id'];
        $u = db_get1(
            'SELECT * FROM users WHERE user_id=?',
            [$user_id]
        );
        if (!isset($u['user_id'])) {
            continue; // 見当たらない
        }
        $name= $u['name'];
        $img = $u['profile_url'];
        $html .= "<li>".
      "<a style='text-decoration:none;' href='index.php?user_id=$user_id&action=list'>".
      "<img src='$img' width=32> {$name}</a>".
      "</li>";
    }
    $html .= "</ul>";
    $html .= "<p><a href='$backURL'>→戻る</a></p>";
    n3s_info(
        "($app_id)を気に入った人🙋",
        $html,
        true
    );
}

function echo_fav()
{
    global $n3s_config;
    $app_id = intval(empty($_GET['page']) ? '0' : $_GET['page']);
    $q = empty($_GET['q']) ? 'view' : $_GET['q'];
    if ($app_id <= 0) {
        echo "0";
        return;
    }
    // ログインしてなければ q は無効
    if (!n3s_is_login()) {
        $q = 'vew';
    }
    // view
    if ($q == 'view') {
        $r = db_get1(
            'SELECT fav FROM apps WHERE app_id=?',
            [$app_id]
        );
        if (isset($r['fav'])) {
            echo $r['fav'];
        } else {
            echo "0";
        }
        return;
    }
    // q=up
    // check app_id exists?
    $r = db_get1(
        'SELECT fav FROM apps WHERE app_id=?',
        [$app_id]
    );
    if (!isset($r['fav'])) {
        echo "0";
        return;
    }
    $fav = $r['fav'];
    $user_id = n3s_get_user_id();
    $bookmark = db_get1(
        'SELECT * FROM bookmarks '.
    'WHERE app_id=? AND user_id=?',
        [$app_id, $user_id]
    );
    if (isset($bookmark['bookmark_id'])) {
        // delete
        $bookmark_id = $bookmark['bookmark_id'];
        $fav--;
        db_exec(
            'UPDATE apps SET fav=? WHERE app_id=?',
            [$fav, $app_id]
        );
        db_exec(
            'DELETE FROM bookmarks WHERE bookmark_id=?',
            [$bookmark_id]
        );
    } else {
        // insert
        $fav++;
        db_exec(
            'UPDATE apps SET fav=? WHERE app_id=?',
            [$fav, $app_id]
        );
        db_exec(
            'INSERT INTO bookmarks (app_id, user_id)'.
      'VALUES(?,?)',
            [$app_id, $user_id]
        );
    }
    echo $fav;
}
