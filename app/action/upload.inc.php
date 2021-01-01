<?php
include_once dirname(__FILE__) . '/save.inc.php';
include_once dirname(__FILE__) . '/show.inc.php';

function n3s_api_upload() {
    n3s_api_output(FALSE, []);
}

function n3s_web_upload() {
    $mode = isset($_GET['mode']) ? $_GET['mode'] : '';
    if ($mode == 'go') {
        go_upload();
        return;
    }
    if ($mode == 'show') {
        show_image();
        return;
    }
    if ($mode == 'delete') {
        delete_image();
        return;
    }
    if ($mode == 'list') {
        list_image();
        return;
    }
    n3s_template_fw('upload.html', []);
}

function go_upload() {
    // システムをチェック
    $dir_images = n3s_get_config('dir_images', '');
    if (!$dir_images) {
        n3s_error('アップロードできません', 'システムで保存フォルダが設定されていません');
        return;
    }
    // ログインチェック
    if (!n3s_is_login()) {
        n3s_error('アップロードできません', '先にログインしてください。');
        return;
    }
    // パラメータをチェック
    $copyright = isset($_POST['copyright']) ? $_POST['copyright'] : '';
    $title = isset($_POST['title']) ? $_POST['title'] : '';
    // 各種チェック
    if ($copyright != 'cc0') {
        n3s_error('アップロードできません', '著作権に同意しないとアップロードできません。');
        return;
    }
    $title = mb_substr($title, 0, 512); // size_field_max で弾きたいけど、アップロードし直すのは嫌なのでとりあえず適当にトリム
    // ファイルのチェック
    $userfile = isset($_FILES['userfile']) ? $_FILES['userfile'] : [];
    if (!$userfile) {
        n3s_error('アップロード失敗', 'ファイルがありまぜん。');
        return;
    }
    $tmp_name = $userfile['tmp_name'];
    $fname = $userfile['name'];
    $size = $userfile['size'];
    $size_upload_max = n3s_get_config('size_upload_max', 1024 * 1024 * 3);
    if ($size > $size_upload_max) {
        $mb = floor($size_upload_max / 1024 * 1024);
        n3s_error('アップロード失敗', "ファイルサイズが最大の{$mb}MBを超えています。");
        return;
    }
    if ($title == '') {
        $title = basename($fname);
    }
    $fname = strtolower($fname);
    $ext = '.jpg';
    if (preg_match('/(\.(jpg|jpeg|gif|png|mp3|ogg|txt|csv|tsv|json))$/i', $fname, $m)) {
        $ext = strtolower($m[1]);
    } else {
        n3s_error('アップロード失敗', "画像や音声、テキスト形式のファイルのみアップロードできます。");
        return;
    }
    $user_id = n3s_get_user_id();
    //
    $db = database_get();
    db_exec('begin');
    $image_id = db_insert(
        'INSERT INTO images (title,user_id,ctime,mtime)VALUES(?,?,?,?)', 
        [$title, $user_id, time(), time()]);
    $filename = "{$image_id}{$ext}";
    db_exec("UPDATE images SET filename=? WHERE image_id=?", [$filename, $image_id]);
    $path = "{$dir_images}/$filename";
    if (!move_uploaded_file($tmp_name, $path)) {
        db_exec('rollback');
        n3s_error('アップロード失敗', "サーバーにファイル保存に失敗しました。やり直してください。");
        return;
    }
    db_exec('commit');
    $url = n3s_getURL('', 'upload', [
        'mode'=>'show',
        'image_id'=>$image_id]);
    header('location:'.$url);
}

