<?php
// tests/Feature/UploadAndImageTest.php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/upload.inc.php';

test('未ログイン状態でのアップロードアクセスはエラー画面を表示しログインページへ誘導する', function () {
    $_GET['mode'] = '';
    
    $out = n3s_test_capture(fn() => n3s_web_upload());
    
    expect($out)->toContain('アップロードできません')
        ->and($out)->toContain('先にログインしてください');
});

test('ログイン中であっても著作権に同意していない場合はアップロードが拒否される', function () {
    // 1. ユーザーログイン
    n3s_add_user('uploader@example.com', 'password', 'アップローダー');
    n3s_web_login_execute('uploader@example.com', 'password');
    expect(n3s_is_login())->toBeTrue();

    // 2. アップロードリクエスト (copyright 同意なし)
    $token = n3s_getEditToken();
    $_GET['mode'] = 'go';
    $_POST['copyright'] = ''; // 同意なし
    $_POST['copyright_type'] = 'CC0';
    $_POST['title'] = 'テスト画像';
    $_REQUEST['edit_token'] = $token;

    // ファイルアップロード先のフォルダ設定 (一時ディレクトリ内)
    global $n3s_config;
    $n3s_config['dir_images'] = N3S_TEST_TMP . '/test_images';
    if (!is_dir($n3s_config['dir_images'])) {
        mkdir($n3s_config['dir_images'], 0777, true);
    }

    $out = n3s_test_capture(fn() => n3s_web_upload());
    
    expect($out)->toContain('アップロードできません')
        ->and($out)->toContain('著作権に同意しないとアップロードできません');

    // DBにレコードが追加されていないことを検証
    $count = db_get1('SELECT count(*) FROM images', []);
    expect($count['count(*)'])->toEqual(0);
});

test('ファイル保存が失敗した場合にトランザクションがロールバックされてレコードが追加されないこと', function () {
    // 1. ユーザーログイン
    n3s_add_user('uploader@example.com', 'password', 'アップローダー');
    n3s_web_login_execute('uploader@example.com', 'password');

    // 2. アップロードリクエスト
    $token = n3s_getEditToken();
    $_GET['mode'] = 'go';
    $_POST['copyright'] = 'ok'; // 同意
    $_POST['copyright_type'] = 'CC0';
    $_POST['title'] = 'テスト画像';
    $_REQUEST['edit_token'] = $token;

    // 疑似的な $_FILES 設定
    $_FILES['userfile'] = [
        'name' => 'test.png',
        'type' => 'image/png',
        'tmp_name' => '/tmp/nonexistent_file_xyz', // 存在しない一時ファイル
        'error' => 0,
        'size' => 100
    ];

    global $n3s_config;
    $n3s_config['dir_images'] = N3S_TEST_TMP . '/test_images';
    if (!is_dir($n3s_config['dir_images'])) {
        mkdir($n3s_config['dir_images'], 0777, true);
    }

    // アップロードを実行 (move_uploaded_fileが失敗し、ロールバックが発生する)
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)->toContain('アップロード失敗')
        ->and($out)->toContain('サーバー側でファイルの保存に失敗しました');

    // トランザクションロールバックが成功し、DBに画像メタデータが残っていないことを検証
    $count = db_get1('SELECT count(*) FROM images', []);
    expect($count['count(*)'])->toEqual(0);
});

test('n3s_getImageFile がトークンの有無に応じて正しいファイルパスを生成すること', function () {
    global $n3s_config;
    $n3s_config['dir_images'] = '/tmp/images';
    
    // トークンなし
    $path = n3s_getImageFile(15, 'png', false, '');
    expect($path)->toBe('/tmp/images/000/15.png');

    // トークンあり (自分専用など)
    $path_token = n3s_getImageFile(15, 'png', false, 'mytoken123');
    expect($path_token)->toBe('/tmp/images/000/15-mytoken123.png');
});

test('プロフィール画像の32pxサムネイルと未指定時のURLを取得できること', function () {
    global $n3s_config;
    $n3s_config['dir_images'] = '/tmp/images';

    expect(n3s_getImageThumbnailFile(15, 32, false, 'mytoken123'))
        ->toBe('/tmp/images/000/15-mytoken123-32.jpg')
        ->and(n3s_get_user_image_url(['image_id' => 0, 'profile_url' => '']))
        ->toBe('https://n3s.nadesi.com/image.php?f=726.png');
});

test('プロフィール画像のURLはアプリのbaseurlから生成されること', function () {
    n3s_set_config('baseurl', 'http://example.test/nako3storage');
    $image_id = db_insert(
        'INSERT INTO images (title,user_id,copyright,app_id,image_name,token,filename,ctime,mtime) VALUES (?,?,?,?,?,?,?,?,?)',
        ['プロフィール画像', 1, 'SELF', 0, '', 'profiletoken', '99.jpg', time(), time()]
    );

    expect(n3s_get_user_image_url(['image_id' => $image_id]))
        ->toBe('http://example.test/nako3storage/image.php?f=99.jpg&s=32&t=profiletoken')
        ->and(n3s_get_user_image_url(['image_id' => $image_id], 0))
        ->toBe('http://example.test/nako3storage/image.php?f=99.jpg&t=profiletoken');
});
