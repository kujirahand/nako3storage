<?php
// tests/Feature/SecurityAudit284Test.php
// 定期セキュリティチェック (Issue #284) で見つかった問題の回帰テスト。
//
// 対象:
//  - custom_head の検証 (scriptタグ以外を保存できないこと)
//  - nadesiko3hub への限定公開(is_private=2)作品の非出力とメタ改行除去
//  - ログイン時のセッションID再生成
//  - fav/bad の状態変更に POST + edit_token が必要なこと (CSRF)
//  - ライブラリ一覧で show_list=0 の作品が除外されること
//  - アップロード時の image_name 拡張子が許可リストで制限されること
//  - 作品削除時の関連データ (コメント/ブックマーク/素材/astorage/hub) の削除
//  - CLIバッチの Web 実行ガード、show画面の editkey 出力エスケープ (ソース確認)
//  - Google トークン交換フック (_google_http_post) の GET 注入対策
//  - 素材削除時のサムネイル削除、GD の画素数上限
//  - アップロード時の app_id 所有者確認
//  - fav の公開範囲判定とマイページお気に入り一覧の絞り込み
//  - 共通セキュリティヘッダ (X-Frame-Options / nosniff)

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/save.inc.php';
require_once N3S_TEST_ROOT . '/app/action/fav.inc.php';
require_once N3S_TEST_ROOT . '/app/action/bad.inc.php';
require_once N3S_TEST_ROOT . '/app/action/upload.inc.php';
// ※ library.inc.php は list.inc.php と同じ n3s_api_list() を宣言するため、
//    list.inc.php を読み込む他のテストと同一プロセスでは読み込めない。
//    ライブラリ一覧の条件はソース確認テストで担保する。

// ------------------------------------------------
// custom_head の検証
// ------------------------------------------------

test('custom_head: script以外のHTMLは保存できない', function () {
    $a = ['custom_head' => '<div onclick="alert(1)">x</div>'];
    $err = '';
    expect(n3s_check_custom_head($a, $err))->toBeFalse();
});

test('custom_head: インラインスクリプトは保存できない', function () {
    $a = ['custom_head' => '<script>alert(1)</script>'];
    $err = '';
    expect(n3s_check_custom_head($a, $err))->toBeFalse();
});

test('custom_head: javascript: スキームの src は保存できない', function () {
    $a = ['custom_head' => '<script src="javascript:alert(1)"></script>'];
    $err = '';
    expect(n3s_check_custom_head($a, $err))->toBeFalse();
});

test('custom_head: 外部スクリプトは許可され、許可外の属性は除去される', function () {
    $a = ['custom_head' => '<script src="https://example.com/lib.js" onclick="alert(1)" defer></script>'];
    $err = '';
    expect(n3s_check_custom_head($a, $err))->toBeTrue()
        ->and($a['custom_head'])->toContain('src="https://example.com/lib.js"')
        ->and($a['custom_head'])->toContain('defer')
        ->and($a['custom_head'])->not->toContain('onclick');
});

test('custom_head: 空文字とコメントのみは許可される', function () {
    $a = ['custom_head' => ''];
    $err = '';
    expect(n3s_check_custom_head($a, $err))->toBeTrue();
    $a = ['custom_head' => '<!-- libs -->'];
    expect(n3s_check_custom_head($a, $err))->toBeTrue()
        ->and($a['custom_head'])->toContain('<!-- libs -->');
});

// ------------------------------------------------
// nadesiko3hub: 限定公開の非出力 / メタ改行 / 削除ヘルパー
// ------------------------------------------------

function _sec284_hub_dir(): string
{
    global $n3s_config;
    $dir = N3S_TEST_TMP . '/' . uniqid('hub284', true);
    mkdir($dir, 0777, true);
    $n3s_config['nadesiko3hub_enabled'] = true;
    $n3s_config['nadesiko3hub_dir'] = $dir;
    return $dir;
}

