<?php
define("MAX_FILE_SIZE_DEFAULT", 1024 * 1024 * 7); // 実際は、configの`size_upload_max`を見る

// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

include_once dirname(__FILE__) . '/save.inc.php';
include_once dirname(__FILE__) . '/show.inc.php';

// アップロード可能タイプ
global $supported_type;
$supported_type = 'jpg|jpeg|gif|png|svg|mml|mp3|ogg|oga|xml|txt|csv|tsv|json|mid|xlsx|sf2|sf3|py|html|md|css|js|mjs|pdf|toml|ini|yaml';


function n3s_api_upload()
{
    n3s_api_output(false, []);
}

function n3s_web_upload()
{
    global $supported_type;
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
    if ($mode == 'update') {
        update_image_meta();
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
        "max_file_size" => n3s_get_config('size_upload_max', MAX_FILE_SIZE_DEFAULT),
        "max_file_size_mb" => ceil(n3s_get_config('size_upload_max', MAX_FILE_SIZE_DEFAULT) / (1024 * 1024)),
        "supported_type" => $supported_type,
        'link_mypage' => n3s_getURL('all', 'mypage'),
        'link_material' => n3s_getURL('all', 'mypage', ['mode' => 'material']),
        'link_all_fav' => n3s_getURL('all', 'mypage', ['fav' => 'all']),
        'link_userinfo' => n3s_getURL(n3s_get_user_id(), 'userinfo'),
    ]);
}

function go_upload()
{
    global $supported_type;
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
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
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
    $description = mb_substr($description, 0, 2000); // 説明が極端に長い場合はトリムする
    // ファイルのチェック
    $userfile = isset($_FILES['userfile']) ? $_FILES['userfile'] : [];
    if (!$userfile) {
        n3s_error('アップロード失敗', 'ファイルがありまぜん。');
        return;
    }
    $tmp_name = $userfile['tmp_name'];
    $fname = $userfile['name'];
    $size = $userfile['size'];
    $size_upload_max = n3s_get_config('size_upload_max', MAX_FILE_SIZE_DEFAULT); // 最大ファイルの指定
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
    $re = "/\.({$supported_type})$/";
    if (preg_match($re, strtolower($fname), $m)) {
        $ext = strtolower($m[0]);
    } else {
        n3s_error('アップロード失敗', "画像や音声、テキスト形式のファイルのみ" .
            "アップロードできます。 (" . $supported_type . ")");
        return;
    }
    // app_idとファイル名のチェック
    $app_id = empty($_POST['app_id']) ? 0 : intval($_POST['app_id']);
    $image_name = empty($_POST['image_name']) ? '' : $_POST['image_name'];
    if (strlen($image_name) > 128) {
        n3s_error('アップロード失敗', "ファイル名が長すぎます。128文字以内にしてください。");
        return;
    }
    // 予約語のチェック - nako_ から始まる名前は使えない
    if (preg_match('/^nako_/', $image_name)) {
        // 管理ユーザーは例外
        if (n3s_is_admin()) {
            // ok
        } else {
            n3s_error('アップロード失敗', "ファイル名は、nako_ から始まる名前は使えません。");
            return;
        }
    }
    // ファイル名は空文字でない時
    if ($image_name != '') {
        // 英数と _ - . のみであること
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $image_name)) {
            n3s_error('アップロード失敗', "ファイル名は、英数字と _ - . のみで指定してください。");
            return;
        }
        // 拡張子が空ならばエラーにする
        $ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
        if ($ext == '' || $ext == ".") {
            n3s_error('アップロード失敗', "ファイル名を指定するときは、ファイルの拡張子が必要です。");
            return;
        }
        // 既に、app_idとimage_nameの組み合わせがある場合はエラー
        if ($image_name != '') {
            $exists = db_get1('SELECT image_id FROM images WHERE app_id=? AND image_name=? LIMIT 1', [$app_id, $image_name]);
            if ($exists) {
                n3s_error('アップロード失敗', "そのアプリ内でのファイル名は既に使われています。別の名前を指定してください。");
                return;
            }
            // ファイル名の形式チェック
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $image_name)) {
                n3s_error('アップロード失敗', "ファイル名は、英数字と _ - . のみで指定してください。");
                return;
            }
        }
    }
    $user_id = n3s_get_user_id();
    // todo: MIME check
    // $mime = @mime_content_type($path);
    db_exec('begin');
    $token = ''; // 通常は空文字
    if ($copyright_type == 'SELF') {
        $token = bin2hex(random_bytes(8)); // 自分専用はよりトークンを生成
    }
    $image_id = db_insert(
        'INSERT INTO images (title,description,user_id,copyright,app_id,image_name,token,ctime,mtime)VALUES(?,?,?,?,?,?,?,?,?)',
        [$title, $description, $user_id, $copyright_type, $app_id, $image_name, $token, time(), time()]
    );
    // detect filename
    $filename = "{$image_id}{$ext}";
    $path = n3s_getImageFile($image_id, $ext, true, $token);
    db_exec("UPDATE images SET filename=? WHERE image_id=?", [$filename, $image_id]);
    if (!move_uploaded_file($tmp_name, $path)) {
        db_exec('rollback');
        $error = $_FILES['userfile']['error'];
        $msg = "";
        if ($error == UPLOAD_ERR_INI_SIZE || $error == UPLOAD_ERR_FORM_SIZE) {
            $mb = floor($size_upload_max / (1024 * 1024));
            $msg = "ファイルサイズが最大の{$mb}MBを超えています。";
        } elseif ($error == UPLOAD_ERR_PARTIAL) {
            $msg = "ファイルが一部しかアップロードされませんでした。";
        } elseif ($error == UPLOAD_ERR_NO_FILE) {
            $msg = "ファイルが選択されていません。";
        } elseif ($error == UPLOAD_ERR_NO_TMP_DIR) {
            $msg = "システムエラー:サーバー側の一時フォルダがありません。";
        } elseif ($error == UPLOAD_ERR_CANT_WRITE) {
            $msg = "システムエラー:サーバー側でファイルの書き込みに失敗しました。";
        } elseif ($error == UPLOAD_ERR_EXTENSION) {
            $msg = "拡張モジュールによってアップロードが中止されました。";
        } else {
            $msg = "不明なエラーコード($error)です。";
        }
        n3s_error('アップロード失敗', "サーバー側でファイルの保存に失敗しました。やり直してください。理由:($error) $msg");
        return;
    }
    db_exec('commit');
    $url = n3s_getURL('', 'upload', [
        'mode' => 'show',
        'image_id' => $image_id
    ]);
    header('location:' . $url);
}

