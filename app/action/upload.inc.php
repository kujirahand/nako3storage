<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

include_once dirname(__FILE__) . '/save.inc.php';
include_once dirname(__FILE__) . '/show.inc.php';

// アップロード可能タイプ
global $supported_type;
$supported_type = 'jpg|jpeg|gif|png|svg|mml|mp3|ogg|oga|xml|txt|csv|tsv|json|mid';


function n3s_api_upload()
{
    n3s_api_output(false, []);
}

function n3s_web_upload()
{
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
    // ログインチェック #78
    if (!n3s_is_login()) {
        $loginurl = n3s_getURL('my', 'login');
        $backurl = n3s_getURL('my', 'upload');
        n3s_setBackURL($backurl);
        n3s_error('アップロードできません', "<a href='$loginurl'>先にログインしてください。</a>", true);
        return;
    }
    // アップロードフォームを表示
    n3s_template_fw('upload.html', [
        "edit_token" => n3s_getEditToken(),
    ]);
}

function go_upload()
{
    // システムをチェック
    $dir_images = n3s_get_config('dir_images', '');
    if (!$dir_images) {
        n3s_error('アップロードできません', 'システムで保存フォルダが設定されていません');
        return;
    }
    // edit_tokenのチェック
    if (!n3s_checkEditToken()) {
        n3s_error('アップロードできません', 'トークンが無効です。再度アップロードしてください。');
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
    if ($copyright != 'ok') {
        n3s_error('アップロードできません', '著作権に同意しないとアップロードできません。');
        return;
    }
    $copyright_type = isset($_POST['copyright_type']) ? $_POST['copyright_type'] : '';
    if ($copyright_type == 'SELF' || $copyright_type == 'CC0' || $copyright_type == 'MIT' || $copyright_type == 'CC-BY') {
        // ok
    } else {
        n3s_error('アップロードできません', '著作権が不明です。');
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
    global $supported_type;
    $re = "/\.({$supported_type})$/";
    if (preg_match($re, strtolower($fname), $m)) {
        $ext = strtolower($m[0]);
    } else {
        n3s_error('アップロード失敗', "画像や音声、テキスト形式のファイルのみ".
          "アップロードできます。 (".$supported_type.")");
        return;
    }
    $user_id = n3s_get_user_id();
    // todo: MINE check
    // $mime = @mime_content_type($path);
    $db = n3s_get_db('main');
    db_exec('begin');
    $image_id = db_insert(
        'INSERT INTO images (title,user_id,copyright,ctime,mtime)VALUES(?,?,?,?,?)',
        [$title, $user_id, $copyright_type, time(), time()]
    );
    // detect filename
    $filename = "{$image_id}{$ext}";
    $path = n3s_getImageFile($image_id, $ext, true);
    db_exec("UPDATE images SET filename=? WHERE image_id=?", [$filename, $image_id]);
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

function show_image()
{
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
    $user = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], "users");
    $image_url = n3s_get_config('baseurl', '.')."/image.php?f={$filename}";
    // can_edit
    $can_edit = false;
    if (n3s_is_admin()) {
        $can_edit = true;
    } else {
        $self_id = n3s_get_user_id();
        if ($self_id == $user_id) {
            $can_edit = true;
        }
    }
    // set token to session
    $n3s_acc_token = $_SESSION['n3s_acc_token_upload'] = n3s_getEditToken();
    // template
    n3s_template_fw('upload-ok.html', [
        'image_id' => $im['image_id'],
        'title' => $im['title'],
        'copyright' => getCopyrightName($im['copyright']),
        'image_url' => $image_url,
        'msg' => 'ファイルの情報',
        'user' => $user,
        'can_edit' => $can_edit,
        'acc_token' => $n3s_acc_token,
    ]);
}

function getCopyrightName($type)
{
    switch ($type) {
        case 'SELF': return '自分専用(他人の使用は認めません)';
        case 'CC0': return 'CC0(パブリックドメイン)';
        case 'CC-BY': return 'CC-BY(著作権表示すれば誰でも使えます)';
    }
    return '著作権表示不明:'.$type;
}
function getCopyrightName2($type)
{
    switch ($type) {
        case 'SELF': return '自分専用';
        case 'CC0': return 'CC0';
        case 'CC-BY': return 'CC-BY';
    }
    return '著作権表示不明:'.$type;
}

function delete_image()
{
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
    $image_id = intval(isset($_POST['image_id']) ? $_POST['image_id'] : '0');
    if ($image_id == 0) {
        n3s_error('削除できません', 'パラメータが不正です。');
        return;
    }
    $db = database_get();
    $im = db_get1('SELECT * FROM images WHERE image_id=? LIMIT 1', [$image_id]);
    if (!$im) {
        n3s_error('ファイルがありません', '指定のファイルはありません。');
        return;
    }
    // url
    $user_id = $im['user_id'];
    $user = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], "users");
    
    // can_edit
    $can_edit = false;
    if (n3s_is_admin()) {
        $can_edit = true;
    } else {
        $self_id = n3s_get_user_id();
        if ($self_id == $user_id) {
            $can_edit = true;
        }
    }
    if (!$can_edit) {
        n3s_error('削除権限がありません。', '他人のリソースは削除できません。');
        return;
    }
    $filename = $im['filename'];
    $dir = n3s_getImageDir($image_id);
    // delete from db
    db_exec('DELETE FROM images WHERE image_id=?', [$image_id]);
    // delete file
    $image_file = "{$dir}/{$filename}";
    if (file_exists($image_file)) {
        @unlink($image_file);
    }
    $back = n3s_getURL('my', 'mypage');
    n3s_info("ファイルを削除しました。", "ファイルを削除しました。<a href='$back'>戻る</a>", true);
}

function list_image()
{
    $PER_PAGE = 20;
    $max_id = isset($_GET['max_id']) ? intval($_GET['max_id']) : 65535;
    $images = db_get(
        'SELECT * FROM images WHERE image_id <= ? '.
        'ORDER BY image_id DESC LIMIT ?',
        [$max_id, $PER_PAGE]
    );
    foreach ($images as &$i) {
        $max_id = $i['image_id'] - 1;
        $fname = $i['filename'];
        $is_image = false;
        if (preg_match('/\.(jpg|jpeg|png|gif|svg)$/', $fname)) {
            $is_image = true;
        }
        $i['is_image'] = $is_image;
        $i['image_url'] = "image.php?f={$fname}";
        $i['info_url'] = n3s_getURL('', 'upload', ['image_id'=>$i['image_id'], 'mode'=>'show']);
        $i['copyright_name'] = getCopyrightName2($i['copyright']);
    }
    $next_url = n3s_getURL('', 'upload', ['max_id' => $max_id, 'mode'=>'list']);
    n3s_template_fw('upload-list.html', [
        'images' => $images,
        'next_url' => $next_url,
    ]);
}