function _sec284_hub_data(array $overrides = []): array
{
    return array_merge([
        'title' => 'テスト作品', 'author' => '作者', 'user_id' => 1,
        'copyright' => 'MIT', 'memo' => '', 'version' => '3.6.0', 'url' => '',
        'nakotype' => 'wnako', 'tag' => '', 'is_private' => 0,
        'body' => '「テスト」と表示。', 'ctime' => time(), 'mtime' => time(),
    ], $overrides);
}

test('hub: 限定公開(is_private=2)の作品は出力されず、既存ファイルも削除される', function () {
    _sec284_hub_dir();
    $savefile = n3s_nadesiko3hub_get_savefile(28402);
    // 公開として一度出力する
    expect(n3s_nadesiko3hub_save(28402, _sec284_hub_data()))->toBeTrue();
    expect(file_exists($savefile))->toBeTrue();
    // 限定公開に変更して保存し直すと、ファイルが削除され再出力もされない
    expect(n3s_nadesiko3hub_save(28402, _sec284_hub_data(['is_private' => 2])))->toBeFalse();
    expect(file_exists($savefile))->toBeFalse();
});

test('hub: メタ情報のタイトル等の改行は除去され、行を偽装できない', function () {
    _sec284_hub_dir();
    $data = _sec284_hub_data(['title' => "本物\n### タイトル=偽物"]);
    expect(n3s_nadesiko3hub_save(28403, $data))->toBeTrue();
    $content = file_get_contents(n3s_nadesiko3hub_get_savefile(28403));
    expect($content)->not->toContain("\n### タイトル=偽物")
        ->and($content)->toContain('タイトル=本物### タイトル=偽物');
});

test('hub: n3s_nadesiko3hub_delete() は出力済みファイルを削除する', function () {
    _sec284_hub_dir();
    expect(n3s_nadesiko3hub_save(28404, _sec284_hub_data()))->toBeTrue();
    expect(n3s_nadesiko3hub_delete(28404))->toBeTrue();
    expect(file_exists(n3s_nadesiko3hub_get_savefile(28404)))->toBeFalse();
    expect(n3s_nadesiko3hub_delete(28404))->toBeFalse();
});

// ------------------------------------------------
// ログイン時のセッションID再生成
// ------------------------------------------------

test('ログインするとセッションIDが再生成される', function () {
    n3s_add_user('regen@example.com', 'password123', '再生成');
    $started_here = false;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
        $started_here = true;
    }
    try {
        $before = session_id();
        expect($before)->not->toBe('');
        expect(n3s_login('regen@example.com', 'password123'))->toBeTrue();
        expect(session_id())->not->toBe($before);
    } finally {
        if ($started_here) {
            session_destroy();
        }
    }
});

// ------------------------------------------------
// fav / bad: POST + edit_token 必須
// ------------------------------------------------

function _sec284_insert_app(int $user_id): int
{
    return (int) db_insert(
        'INSERT INTO apps (title, author, user_id, copyright, is_private, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['対象作品', '作者', $user_id, 'MIT', 0, time(), time()]
    );
}

