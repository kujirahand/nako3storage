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