function show_image()
{
    // check
    $image_id = isset($_GET['image_id']) ? $_GET['image_id'] : '';
    $im = db_get1('SELECT * FROM images WHERE image_id=? LIMIT 1', [$image_id]);
    if (!$im) {
        n3s_error('ファイルがありません', '指定のファイルはありません。');
        return;
    }
    if ($im['copyright'] == 'SELF') {
        // 自分専用ファイルの場合、ユーザーIDとトークンをチェック
        $user_id = n3s_get_user_id();
        if ($user_id != $im['user_id']) {
            n3s_error('ファイルがありません', '指定のファイルはありません。');
            return;
        }
    }
    // url
    $filename = $im['filename'];
    $user_id = $im['user_id'];
    $token = $im['token'];
    $user = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], "users");
    $image_url = n3s_get_config('baseurl', '.') . "/image.php?f={$filename}";
    if ($token != '') {
        // 自分専用ファイルの場合、トークンを付与
        $image_url = n3s_get_config('baseurl', '.') . "/image.php?t={$token}&f={$filename}";
    }
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
    $use_image_name_shortcut = n3s_getInfo('use_image_name_shortcut', TRUE);
    $app_id = intval($im['app_id']);
    $image_name = $im['image_name'];
    $image_name_url = '';
    if ($image_name !== '') {
        if ($use_image_name_shortcut) {
            // ショートカットURLを有効にする
            if ($app_id > 0) {
                // app_idが1以上の場合は、/images/xxx の形にする
                $image_name_url = n3s_get_config('baseurl', '.') . "/images/{$app_id}-{$image_name}";
            } else {
                // app_idが0の場合は、/image/xxx の形にする
                $image_name_url = n3s_get_config('baseurl', '.') . "/image/{$image_name}";
            }
        } else {
            $image_name_url = n3s_get_config('baseurl', '.') . "/image.php?app_id={$app_id}&image_name={$image_name}"; // 旧式のURL
        }
        if ($token != '') {
            $image_name_url .= "?t={$token}";
        }
    }
    // set token to session
    $n3s_acc_token = $_SESSION['n3s_acc_token_upload'] = n3s_getEditToken();
    // template
    n3s_template_fw('upload-ok.html', [
        'image_name_url' => $image_name_url,
        'im' => $image_name_url,
        'image_id' => $im['image_id'],
        'title' => $im['title'],
        'description' => isset($im['description']) ? $im['description'] : '',
        'image_name' => $im['image_name'],
        'app_id' => $im['app_id'],
        'copyright' => getCopyrightName($im['copyright']),
        'image_url' => $image_url,
        'view' => intval(isset($im['view']) ? $im['view'] : 0),
        'msg' => 'ファイルの情報',
        'user' => $user,
        'can_edit' => $can_edit,
        'acc_token' => $n3s_acc_token,
        'link_user' => n3s_getURL('', 'user', ['user_id' => $user_id]),
        'is_preview_image' => preg_match('/\.(jpe?g|png|gif|webp)$/i', $filename) === 1,
        'file_extension' => strtoupper(pathinfo($filename, PATHINFO_EXTENSION)),
        'link_mypage' => n3s_getURL('all', 'mypage'),
        'link_material' => n3s_getURL('all', 'mypage', ['mode' => 'material']),
        'link_all_fav' => n3s_getURL('all', 'mypage', ['fav' => 'all']),
        'link_userinfo' => n3s_getURL(n3s_get_user_id(), 'userinfo'),
    ]);
}