test('fav: GETでの q=up はお気に入りを増やさない', function () {
    $owner = (int) n3s_add_user('fav-owner@example.com', 'password123', '作者');
    $liker = (int) n3s_add_user('fav-liker@example.com', 'password123', 'ファン');
    $app_id = _sec284_insert_app($owner);
    n3s_web_login_execute('fav-liker@example.com', 'password123');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['page' => (string) $app_id, 'q' => 'up'];
    $_REQUEST = $_GET;
    n3s_test_capture(fn () => echo_fav());
    $r = db_get1('SELECT fav FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['fav']))->toBe(0);

    // POSTでもトークンがなければ増えない
    $_SERVER['REQUEST_METHOD'] = 'POST';
    n3s_test_capture(fn () => echo_fav());
    $r = db_get1('SELECT fav FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['fav']))->toBe(0);

    // POST + 正しいトークンで増える
    $_REQUEST = ['page' => (string) $app_id, 'q' => 'up', 'edit_token' => n3s_getEditToken()];
    $_GET = $_REQUEST;
    n3s_test_capture(fn () => echo_fav());
    $r = db_get1('SELECT fav FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['fav']))->toBe(1);
});

test('bad: GETでの q=up は通報数を増やさない', function () {
    $owner = (int) n3s_add_user('bad-owner@example.com', 'password123', '作者');
    n3s_add_user('bad-reporter@example.com', 'password123', '通報者');
    $app_id = _sec284_insert_app($owner);
    n3s_web_login_execute('bad-reporter@example.com', 'password123');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['page' => (string) $app_id, 'q' => 'up'];
    $_REQUEST = $_GET;
    n3s_test_capture(fn () => echo_bad());
    $r = db_get1('SELECT bad FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['bad']))->toBe(0);

    // POST + 正しいトークンで増える
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_REQUEST = ['page' => (string) $app_id, 'q' => 'up', 'edit_token' => n3s_getEditToken()];
    $_GET = $_REQUEST;
    n3s_test_capture(fn () => echo_bad());
    $r = db_get1('SELECT bad FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['bad']))->toBe(1);
});

// ------------------------------------------------
// ライブラリ一覧の show_list
// ------------------------------------------------

test('ライブラリ一覧の取得条件に show_list=1 が含まれる (ソース確認)', function () {
    // library.inc.php は list.inc.php と関数名が衝突するため同一プロセスで
    // 読み込めず、クエリ文字列そのものを回帰ガードとする。
    // (show_list の実際の絞り込み動作は ShowListTest が list 側で検証している)
    $src = file_get_contents(N3S_TEST_ROOT . '/app/action/library.inc.php');
    expect($src)->toContain('(is_private == 0) AND (show_list = 1)');
});

// ------------------------------------------------
// アップロード: image_name の拡張子制限
// ------------------------------------------------

test('アップロード: image_name に許可外の拡張子(.php)を指定できない', function () {
    global $n3s_config;
    n3s_add_user('ext@example.com', 'password123', '拡張子確認');
    n3s_web_login_execute('ext@example.com', 'password123');
    $n3s_config['dir_images'] = N3S_TEST_TMP . '/test_images_284';
    if (!is_dir($n3s_config['dir_images'])) {
        mkdir($n3s_config['dir_images'], 0777, true);
    }
    $_GET['mode'] = 'go';
    $_POST = [
        'copyright' => 'ok', 'copyright_type' => 'CC0', 'title' => '悪意ある名前',
        'image_name' => 'evil.php', 'app_id' => 0,
    ];
    $_REQUEST = ['edit_token' => n3s_getEditToken()];
    $_FILES['userfile'] = [
        'name' => 'test.png', 'type' => 'image/png',
        'tmp_name' => '/tmp/nonexistent_file_284', 'error' => 0, 'size' => 100,
    ];
    $out = n3s_test_capture(fn () => n3s_web_upload());
    expect($out)->toContain('その拡張子は使えません');
    $count = db_get1('SELECT count(*) AS c FROM images', []);
    expect(intval($count['c']))->toBe(0);
});

// ------------------------------------------------
// 作品削除時の関連データ削除
// ------------------------------------------------

test('作品を削除するとコメント・ブックマーク・素材・astorage・hubファイルも削除される', function () {
    global $n3s_config;
    $owner = (int) n3s_add_user('cascade@example.com', 'password123', '削除者');
    n3s_web_login_execute('cascade@example.com', 'password123');
    $app_id = _sec284_insert_app($owner);

    // 本文
    $dbname = n3s_getMaterialDB($app_id);
    db_exec('INSERT INTO materials (material_id, body, app_id) VALUES (?,?,?)', [$app_id, '本文', $app_id], $dbname);
    // コメント + いいね
    $comment_id = (int) db_insert(
        'INSERT INTO comments (user_id, app_id, parent_id, name, body, status, ctime, mtime) VALUES (?,?,?,?,?,?,?,?)',
        [$owner, $app_id, 0, '削除者', 'コメント', 'approved', time(), time()]
    );
    db_exec('INSERT INTO comment_likes (user_id, comment_id, ctime) VALUES (?,?,?)', [$owner, $comment_id, time()]);
    // ブックマーク
    db_exec('INSERT INTO bookmarks (user_id, app_id, ctime) VALUES (?,?,?)', [$owner, $app_id, time()]);
    // 素材 (レコード + 実ファイル)
    $n3s_config['dir_images'] = N3S_TEST_TMP . '/cascade_images';
    $image_id = (int) db_insert(
        'INSERT INTO images (title, user_id, app_id, image_name, filename, copyright, ctime, mtime) VALUES (?,?,?,?,?,?,?,?)',
        ['素材', $owner, $app_id, 'mat.png', 'x.png', 'CC0', time(), time()]
    );
    $image_path = n3s_getImageFile($image_id, 'png', true);
    file_put_contents($image_path, 'PNG');
    // astorage (app領域) のDBファイル
    $astorage_dir = N3S_TEST_TMP . '/cascade_astorage';
    mkdir($astorage_dir . '/apps', 0777, true);
    $n3s_config['dir_astorage'] = $astorage_dir;
    $astorage_file = $astorage_dir . '/apps/app' . sprintf('%06d', $app_id) . '.sqlite3';
    file_put_contents($astorage_file, 'DB');
    // hub 出力ファイル
    _sec284_hub_dir();
    $hub_file = n3s_nadesiko3hub_get_savefile($app_id);
    if (!is_dir(dirname($hub_file))) {
        mkdir(dirname($hub_file), 0777, true);
    }
    file_put_contents($hub_file, 'hub');

    // 削除を実行する
    $_GET = ['page' => (string) $app_id];
    $_POST = ['yesno' => 'yes'];
    $_REQUEST = ['edit_token' => n3s_getEditToken()];
    n3s_test_capture(fn () => n3s_action_save_delete([]));

    expect(db_get1('SELECT * FROM apps WHERE app_id=?', [$app_id]))->toBeFalsy()
        ->and(db_get1('SELECT * FROM comments WHERE app_id=?', [$app_id]))->toBeFalsy()
        ->and(db_get1('SELECT * FROM comment_likes WHERE comment_id=?', [$comment_id]))->toBeFalsy()
        ->and(db_get1('SELECT * FROM bookmarks WHERE app_id=?', [$app_id]))->toBeFalsy()
        ->and(db_get1('SELECT * FROM images WHERE app_id=?', [$app_id]))->toBeFalsy()
        ->and(file_exists($image_path))->toBeFalse()
        ->and(file_exists($astorage_file))->toBeFalse()
        ->and(file_exists($hub_file))->toBeFalse();
});

// ------------------------------------------------
// ソース確認: CLIガード / editkey エスケープ / JS の POST 化
// ------------------------------------------------

test('CLIバッチと nadesiko3hub_update.php には Web 実行ガードがある', function () {
    $files = [
        'nadesiko3hub_update.php',
        'scripts/app_count.php',
        'scripts/cdn_download_count.php',
        'scripts/comment_audit.php',
        'scripts/image_count.php',
        'scripts/nadesiko3hub_sync.php',
    ];
    foreach ($files as $f) {
        $src = file_get_contents(N3S_TEST_ROOT . '/' . $f);
        expect($src)->toContain("PHP_SAPI !== 'cli'");
    }
});

test('show画面の editkey 出力はエスケープされている', function () {
    $src = file_get_contents(N3S_TEST_ROOT . '/app/template/show.html');
    expect($src)->not->toContain("window.n3s_editkey = \"{{e: echo empty(\$_GET['editkey'])")
        ->and($src)->toContain('n3s_editkey = {{e: echo json_encode(')
        ->and($src)->toContain("htmlspecialchars(empty(\$_GET['editkey'])");
    $js = file_get_contents(N3S_TEST_ROOT . '/app/resource/nako3storage_comment.js');
    expect($js)->toContain('value="${escapeHTML(editKey)}"');
});

test('fav/bad のJSは edit_token 付きで POST する', function () {
    $js = file_get_contents(N3S_TEST_ROOT . '/app/resource/nako3storage_edit.js');
    expect($js)->toContain("ajaxPost('api.php?page=' + app_id + '&action=fav&q=up'")
        ->and($js)->toContain("ajaxPost('api.php?page=' + app_id + '&action=bad&q=up'")
        ->and($js)->toContain('edit_token=');
});

test('images/.htaccess がリポジトリに含まれ、実ファイルを保護する', function () {
    $path = N3S_TEST_ROOT . '/images/.htaccess';
    expect(file_exists($path))->toBeTrue();
    $src = file_get_contents($path);
    expect($src)->toContain('image.php')
        ->and($src)->toContain('[F,L]');
});

// ------------------------------------------------
// Google トークン交換フックの GET 注入対策
// ------------------------------------------------

test('Google: _google_http_post は既定設定に定義され、GETで差し替えできない', function () {
    global $n3s_config;
    // 未定義キーだと n3s_config_merge_get() が GET を取り込んでしまうため、
    // 既定設定 (n3s_config.def.php) にキー自体が定義されていることが対策になる
    expect($n3s_config)->toHaveKey('_google_http_post')
        ->and($n3s_config['_google_http_post'])->toBeNull();
    $merged = n3s_config_merge_get($n3s_config, ['_google_http_post' => 'phpinfo']);
    expect($merged['_google_http_post'])->toBeNull();
});

test('Google: 文字列callableでは http_post フックが実行されない', function () {
    // 万一キーが差し替えられても、GET 由来の文字列 callable は実行されない
    // (フックとして有効なのは Closure のみ)。接続拒否になるURLで、
    // フックが無視されて通常経路 (失敗して false) へ進むことも確認する。
    n3s_set_config('_google_http_post', 'phpinfo');
    $ret = null;
    $out = n3s_test_capture(function () use (&$ret) {
        $ret = n3s_google_http_post('https://127.0.0.1:1/token', []);
    });
    expect($ret)->toBeFalse()
        ->and($out)->not->toContain('PHP Version');
});

// ------------------------------------------------
// 素材削除時のサムネイル削除
// ------------------------------------------------

test('素材を削除すると32pxサムネイルも削除される', function () {
    global $n3s_config;
    $owner = (int) n3s_add_user('thumb@example.com', 'password123', 'サムネイル');
    $n3s_config['dir_images'] = N3S_TEST_TMP . '/thumb_images';
    $token = 'tokentest1234567';
    $image_id = (int) db_insert(
        'INSERT INTO images (title, user_id, app_id, image_name, filename, token, copyright, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?)',
        ['プロフィール画像', $owner, 0, '', '', $token, 'SELF', time(), time()]
    );
    db_exec('UPDATE images SET filename=? WHERE image_id=?', ["{$image_id}.jpg", $image_id]);
    $main = n3s_getImageFile($image_id, '.jpg', true, $token);
    file_put_contents($main, 'JPG');
    $thumb = n3s_getImageThumbnailFile($image_id, 32, true, $token);
    file_put_contents($thumb, 'THUMB');
    expect(file_exists($main))->toBeTrue()
        ->and(file_exists($thumb))->toBeTrue();

    n3s_delete_image_record_and_file($image_id);

    expect(db_get1('SELECT * FROM images WHERE image_id=?', [$image_id]))->toBeFalsy()
        ->and(file_exists($main))->toBeFalse()
        ->and(file_exists($thumb))->toBeFalse();
});

// ------------------------------------------------
// GD の画素数上限
// ------------------------------------------------

test('GD: 画素数が上限を超える画像は展開前に拒否される', function () {
    // 小さいPNGの IHDR だけを書き換え、巨大な寸法を宣言した画像を作る
    $src = N3S_TEST_TMP . '/huge284.png';
    $img = imagecreatetruecolor(16, 16);
    imagepng($img, $src);
    imagedestroy($img);
    $bin = file_get_contents($src);
    // PNG の IHDR では幅がオフセット16、高さが20 (ビッグエンディアン)
    $bin = substr_replace($bin, pack('N', 20000), 16, 4);
    $bin = substr_replace($bin, pack('N', 20000), 20, 4);
    file_put_contents($src, $bin);
    $info = getimagesize($src);
    expect($info[0])->toBe(20000)->and($info[1])->toBe(20000);

    $msg = '';
    try {
        n3s_gd_resize_center_crop($src, N3S_TEST_TMP . '/huge284_out.jpg', 32, 32, 'テスト画像');
    } catch (Exception $e) {
        $msg = $e->getMessage();
    }
    expect($msg)->toContain('画素数が大きすぎます');
});

test('GD: 通常サイズの画像はリサイズできる', function () {
    $src = N3S_TEST_TMP . '/normal284.png';
    $img = imagecreatetruecolor(120, 80);
    imagepng($img, $src);
    imagedestroy($img);
    $dest = N3S_TEST_TMP . '/normal284_out.jpg';
    n3s_gd_resize_center_crop($src, $dest, 32, 32, 'テスト画像');
    expect(file_exists($dest))->toBeTrue();
});

// ------------------------------------------------
// アップロード: app_id の所有者確認
// ------------------------------------------------

function _sec284_upload_post(int $app_id): void
{
    $_GET['mode'] = 'go';
    $_POST = [
        'copyright' => 'ok', 'copyright_type' => 'CC0', 'title' => '素材',
        'image_name' => 'mat.png', 'app_id' => $app_id,
    ];
    $_REQUEST = ['edit_token' => n3s_getEditToken()];
    $_FILES['userfile'] = [
        'name' => 'mat.png', 'type' => 'image/png',
        'tmp_name' => '/tmp/nonexistent_file_284', 'error' => 0, 'size' => 100,
    ];
}

test('アップロード: 他人のアプリには素材を紐付けできない', function () {
    global $n3s_config;
    $owner = (int) n3s_add_user('up-owner@example.com', 'password123', '作者');
    n3s_add_user('up-other@example.com', 'password123', '他人');
    $app_id = _sec284_insert_app($owner);
    n3s_web_login_execute('up-other@example.com', 'password123');
    $n3s_config['dir_images'] = N3S_TEST_TMP . '/test_images_284b';
    if (!is_dir($n3s_config['dir_images'])) {
        mkdir($n3s_config['dir_images'], 0777, true);
    }
    _sec284_upload_post($app_id);
    $out = n3s_test_capture(fn () => n3s_web_upload());
    expect($out)->toContain('他のユーザーのアプリには');
    $count = db_get1('SELECT count(*) AS c FROM images', []);
    expect(intval($count['c']))->toBe(0);
});

test('アップロード: 存在しないアプリには素材を紐付けできない', function () {
    global $n3s_config;
    n3s_add_user('up-ghost@example.com', 'password123', '投稿者');
    n3s_web_login_execute('up-ghost@example.com', 'password123');
    $n3s_config['dir_images'] = N3S_TEST_TMP . '/test_images_284b';
    if (!is_dir($n3s_config['dir_images'])) {
        mkdir($n3s_config['dir_images'], 0777, true);
    }
    _sec284_upload_post(999999);
    $out = n3s_test_capture(fn () => n3s_web_upload());
    expect($out)->toContain('指定されたアプリが見つかりません');
    $count = db_get1('SELECT count(*) AS c FROM images', []);
    expect(intval($count['c']))->toBe(0);
});

test('アップロード: 自分のアプリであれば所有者確認を通過する', function () {
    global $n3s_config;
    $owner = (int) n3s_add_user('up-self@example.com', 'password123', '作者');
    $app_id = _sec284_insert_app($owner);
    n3s_web_login_execute('up-self@example.com', 'password123');
    $n3s_config['dir_images'] = N3S_TEST_TMP . '/test_images_284b';
    if (!is_dir($n3s_config['dir_images'])) {
        mkdir($n3s_config['dir_images'], 0777, true);
    }
    // 実ファイルを用意しておく (move_uploaded_file 自体はテスト環境では
    // 失敗するが、所有者確認を通過して保存段階まで進むことを確認する)
    $tmp = N3S_TEST_TMP . '/up_self_src.png';
    file_put_contents($tmp, 'PNG');
    _sec284_upload_post($app_id);
    $_FILES['userfile']['tmp_name'] = $tmp;
    $out = n3s_test_capture(fn () => n3s_web_upload());
    expect($out)->not->toContain('他のユーザーのアプリには')
        ->and($out)->toContain('サーバー側でファイルの保存に失敗しました');
});

// ------------------------------------------------
// fav の公開範囲判定
// ------------------------------------------------

test('fav: 非公開作品は fav 数が表示されず、登録もできない', function () {
    $owner = (int) n3s_add_user('favp-owner@example.com', 'password123', '作者');
    n3s_add_user('favp-liker@example.com', 'password123', 'ファン');
    $app_id = (int) db_insert(
        'INSERT INTO apps (title, author, user_id, copyright, is_private, fav, ctime, mtime) VALUES (?,?,?,?,?,?,?,?)',
        ['非公開作品', '作者', $owner, 'MIT', 1, 5, time(), time()]
    );
    n3s_web_login_execute('favp-liker@example.com', 'password123');

    // view: DB上は fav=5 でも、閲覧権限がなければ 0 と表示される
    $_GET = ['page' => (string) $app_id, 'q' => 'view'];
    $_REQUEST = $_GET;
    $out = n3s_test_capture(fn () => echo_fav());
    expect($out)->toBe('0');

    // up: POST + 正しいトークンでも登録できない
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_REQUEST = ['page' => (string) $app_id, 'q' => 'up', 'edit_token' => n3s_getEditToken()];
    $_GET = $_REQUEST;
    n3s_test_capture(fn () => echo_fav());
    $r = db_get1('SELECT fav FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['fav']))->toBe(5);
    expect(db_get1('SELECT * FROM bookmarks WHERE app_id=?', [$app_id]))->toBeFalsy();
});

test('fav: 限定公開作品は editkey が一致すれば登録できる', function () {
    $owner = (int) n3s_add_user('favl-owner@example.com', 'password123', '作者');
    n3s_add_user('favl-liker@example.com', 'password123', 'ファン');
    $app_id = (int) db_insert(
        'INSERT INTO apps (title, author, user_id, copyright, is_private, editkey, fav, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?)',
        ['限定公開作品', '作者', $owner, 'MIT', 2, 'secretkey', 0, time(), time()]
    );
    n3s_web_login_execute('favl-liker@example.com', 'password123');

    // キーなしでは登録できない
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_REQUEST = ['page' => (string) $app_id, 'q' => 'up', 'edit_token' => n3s_getEditToken()];
    $_GET = $_REQUEST;
    n3s_test_capture(fn () => echo_fav());
    $r = db_get1('SELECT fav FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['fav']))->toBe(0);

    // キーが一致すれば登録できる
    $_REQUEST = ['page' => (string) $app_id, 'q' => 'up', 'edit_token' => n3s_getEditToken(), 'editkey' => 'secretkey'];
    $_GET = $_REQUEST;
    n3s_test_capture(fn () => echo_fav());
    $r = db_get1('SELECT fav FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['fav']))->toBe(1);
});

test('マイページのお気に入り一覧は閲覧できない作品を除外する (ソース確認)', function () {
    $src = file_get_contents(N3S_TEST_ROOT . '/app/action/mypage.inc.php');
    expect($src)->toContain("n3s_private_access_allowed(\$a, '')");
});

// ------------------------------------------------
// 共通セキュリティヘッダ
// ------------------------------------------------

test('セキュリティヘッダ: 通常画面には X-Frame-Options が付き、widget系には付かない', function () {
    $show = n3s_security_headers('show');
    expect($show)->toContain('X-Frame-Options: SAMEORIGIN')
        ->and($show)->toContain('X-Content-Type-Options: nosniff');
    foreach (['widget', 'widget_frame'] as $a) {
        $h = n3s_security_headers($a);
        expect($h)->toContain('X-Content-Type-Options: nosniff');
        foreach ($h as $line) {
            expect($line)->not->toStartWith('X-Frame-Options');
        }
    }
});

test('セキュリティヘッダ: ディスパッチとJSON出力に組み込まれている (ソース確認)', function () {
    $idx = file_get_contents(N3S_TEST_ROOT . '/app/index.inc.php');
    expect($idx)->toContain('n3s_send_security_headers($action)');
    $lib = file_get_contents(N3S_TEST_ROOT . '/app/n3s_lib.inc.php');
    expect($lib)->toContain("header('X-Content-Type-Options: nosniff');");
});

// ------------------------------------------------
// agyレビューでの追加指摘 (PR #285 レビュー)
// ------------------------------------------------

test('custom_head: コメントの早期終了(--!>)でスクリプトを混入できない', function () {
    // ブラウザは --!> をコメント終了として解釈するため、コメントとして
    // そのまま保存すると後続のスクリプトが本体オリジンで実行されてしまう
    $a = ['custom_head' => '<!-- --!><script>alert(origin)</script> -->'];
    $err = '';
    expect(n3s_check_custom_head($a, $err))->toBeFalse();
});

test('custom_head: コメント内にタグを含むものは保存できない', function () {
    $a = ['custom_head' => '<!-- <script src="https://example.com/x.js"></script> -->'];
    $err = '';
    expect(n3s_check_custom_head($a, $err))->toBeFalse();
});

test('custom_head: コメント内に -- を含むものは保存できない', function () {
    $a = ['custom_head' => '<!-- a -- b -->'];
    $err = '';
    expect(n3s_check_custom_head($a, $err))->toBeFalse();
});

test('custom_head: 通常のコメントと外部スクリプトの組み合わせは許可される', function () {
    $a = ['custom_head' => '<!-- libs --><script src="https://example.com/lib.js"></script>'];
    $err = '';
    expect(n3s_check_custom_head($a, $err))->toBeTrue()
        ->and($a['custom_head'])->toContain('<!-- libs -->')
        ->and($a['custom_head'])->toContain('src="https://example.com/lib.js"');
});

test('fav: 限定公開の閲覧キーがJSのリクエストとwhoリンクで引き継がれる (ソース確認)', function () {
    $js = file_get_contents(N3S_TEST_ROOT . '/app/resource/nako3storage_edit.js');
    expect($js)->toContain('window.n3s_editkey')
        ->and($js)->toContain("'&action=fav&q=up' + fav_editkey")
        ->and($js)->toContain("'&action=fav' + fav_editkey");
    $html = file_get_contents(N3S_TEST_ROOT . '/app/template/show.html');
    expect($html)->toContain("m=who{{e: echo empty(\$_GET['editkey'])");
});

test('アップロード: image_name の拡張子はドット付きに統一される (ソース確認)', function () {
    // ドットがないと DB の filename が "<image_id>png" になってしまう。
    // go_upload は move_uploaded_file がテスト環境で必ず失敗しロールバック
    // されるため、filename の値はソース確認で担保する。
    $src = file_get_contents(N3S_TEST_ROOT . '/app/action/upload.inc.php');
    expect($src)->toContain("\$ext = '.' . \$ext;");
});
