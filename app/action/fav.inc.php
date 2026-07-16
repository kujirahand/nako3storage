<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

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
    // 誰がお気に入りしたか見られないようにする。数だけを報告する。
    $html = "<ul>";
    foreach ($rows as $r) {
        $user_id = $r['user_id'];
        $u = n3s_getUserInfo($user_id);
        if (!isset($u['user_id'])) {
            continue; // 見当たらない
        }
        $html .= "<li>⭐ ← 😊</li>";
        /*
        // もし、ユーザーを特定させたい場合は以下を利用する
        $name= $u['name'];
        $img = $u['profile_url'];
        $html .= "<li>".
      "<a style='text-decoration:none;' href='index.php?action=user&user_id=$user_id'>".
      "<img src='$img' width=32> {$name}</a>".
      "</li>";
        */
    }
    $html .= "</ul>";
    $html .= "<p><a href='$backURL'>→戻る</a></p>";
    n3s_info(
        "($app_id)を気に入っている人が星の数だけいます！",
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
    // (以前は 'vew' というタイポのせいで view 扱いにならず、
    //  未ログインでも q=up の加算処理に到達してしまうバグがあった)
    if (!n3s_is_login()) {
        $q = 'view';
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
        'SELECT fav,user_id FROM apps WHERE app_id=?',
        [$app_id]
    );
    if (!isset($r['fav'])) {
        echo "0";
        return;
    }
    $fav = $r['fav'];
    $app_user_id = intval($r['user_id']);
    $user_id = n3s_get_user_id();
    $bookmark = db_get1(
        'SELECT * FROM bookmarks '.
    'WHERE app_id=? AND user_id=?',
        [$app_id, $user_id]
    );
    if (isset($bookmark['bookmark_id'])) {
        // delete
        $bookmark_id = $bookmark['bookmark_id'];
        db_begin();
        try {
            // fav+bookmarkの2更新をまとめて1トランザクションにし、ロック取得回数を減らす。
            // read-then-writeではなくfav=fav-1で原子的に減算し、同時書き込みでの更新ロストを防ぐ。
            db_exec(
                'UPDATE apps SET fav=fav-1 WHERE app_id=?',
                [$app_id]
            );
            db_exec(
                'DELETE FROM bookmarks WHERE bookmark_id=?',
                [$bookmark_id]
            );
            db_commit();
        } catch (Exception $e) {
            db_rollback();
            throw $e;
        }
        $fav--;
        echo_fav_result(true, $fav, 0);
        return;
    }
    // 自分の作品にはお気に入り登録できない
    if ($app_user_id === intval($user_id)) {
        echo_fav_result(false, $fav, 0, '自分の作品にはお気に入り登録できません。');
        return;
    }
    // insert
    db_begin();
    try {
        db_exec(
            'UPDATE apps SET fav=fav+1 WHERE app_id=?',
            [$app_id]
        );
        db_exec(
            'INSERT INTO bookmarks (app_id, user_id, ctime)'.
  'VALUES(?,?,?)',
            [$app_id, $user_id, time()]
        );
        db_commit();
    } catch (Exception $e) {
        db_rollback();
        throw $e;
    }
    $fav++;
    echo_fav_result(true, $fav, 1);
}

// q=up の応答。数値だけでは「自分の作品なので登録できなかった」ことを
// クライアントに伝えられないため、JSON形式で返す。
// (呼び出し元は app/resource/nako3storage_edit.js の fav_button のみ)
function echo_fav_result($result, $fav, $bookmark, $msg = '')
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'result' => $result,
        'fav' => $fav,
        'bookmark' => $bookmark,
        'msg' => $msg,
    ], JSON_UNESCAPED_UNICODE);
}