function getCopyrightName($type)
{
    switch ($type) {
        case 'SELF':
            return '自分専用(他人の使用は認めません)';
        case 'CC0':
            return 'CC0(パブリックドメイン)';
        case 'CC-BY':
            return 'CC-BY(著作権表示すれば誰でも使えます)';
    }
    return '著作権表示不明:' . $type;
}
function getCopyrightName2($type)
{
    switch ($type) {
        case 'SELF':
            return '自分専用';
        case 'CC0':
            return 'CC0';
        case 'CC-BY':
            return 'CC-BY';
    }
    return '著作権表示不明:' . $type;
}

function update_image_meta()
{
    // check acc_token (削除フォームと共通のCSRFトークン)
    $acc_token = isset($_POST['acc_token']) ? $_POST['acc_token'] : '';
    if (empty($_SESSION['n3s_acc_token_upload']) || $acc_token != $_SESSION['n3s_acc_token_upload']) {
        n3s_error('更新できません', 'トークンの有効期限が切れています。ページを戻ってやり直してください。');
        return;
    }
    $image_id = intval(isset($_POST['image_id']) ? $_POST['image_id'] : '0');
    if ($image_id == 0) {
        n3s_error('更新できません', 'パラメータが不正です。');
        return;
    }
    $im = db_get1('SELECT * FROM images WHERE image_id=? LIMIT 1', [$image_id]);
    if (!$im) {
        n3s_error('ファイルがありません', '指定のファイルはありません。');
        return;
    }
    // can_edit
    $can_edit = false;
    if (n3s_is_admin()) {
        $can_edit = true;
    } else {
        $self_id = n3s_get_user_id();
        if ($self_id == $im['user_id']) {
            $can_edit = true;
        }
    }
    if (!$can_edit) {
        n3s_error('更新権限がありません。', '他人のリソースは更新できません。');
        return;
    }
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $title = mb_substr($title, 0, 512);
    $description = mb_substr($description, 0, 2000);
    if ($title === '') {
        $title = $im['filename'];
    }
    db_exec('UPDATE images SET title=?, description=?, mtime=? WHERE image_id=?', [$title, $description, time(), $image_id]);
    $url = n3s_getURL('', 'upload', [
        'mode' => 'show',
        'image_id' => $image_id
    ]);
    header('location:' . $url);
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
    n3s_delete_image_record_and_file($image_id);
    $back = n3s_getURL('my', 'mypage');
    n3s_info("ファイルを削除しました。", "ファイルを削除しました。<a href='$back'>戻る</a>", true);
}

