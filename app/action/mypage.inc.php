<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');
define('MAX_MYPAGE_FAV_DEF', 5);
define('MAX_MYPAGE_APP', 20);
define('MAX_MYPAGE_MATERIALS', 20);

// no api login
function n3s_api_mypage()
{
    n3s_api_output('ng', ['msg' => 'should use web access']);
}

function n3s_web_mypage()
{
    // ログインが必須
    $login_url = n3s_getURL('my', 'login');
    $logout_url = n3s_getURL('my', 'logout');
    $back = isset($_GET['back']) ? $_GET['back'] : '';
    if ($back == 'list') {
        n3s_setBackURL(n3s_getURL('all', 'list'));
    }
    if (!n3s_is_login()) {
        header('location:' . $login_url);
        exit;
    }
    // ログインが完了してこのページが表示されたところ？
    // そうならばセッションのn3s_on_after_loginをチェック
    if (!empty($_SESSION['n3s_on_after_login'])) {
        $url = $_SESSION['n3s_on_after_login'];
        unset($_SESSION['n3s_on_after_login']);
        header('location:' . $url);
        exit;
    }
    // ユーザー情報を取得
    $user = n3s_get_login_info();
    $user_id = $user['user_id'];
    // リンクページ
    $page = empty($_GET['page']) ? 0 : intval($_GET['page']);
    $offset = MAX_MYPAGE_APP * $page;
    $link_next_page = n3s_getURL($page + 1, 'mypage');
    $link_next_material_page = n3s_getURL($page + 1, 'mypage', ['mode' => 'material']);
    $link_all_fav = n3s_getURL('all', 'mypage', ['fav' => 'all']);
    $link_mypage = n3s_getURL('all', 'mypage', []);
    $link_materil = n3s_getURL('all', 'mypage', ['mode' => 'material']);
    $link_userinfo = n3s_getURL($user_id, 'userinfo', []);
    $link_del_account = n3s_getURL($user_id, 'mypage', ['mode' => 'del_account']);
    // -----------------------------------
    // 表示モードの確認
    // -----------------------------------
    $mode = empty($_GET['mode']) ? 'mypage' : $_GET['mode'];
    // 素材ページ
    if ($mode == 'material') {
        // 素材一覧
        $images = db_get(
            'SELECT * FROM images WHERE user_id=? ORDER BY image_id DESC ' .
                ' LIMIT ? OFFSET ?',
            [$user_id, MAX_MYPAGE_MATERIALS, $offset]
        );
        n3s_template_fw('mymaterial.html', [
            'user_id' => $user_id,
            'name' => $user['name'],
            'logout_url' => $logout_url,
            'user' => $user,
            'images' => $images,
            'url_images' => n3s_get_config('url_images', '/images'),
            'link_all_fav' => $link_all_fav,
            'link_next_page' => $link_next_material_page,
            'link_mypage' => $link_mypage,
            'link_material' => $link_materil,
            'link_userinfo' => $link_userinfo,
            'page' => $page,
        ]);
        return;
    }
    if ($mode == 'del_account') {
        // アカウント削除
        n3s_mypage_mode_del_account($user_id);
        return;
    }

    // 作品一覧を取得
    $apps = db_get('SELECT * FROM apps WHERE user_id=? ORDER BY app_id DESC LIMIT ? OFFSET ?', [$user_id, MAX_MYPAGE_APP, $offset]);
    // お気に入り一覧を取得
    $fav_limit = empty($_GET['fav']) ? MAX_MYPAGE_FAV_DEF : (($_GET['fav'] == 'all') ? 1000 : MAX_MYPAGE_FAV_DEF);
    $bookmark_ids = db_get(
        'SELECT * FROM bookmarks WHERE user_id=? ' .
            'ORDER BY bookmark_id DESC LIMIT ?',
        [$user_id, $fav_limit]
    );
    $bookmarks = [];
    if ($bookmark_ids) {
        foreach ($bookmark_ids as $aid) {
            $a = db_get1(
                'SELECT app_id, title, author FROM apps ' .
                    'WHERE app_id=?',
                [intval($aid['app_id'])]
            );
            $bookmarks[] = $a;
        }
    }
    // ユーザー情報
    n3s_template_fw('mypage.html', [
        'user_id' => $user_id,
        'name' => $user['name'],
        'apps' => $apps,
        'logout_url' => $logout_url,
        'user' => $user,
        'url_images' => n3s_get_config('url_images', '/images'),
        'bookmarks' => $bookmarks,
        'link_all_fav' => $link_all_fav,
        'link_next_page' => $link_next_page,
        'link_mypage' => $link_mypage,
        'link_userinfo' => $link_userinfo,
        'link_del_account' => $link_del_account,
        'page' => $page,
        'link_material' => $link_materil,
    ]);
}