function show_image() {
    // check
    $image_id = isset($_GET['image_id']) ? $_GET['image_id'] : '';
    $db = database_get();
    $im = db_get1('SELECT * FROM images WHERE image_id=? LIMIT 1', [$image_id]);
    if (!$im) {
        n3s_error('ファイルがありません', '指定のファイルはありません。');
        return;
    }
    // url
    $filename = $im['filename'];
    $user_id = $im['user_id'];
    $user = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id]);
    $image_url = n3s_get_config('url_images', 'images')."/{$filename}";
    // can_edit
    $can_edit = FALSE;
    if (n3s_is_admin()) {
        $can_edit = TRUE;
    } else {
        $self_id = n3s_get_user_id();
        if ($self_id == $user_id) {
            $can_edit = TRUE;
        }
    }
    // set token to session
    $n3s_acc_token = $_SESSION['n3s_acc_token_upload'] = gen_acc_token();
    // template
    n3s_template_fw('upload-ok.html', [
        'image_id' => $im['image_id'],
        'title' => $im['title'],
        'image_url' => $image_url,
        'msg' => 'ファイルの情報',
        'user' => $user,
        'can_edit' => $can_edit,
        'acc_token' => $n3s_acc_token,
    ]);
}

function gen_acc_token() {
    $s = md5(uniqid(mt_rand(), true));
    return 'acc_token::'.$s;
}

function delete_image() {
    $dir_images = n3s_get_config('dir_images', '');
    if ($dir_images == '') {
        n3s_error('削除不可','システムでファイルフォルダが設定されていません。');
        return;
    }
    // check params
    $really = isset($_POST['really']) ? $_POST['really'] : '';
    if ($really != "delete") {
        n3s_error('削除できません', '「削除する」のチェックを入れてから試してください。');
        return;
    }
    // check acc_token
    $acc_token = isset($_POST['acc_token']) ? $_POST['acc_token'] : '';
    if (empty($_SESSION['n3s_acc_token_upload']) || $acc_token != $_SESSION['n3s_acc_token_upload']) {
        n3s_error('削除できません', 'トークンの有効期限が切れています。ページを戻ってやり直してください。');
        return;
    }
    $image_id = isset($_POST['image_id']) ? $_POST['image_id'] : '';
    $db = database_get();
    $im = db_get1('SELECT * FROM images WHERE image_id=? LIMIT 1', [$image_id]);
    if (!$im) {
        n3s_error('ファイルがありません', '指定のファイルはありません。');
        return;
    }
    // url
    $filename = $im['filename'];
    $user_id = $im['user_id'];
    $user = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id]);
    // can_edit
    $can_edit = FALSE;
    if (n3s_is_admin()) {
        $can_edit = TRUE;
    } else {
        $self_id = n3s_get_user_id();
        if ($self_id == $user_id) {
            $can_edit = TRUE;
        }
    }
    // delete from db
    db_exec('DELETE FROM images WHERE image_id=?', [$image_id]);
    // delete file
    $image_file = "{$dir_images}/{$filename}";
    unlink($image_file);
    //
    n3s_template_fw('basic.html',[
        'contents' => "ファイルを削除しました。",
    ]);
}

function list_image() {
    $PER_PAGE = 20;
    $max_id = isset($_GET['max_id']) ? intval($_GET['max_id']) : 65535;
    $images = db_get(
        'SELECT * FROM images WHERE image_id <= ? '.
        'ORDER BY image_id DESC LIMIT ?', [$max_id, $PER_PAGE]);
    foreach ($images as &$i) {
        $max_id = $i['image_id'] - 1;
        $fname = $i['filename'];
        $is_image = False;
        if (preg_match('/\.(jpg|jpeg|png|gif)$/', $fname)) {
            $is_image = True;
        }
        $i['is_image'] = $is_image;
        $i['image_url'] = n3s_get_config('url_images', 'images/')."/{$fname}";
        $i['info_url'] = n3s_getURL('', 'upload', ['image_id'=>$i['image_id'], 'mode'=>'show']);
    }
    $next_url = n3s_getURL('', 'upload', ['max_id' => $max_id, 'mode'=>'list']);
    n3s_template_fw('upload-list.html', [
        'images' => $images,
        'next_url' => $next_url,
    ]);
}