function list_image()
{
    $PER_PAGE = 20;
    
    // sort パラメータの判定
    $sort = 'ranking';
    if (isset($_GET['sort'])) {
        if ($_GET['sort'] === 'mtime') {
            $sort = 'mtime';
        } elseif ($_GET['sort'] === 'search') {
            $sort = 'search';
        }
    }

    // 検索語の取得
    $search_word = isset($_GET['search_word']) ? trim($_GET['search_word']) : '';
    $has_search = ($sort === 'search' && $search_word !== '');

    global $n3s_config;
    $n3s_config['search_word'] = $search_word;
    $n3s_config['has_search'] = $has_search;

    $params = [];
    if ($has_search) {
        // %, _, \ のエスケープ
        $escaped_word = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search_word);
        $search_like = "%{$escaped_word}%";
    }

    if ($sort === 'mtime') {
        $max_id = isset($_GET['max_id']) ? intval($_GET['max_id']) : 65535;
        $sql = 'SELECT * FROM images WHERE (copyright != \'SELF\') AND (image_id <= ?) ORDER BY image_id DESC LIMIT ?';
        $params[] = $max_id;
        $params[] = $PER_PAGE;

        $images = db_get($sql, $params);
        foreach ($images as $i) {
            $max_id = $i['image_id'] - 1;
        }

        $next_url = n3s_getURL('', 'upload', ['max_id' => $max_id, 'mode' => 'list', 'sort' => 'mtime']);
    } elseif ($sort === 'search') {
        $max_id = isset($_GET['max_id']) ? intval($_GET['max_id']) : 65535;
        $sql = 'SELECT * FROM images WHERE (copyright != \'SELF\') AND (image_id <= ?)';
        $params[] = $max_id;

        if ($has_search) {
            $sql .= ' AND (title LIKE ? ESCAPE \'\\\' OR description LIKE ? ESCAPE \'\\\')';
            $params[] = $search_like;
            $params[] = $search_like;
        }

        $sql .= ' ORDER BY image_id DESC LIMIT ?';
        $params[] = $PER_PAGE;

        $images = db_get($sql, $params);
        foreach ($images as $i) {
            $max_id = $i['image_id'] - 1;
        }

        $next_params = ['max_id' => $max_id, 'mode' => 'list', 'sort' => 'search'];
        if ($has_search) {
            $next_params['search_word'] = $search_word;
        }
        $next_url = n3s_getURL('', 'upload', $next_params);
    } else {
        // ランキング順は image_id と相関しないため、カーソル方式ではなく offset で送る
        $offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;
        $sql = 'SELECT * FROM images WHERE (copyright != \'SELF\') ORDER BY view DESC, image_id DESC LIMIT ? OFFSET ?';
        $params[] = $PER_PAGE;
        $params[] = $offset;

        $images = db_get($sql, $params);

        $next_url = n3s_getURL('', 'upload', ['offset' => $offset + $PER_PAGE, 'mode' => 'list', 'sort' => 'ranking']);
    }

    foreach ($images as &$i) {
        $fname = $i['filename'];
        $is_image = false;
        if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $fname)) {
            $is_image = true;
        }
        $i['is_image'] = $is_image;
        $i['image_url'] = $is_image ? n3s_cover_url_from_image_row($i) : '';
        $i['file_extension'] = strtoupper(pathinfo($fname, PATHINFO_EXTENSION));
        $i['info_url'] = n3s_getURL('', 'upload', ['image_id' => $i['image_id'], 'mode' => 'show']);
        $i['copyright_name'] = getCopyrightName2($i['copyright']);
    }

    $link_ranking_url = n3s_getURL('', 'upload', ['mode' => 'list', 'sort' => 'ranking']);
    $link_mtime_url = n3s_getURL('', 'upload', ['mode' => 'list', 'sort' => 'mtime']);
    $link_search_params = ['mode' => 'list', 'sort' => 'search'];
    if ($search_word !== '') {
        $link_search_params['search_word'] = $search_word;
    }
    $link_search_url = n3s_getURL('', 'upload', $link_search_params);
    $link_clear_search = n3s_getURL('', 'upload', ['mode' => 'list', 'sort' => 'search']);

    n3s_template_fw('upload-list.html', [
        'images' => $images,
        'next_url' => $next_url,
        'sort' => $sort,
        'search_word' => $search_word,
        'has_search' => $has_search,
        'link_clear_search' => $link_clear_search,
        'link_ranking_url' => $link_ranking_url,
        'link_mtime_url' => $link_mtime_url,
        'link_search_url' => $link_search_url,
        'link_mypage' => n3s_getURL('all', 'mypage'),
        'link_material' => n3s_getURL('all', 'mypage', ['mode' => 'material']),
        'link_all_fav' => n3s_getURL('all', 'mypage', ['fav' => 'all']),
        'link_userinfo' => n3s_getURL(n3s_get_user_id(), 'userinfo'),
    ]);
}