// アカウント削除
function n3s_mypage_mode_del_account($user_id)
{
    $confirm = empty($_POST['confirm']) ? '' : $_POST['confirm'];
    $token = n3s_getEditToken("del_account__$user_id", FALSE);
    $token_post = empty($_POST['token']) ? '' : $_POST['token'];
    $link_del_account = n3s_getURL(
        $user_id,
        'mypage',
        [
            'mode' => 'del_account',
        ]
    );
    $link_mypage = n3s_getURL($user_id, 'mypage');
    // check user_id
    $user = n3s_getUserInfo($user_id);
    if (!$user) {
        n3s_error('ユーザーIDがありません', '', TRUE);
        return;
    }
    $user_name = $user['name'];
    // 添付ファイルの列挙
    $files = db_get('SELECT * FROM images WHERE user_id=?', [$user_id]);
    $files_html = "<h3>削除対象のファイル</h3>\n<ul>\n";
    $files_full = [];
    foreach ($files as $file) {
        $image_id = $file['image_id'];
        $imageDir = n3s_getImageDir($image_id);
        $filename = $file['filename'];
        $fullpath = "$imageDir/$filename";
        $title = htmlspecialchars($file['title'], ENT_QUOTES);
        $files_full[] = [
            "image_id" => $image_id,
            "filename" => $fullpath,
        ];
        $url = n3s_getURL($user_id, 'upload', ['mode' => 'show', 'image_id' => $image_id]);
        $files_html .= "<li><a href='$url'>$title - $filename</li>\n";
    }
    if (count($files) == 0) {
        $files_html .= "<li>なし</li>\n";
    }
    $files_html .= "</ul>\n";
    // 作品一覧
    $apps = db_get('SELECT * FROM apps WHERE user_id=?', [$user_id]);
    $apps_html = "<h3>削除対象の作品</h3>\n<ul>\n";
    $apps_full = [];
    foreach ($apps as $app) {
        $app_id = $app['app_id'];
        $material_id = $app['material_id'];
        $title = htmlspecialchars($app['title'], ENT_QUOTES);
        $url = n3s_getURL($app_id, 'show');
        $apps_html .= "<li><a href='$url'>($app_id) $title</a></li>\n";
        $apps_full[] = [
            "app_id" => $app_id,
            "material_id" => $material_id,
            "dbname" => n3s_getMaterialDB($app_id),
            "title" => $app['title'],
        ];
    }
    if (count($apps) == 0) {
        $apps_html .= "<li>なし</li>\n";
    }
    $apps_html .= "</ul>\n";
    // 退会処理
    // 確認画面 -> 実行
    if ($confirm == 'yes') {
        if ($token != $token_post) {
            n3s_error(
                '退会トークンのエラー',
                "<a href='$link_del_account'>こちらから再度お試しください。($token_post)</a>",
                TRUE
            );
            return;
        }
        // === 退会処理 ===
        // ログに追加
        n3s_log("+ ($user_id)『{$user_name}』が退会(n3s_mypage_mode_del_account)", '退会', 1);
        // 添付ファイルの削除
        foreach ($files_full as $file) {
            $image_id = $file['image_id'];
            $filename = $file['filename'];
            $file_title = $file['title'];
            if (file_exists($filename)) {
                @unlink($filename);
            }
            $title = '(ユーザー退会のため削除されました)';
            db_exec('UPDATE images SET title=?, filename="53.png" WHERE image_id=?', [$title, $image_id]);
            n3s_log("- 素材($image_id)『{$file_title}』($filename)を削除", '退会');
        }
        // 作品情報の削除
        foreach ($apps_full as $app) {
            $app_id = $app['app_id'];
            $material_id = $app['material_id'];
            $app_title = $app['title'];
            if ($material_id == 0) {
                $material_id = $app_id;
            }
            $date = date('Y-m-d H:i:s');
            $body =
                "# この作品はユーザー退会により削除されました。\n" .
                "# 退会ID={$user_id}\n" .
                "# 退会日時={$date}\n" .
                "「申し訳ありません。この作品は削除されました。」と表示。\n";
            $dbname = n3s_getMaterialDB($material_id);
            $ok = db_exec('UPDATE materials SET body=? WHERE material_id=?', [$body, $material_id], $dbname);
            if (!$ok) {
                n3s_error('作品情報の削除に失敗しました', '', TRUE);
                return;
            }
            n3s_log("- 作品削除($app_id)『{$app_title}』", "退会");
        }
        // お気に入り情報の削除
        db_exec('DELETE FROM bookmarks WHERE user_id=?', [$user_id]);
        // ユーザーの変更
        $pw = generatePassword();
        $email = generatePassword();
        $token = generatePassword();
        db_exec(
            'UPDATE users SET name="(退会ユーザー)", email=?, password=?, login_token=? WHERE user_id=?',
            [
                $email,
                $pw,
                $token,
                $user_id
            ],
            'users'
        );
        // 作品インデックスを変更
        $title = '(削除されました)';
        $memo = 'この作品はユーザー退会により削除されました。';
        $author = '(退会ユーザー)';
        $tag = 'w_noname'; // リストに表示しないようにする
        db_exec(
            'UPDATE apps SET title=?, author=?, memo=?, email=?, tag=? WHERE user_id=?',
            [$title, $author, $memo, $email, $tag, $user_id]
        );
        // ログアウト
        n3s_logout();
        n3s_info('退会しました', 'またのご利用をお待ちしております。', TRUE);
        exit;
    }
    // 確認画面
    $title = '貯蔵庫から退会（アカウントを削除）しますか？';
    $msg = <<< __EOS__
<div class="mypage_box">
    <p>
        <span style="background-color: yellow; font-weight: bold;">
        貯蔵庫から退会すると、ユーザー情報が全て削除されます。</span>
    </p>
    <p>
        本当に退会する場合は、以下にチェックを入れてください。
    </p>
    <form method="post" action="{$link_del_account}">
        <div style="margin-left: 1em;">
            <input id="confirm" type="checkbox" name="confirm" value="yes">
            <label style="color: black; font-size: 1em;" for="confirm">アカウントを削除する</label>
            <input type="hidden" name="token" value="{$token}">
        </div>
        <p><input type="submit" value="退会する"></p>
    </form>
</div>
<div class="mypage_box">
    <a href="{$link_mypage}">→退会しない</a>
</div>
<div class="mypage_box">
{$apps_html}
</div>
<div class="mypage_box">
{$files_html}
</div>
__EOS__;
    n3s_info($title, $msg, TRUE);
}

function generatePassword($length = 16)
{
    return substr(bin2hex(random_bytes($length)), 0, $length);
}
