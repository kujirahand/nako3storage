<?php
// Issue #286 フォローアップの回帰テスト。
// 対象: セッション失効 (login_token)、astorageトークンの失効連動、ログアウトの
// POST化とセッション破棄、退会の完全化、ログイン/再発行のアカウント単位制限、
// is_private の値域検証、LIKEエスケープ、bad のユーザー単位一意化、
// reset 系の POST 必須化、widget_frame のトークン照合、コメントのレート制限、
// プロフィール/扉絵の実体削除、バッチの多重起動防止と監査ログ。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/login.inc.php';
require_once N3S_TEST_ROOT . '/app/action/logout.inc.php';
require_once N3S_TEST_ROOT . '/app/action/save.inc.php';
require_once N3S_TEST_ROOT . '/app/action/bad.inc.php';
require_once N3S_TEST_ROOT . '/app/action/comment.inc.php';
require_once N3S_TEST_ROOT . '/app/action/admin.inc.php';
require_once N3S_TEST_ROOT . '/app/action/widget_frame.inc.php';
require_once N3S_TEST_ROOT . '/app/action/search.inc.php';

// ------------------------------------------------
// セッション失効 (login_token)
// ------------------------------------------------

test('セッション: login_token がDBと一致すればログイン扱い、ローテーションで失効する', function () {
    global $n3s_config;
    $user_id = (int) n3s_add_user('tok@example.com', 'password123', 'トークン');
    db_exec('UPDATE users SET login_token=? WHERE user_id=?', ['token-A', $user_id], 'users');

    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user_id;
    $_SESSION['login_token'] = 'token-A';
    unset($n3s_config['_login_token_cache']);
    expect(n3s_is_login())->toBeTrue();

    // DB側をローテーションすると、このセッションは失効する
    n3s_rotate_login_token($user_id);
    unset($n3s_config['_login_token_cache']);
    expect(n3s_is_login())->toBeFalse();

    // トークンを持たないセッションは従来通り (互換)
    unset($_SESSION['login_token']);
    expect(n3s_is_login())->toBeTrue();
});

test('セッション: ログイン時に login_token が未発行なら発行されてセッションに保存される', function () {
    $user_id = (int) n3s_add_user('tok2@example.com', 'password123', 'トークン2');
    $user = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], 'users');
    expect($user['login_token'] ?? null)->toBeEmpty();
    n3s_login_session_start($user);
    expect($_SESSION['login_token'])->not->toBeEmpty();
    $after = db_get1('SELECT login_token FROM users WHERE user_id=?', [$user_id], 'users');
    expect($after['login_token'])->toBe($_SESSION['login_token']);
});

test('セッション: トークンのないセッションはDBのトークンを取り込み、退会済みは失効する', function () {
    global $n3s_config;
    $user_id = (int) n3s_add_user('promo@example.com', 'password123', '昇格');
    db_exec('UPDATE users SET login_token=? WHERE user_id=?', ['token-P', $user_id], 'users');
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user_id;
    unset($n3s_config['_login_token_cache']);
    expect(n3s_is_login())->toBeTrue();
    // DB側のトークンがセッションへ取り込まれる (遅延バインド)
    expect($_SESSION['login_token'])->toBe('token-P');
    // 取り込み後のローテーションでは失効する
    n3s_rotate_login_token($user_id);
    expect(n3s_is_login())->toBeFalse();

    // 退会済みユーザーのトークンなしセッションは失効する
    $user2 = (int) n3s_add_user('promo2@example.com', 'password123', '昇格2');
    db_exec('UPDATE users SET name="(退会ユーザー)" WHERE user_id=?', [$user2], 'users');
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user2;
    unset($_SESSION['login_token']);
    unset($n3s_config['_login_token_cache']);
    expect(n3s_is_login())->toBeFalse();
});

// ------------------------------------------------
// astorage トークンの失効連動
// ------------------------------------------------

test('astorageトークン: login_token のローテーションで失効し、旧形式とゲストは影響を受けない', function () {
    $user_id = (int) n3s_add_user('as@example.com', 'password123', 'ストレージ');
    db_exec('UPDATE users SET login_token=? WHERE user_id=?', ['token-B', $user_id], 'users');

    $token = n3s_astorage_token_create(5, $user_id);
    expect($token)->not->toBe('');
    $v = n3s_astorage_token_verify($token);
    expect($v)->not->toBeNull()
        ->and($v['user_id'])->toBe($user_id);

    // ローテーション後は失効する
    n3s_rotate_login_token($user_id);
    expect(n3s_astorage_token_verify($token))->toBeNull();

    // 指紋のない旧形式トークンは有効期限まで有効
    $payload = n3s_base64url_encode(json_encode(['a' => 5, 'u' => $user_id, 'e' => time() + 600]));
    $legacy = $payload . '.' . n3s_astorage_token_sign($payload);
    expect(n3s_astorage_token_verify($legacy))->not->toBeNull();

    // ゲスト (user_id=0) のトークンは login_token と無関係
    $guest = n3s_astorage_token_create(5, 0);
    expect(n3s_astorage_token_verify($guest))->not->toBeNull();
});

test('astorageトークン: login_token 未発行のユーザーでも指紋付きで発行され失効連動する', function () {
    $user_id = (int) n3s_add_user('as2@example.com', 'password123', 'ストレージ2');
    $before = db_get1('SELECT login_token FROM users WHERE user_id=?', [$user_id], 'users');
    expect($before['login_token'] ?? null)->toBeEmpty();
    $token = n3s_astorage_token_create(7, $user_id);
    expect($token)->not->toBe('');
    expect(n3s_astorage_token_verify($token))->not->toBeNull();
    // 発行時に login_token が生成されている
    $after = db_get1('SELECT login_token FROM users WHERE user_id=?', [$user_id], 'users');
    expect($after['login_token'])->not->toBeEmpty();
    // ローテーションで失効する
    n3s_rotate_login_token($user_id);
    expect(n3s_astorage_token_verify($token))->toBeNull();
});

// ------------------------------------------------
// ログアウト
// ------------------------------------------------

test('ログアウト: GETでは確認画面になり、POST+トークンでログアウトする', function () {
    $user_id = (int) n3s_add_user('lo@example.com', 'password123', 'ログアウト');
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = 'ログアウト';

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_REQUEST = [];
    $out = n3s_test_capture(fn () => n3s_web_logout());
    expect($out)->toContain('ログアウトしますか')
        ->and($_SESSION['n3s_login'] ?? null)->toBeTrue();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_REQUEST = ['edit_token' => n3s_getEditToken()];
    $out = n3s_test_capture(fn () => n3s_web_logout());
    expect($out)->toContain('ログアウトしました')
        ->and($_SESSION['n3s_login'] ?? null)->toBeNull()
        ->and($_SESSION['user_id'] ?? null)->toBeNull();
});

// ------------------------------------------------
// 退会まわり (ソース確認)
// ------------------------------------------------

test('退会: google_sub と pass_token も消去し、astorageのユーザーDBも削除する (ソース確認)', function () {
    $src = file_get_contents(N3S_TEST_ROOT . '/app/action/mypage.inc.php');
    expect($src)->toContain('google_sub="", pass_token=""')
        ->and($src)->toContain('astorage_user_db')
        ->and($src)->toContain("'-wal'")
        // 退会トークン不一致エラーでは入力値をエスケープしてから埋め込む
        ->and($src)->toContain('token_post_e')
        ->and($src)->not->toContain('($token_post)</a>');
});

// ------------------------------------------------
// ログイン試行のアカウント単位制限
// ------------------------------------------------

test('ログイン試行: 分散IPから同一アカウントへの失敗が多すぎると拒否される', function () {
    n3s_add_user('victim@example.com', 'right-password', '被害者');
    // 異なるIPから同一メールへの失敗を11件記録する (IP単位の制限にはかからない)
    for ($i = 0; $i < 11; $i++) {
        db_exec("INSERT INTO ip_check (key, ip, memo, ctime) VALUES(?,?,?,?)",
            [0, "10.9.8.{$i}", 'victim@example.com', time()], 'log');
    }
    $_POST = ['email' => 'victim@example.com', 'password' => 'right-password'];
    $out = n3s_test_capture(fn () => n3s_web_login_trylogin());
    expect($out)->toContain('ログイン失敗が多すぎます');
    expect(n3s_is_login())->toBeFalse();
});

// ------------------------------------------------
// パスワード再発行の宛先単位制限
// ------------------------------------------------

test('再発行: IPを変えても同一宛先への再発行は回数制限される', function () {
    $user_id = (int) n3s_add_user('mailbomb@example.com', 'password1', '宛先');
    for ($i = 0; $i < N3S_SETPW_REISSUE_MAX_TRIES; $i++) {
        n3s_setpw_record_reissue('mailbomb@example.com', "10.7.7.{$i}");
    }
    expect(n3s_setpw_recent_reissues('mailbomb@example.com', '192.0.2.1'))
        ->toBe(N3S_SETPW_REISSUE_MAX_TRIES);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
    $sent = n3s_test_call(fn () => n3s_web_login_setpw_sendmail($user_id, 'mailbomb@example.com', 'forgot'));
    expect($sent)->toBeFalse();
});

// ------------------------------------------------
// is_private の値域検証
// ------------------------------------------------

test('保存: is_private の想定外の値は保存できない', function () {
    $a = [
        'body' => str_repeat('あ', 40),
        'author' => '作者',
        'user_id' => 1,
        'is_private' => 3,
    ];
    $thrown = null;
    try {
        n3s_action_save_check_param($a, true);
    } catch (Exception $e) {
        $thrown = $e->getMessage();
    }
    expect($thrown)->toContain('公開設定の値が不正です');

    $a['is_private'] = 1;
    n3s_action_save_check_param($a, true);
    expect($a['is_private'])->toBe(1);
});

// ------------------------------------------------
// LIKE エスケープ
// ------------------------------------------------

test('検索: % や _ はワイルドカードとして扱われず、* は従来通り使える', function () {
    $owner = (int) n3s_add_user('like@example.com', 'password123', '検索者');
    foreach (['100%ジュース', '100点満点', '100_アンダー'] as $title) {
        db_insert(
            'INSERT INTO apps (title, author, user_id, copyright, is_private, show_list, ctime, mtime) VALUES (?,?,?,?,?,?,?,?)',
            [$title, '検索者', $owner, 'MIT', 0, 1, time(), time()]
        );
    }
    $_GET = ['search_word' => '100%', 'target' => 'normal'];
    $r = n3s_test_call(fn () => n3s_list_search());
    $titles = array_column($r['list'], 'title');
    expect($titles)->toBe(['100%ジュース']);

    $_GET = ['search_word' => '100_', 'target' => 'normal'];
    $r = n3s_test_call(fn () => n3s_list_search());
    $titles = array_column($r['list'], 'title');
    expect($titles)->toBe(['100_アンダー']);

    $_GET = ['search_word' => '100*', 'target' => 'normal'];
    $r = n3s_test_call(fn () => n3s_list_search());
    expect(array_column($r['list'], 'title'))->toHaveCount(3);
});

// ------------------------------------------------
// bad (通報) のユーザー単位一意化
// ------------------------------------------------

function _sec286_bad_report(int $app_id): void
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['page' => (string) $app_id, 'q' => 'up'];
    $_REQUEST = ['page' => (string) $app_id, 'q' => 'up', 'edit_token' => n3s_getEditToken()];
    n3s_test_capture(fn () => echo_bad());
}

test('bad: 同一ユーザーはIPを変えても1作品に1回だけ、管理者は初回のみ+100', function () {
    n3s_add_user('admin286@example.com', 'password123', '管理者'); // user_id=1 が管理者
    $owner = (int) n3s_add_user('bad286-owner@example.com', 'password123', '作者');
    $app_id = (int) db_insert(
        'INSERT INTO apps (title, author, user_id, copyright, is_private, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['対象作品', '作者', $owner, 'MIT', 0, time(), time()]
    );

    n3s_add_user('bad286-a@example.com', 'password123', '通報者A');
    n3s_web_login_execute('bad286-a@example.com', 'password123');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
    _sec286_bad_report($app_id);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.2'; // IPを変えても同一ユーザーは加算されない
    _sec286_bad_report($app_id);
    $r = db_get1('SELECT bad FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['bad']))->toBe(1);

    n3s_add_user('bad286-b@example.com', 'password123', '通報者B');
    n3s_web_login_execute('bad286-b@example.com', 'password123');
    _sec286_bad_report($app_id);
    $r = db_get1('SELECT bad FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['bad']))->toBe(2);

    n3s_web_login_execute('admin286@example.com', 'password123');
    _sec286_bad_report($app_id);
    _sec286_bad_report($app_id); // 管理者も2回目は加算されない
    $r = db_get1('SELECT bad FROM apps WHERE app_id=?', [$app_id]);
    expect(intval($r['bad']))->toBe(102);
    expect(db_get('SELECT * FROM bad_reports WHERE app_id=?', [$app_id]))->toHaveCount(3);
});

test('bad: 通報リセットで報告者の記録も消える (ソース確認)', function () {
    $owner = (int) n3s_add_user('bad286r-owner@example.com', 'password123', '作者');
    $app_id = (int) db_insert(
        'INSERT INTO apps (title, author, user_id, copyright, is_private, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['対象作品', '作者', $owner, 'MIT', 0, time(), time()]
    );
    n3s_add_user('bad286r-a@example.com', 'password123', '通報者');
    n3s_web_login_execute('bad286r-a@example.com', 'password123');
    _sec286_bad_report($app_id);
    expect(db_get('SELECT * FROM bad_reports WHERE app_id=?', [$app_id]))->toHaveCount(1);

    // リセット関数 (n3s_action_save_reset_bad) と作品削除の両方が
    // bad_reports の行を消すこと
    $src = file_get_contents(N3S_TEST_ROOT . '/app/action/save.inc.php');
    expect(substr_count($src, 'DELETE FROM bad_reports WHERE app_id=?'))->toBeGreaterThanOrEqual(2);
});

// ------------------------------------------------
// reset 系の POST 必須化
// ------------------------------------------------

test('reset_broken: GETではリセットされず、POSTでリセットされる', function () {
    $owner = (int) n3s_add_user('rb286@example.com', 'password123', '作者');
    $app_id = (int) db_insert(
        'INSERT INTO apps (title, author, user_id, copyright, is_private, broken_report, ctime, mtime) VALUES (?,?,?,?,?,?,?,?)',
        ['対象作品', '作者', $owner, 'MIT', 0, 1, time(), time()]
    );
    db_insert('INSERT INTO broken_reports (app_id, user_id, ip, ctime) VALUES (?,?,?,?)',
        [$app_id, $owner, '203.0.113.1', time()]);
    n3s_web_login_execute('rb286@example.com', 'password123');

    $_GET = ['page' => (string) $app_id];
    $_REQUEST = ['page' => (string) $app_id, 'edit_token' => n3s_getEditToken()];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $out = n3s_test_capture(fn () => n3s_action_save_reset_broken([]));
    expect($out)->toContain('リクエストが不正');
    expect(intval(db_get1('SELECT broken_report FROM apps WHERE app_id=?', [$app_id])['broken_report']))->toBe(1);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    n3s_test_capture(fn () => n3s_action_save_reset_broken([]));
    expect(intval(db_get1('SELECT broken_report FROM apps WHERE app_id=?', [$app_id])['broken_report']))->toBe(0);
    expect(db_get('SELECT * FROM broken_reports WHERE app_id=?', [$app_id]))->toHaveCount(0);
});

// ------------------------------------------------
// widget_frame のトークン照合
// ------------------------------------------------

test('widget_frame: 表示作品と一致しない api_token は無効化される', function () {
    $token_app1 = n3s_astorage_token_create(1, 0);
    expect(n3s_widget_frame_api_token($token_app1, 1))->toBe($token_app1);
    expect(n3s_widget_frame_api_token($token_app1, 2))->toBe('');
    expect(n3s_widget_frame_api_token('garbage.token', 1))->toBe('');
    expect(n3s_widget_frame_api_token('', 1))->toBe('');
});

// ------------------------------------------------
// コメントのレート制限
// ------------------------------------------------

test('コメント: 1時間の上限を超えると投稿できない', function () {
    global $n3s_config;
    $n3s_config['comment_post_limit_per_hour'] = 2;
    $user_id = (int) n3s_add_user('cm286@example.com', 'password123', '投稿者');
    $app_id = (int) db_insert(
        'INSERT INTO apps (title, author, user_id, copyright, is_private, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['対象作品', '作者', $user_id, 'MIT', 0, time(), time()]
    );
    $ip = $_SERVER['REMOTE_ADDR'];
    for ($i = 0; $i < 2; $i++) {
        db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, ip, status, fav, ctime, mtime) VALUES (?,?,0,?,?,?,?,0,?,?)",
            [$user_id, $app_id, '投稿者', "既存コメント{$i}", $ip, 'pending', time(), time()], 'main');
    }
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user_id;
    $_SESSION['n3s_login_info'] = ['user_id' => $user_id, 'name' => '投稿者', 'email' => 'cm286@example.com'];
    $_POST = ['app_id' => (string) $app_id, 'body' => '3件目の投稿', 'edit_token' => n3s_getEditToken()];
    $_GET = ['mode' => 'add'];
    $_REQUEST = array_merge($_GET, $_POST);
    $res = json_decode(n3s_test_capture(fn () => n3s_api_comment()), true);
    expect($res['result'])->toBeFalse();
    expect($res['msg'])->toContain('短時間に多くのコメント');
    expect(db_get1("SELECT COUNT(*) AS n FROM comments WHERE body = '3件目の投稿'", [], 'main')['n'])->toBe(0);
});

test('コメント: 他ユーザーの投稿は自分の上限に数えられない', function () {
    global $n3s_config;
    $n3s_config['comment_post_limit_per_hour'] = 2;
    $user_a = (int) n3s_add_user('cm286x-a@example.com', 'password123', '投稿者A');
    $user_b = (int) n3s_add_user('cm286x-b@example.com', 'password123', '投稿者B');
    $app_id = (int) db_insert(
        'INSERT INTO apps (title, author, user_id, copyright, is_private, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['対象作品', '作者', $user_a, 'MIT', 0, time(), time()]
    );
    // A が別IPから上限を超えて投稿済みでも、B の投稿は妨げられない
    for ($i = 0; $i < 3; $i++) {
        db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, ip, status, fav, ctime, mtime) VALUES (?,?,0,'投稿者A',?,?,'pending',0,?,?)",
            [$user_a, $app_id, "Aの投稿{$i}", '10.8.8.8', time(), time()], 'main');
    }
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user_b;
    $_SESSION['n3s_login_info'] = ['user_id' => $user_b, 'name' => '投稿者B', 'email' => 'cm286x-b@example.com'];
    $_POST = ['app_id' => (string) $app_id, 'body' => 'Bの投稿', 'edit_token' => n3s_getEditToken()];
    $_GET = ['mode' => 'add'];
    $_REQUEST = array_merge($_GET, $_POST);
    $res = json_decode(n3s_test_capture(fn () => n3s_api_comment()), true);
    expect($res['result'])->toBeTrue();
});

// ------------------------------------------------
// プロフィール画像・扉絵の実体削除
// ------------------------------------------------

function _sec286_make_png(): string
{
    $path = tempnam(sys_get_temp_dir(), 'n3s286_') . '.png';
    $im = imagecreatetruecolor(10, 10);
    imagepng($im, $path);
    imagedestroy($im);
    return $path;
}

function _sec286_image_files(int $image_id): array
{
    global $n3s_config;
    $dir = $n3s_config['dir_images'] . '/' . str_pad((string) floor($image_id / 100), 3, '0', STR_PAD_LEFT);
    $files = glob($dir . '/' . $image_id . '-*');
    return $files === false ? [] : $files;
}

test('プロフィール画像: 差し替えると旧画像の行とファイルが削除される', function () {
    global $n3s_config;
    $n3s_config['dir_images'] = N3S_TEST_TMP . '/test_images_286';
    if (!is_dir($n3s_config['dir_images'])) {
        mkdir($n3s_config['dir_images'], 0777, true);
    }
    $user_id = (int) n3s_add_user('prof286@example.com', 'password123', 'プロフ');
    $png = _sec286_make_png();
    $file = ['error' => UPLOAD_ERR_OK, 'size' => filesize($png), 'tmp_name' => $png, 'name' => 'a.png'];

    $id1 = (int) n3s_save_user_image($user_id, $file);
    expect($id1)->toBeGreaterThan(0);
    expect(db_get1('SELECT * FROM images WHERE image_id=?', [$id1]))->not->toBeEmpty();
    expect(_sec286_image_files($id1))->not->toBeEmpty();

    $id2 = (int) n3s_save_user_image($user_id, $file);
    expect($id2)->toBeGreaterThan($id1);
    expect(db_get1('SELECT * FROM images WHERE image_id=?', [$id1]))->toBeEmpty();
    expect(_sec286_image_files($id1))->toBeEmpty();
    $u = db_get1('SELECT image_id FROM users WHERE user_id=?', [$user_id], 'users');
    expect(intval($u['image_id']))->toBe($id2);
    @unlink($png);
});

test('扉絵: 差し替え・解除で専用アップロードは削除され、一般素材は残る', function () {
    global $n3s_config;
    $n3s_config['dir_images'] = N3S_TEST_TMP . '/test_images_286_cover';
    if (!is_dir($n3s_config['dir_images'])) {
        mkdir($n3s_config['dir_images'], 0777, true);
    }
    $user_id = (int) n3s_add_user('cover286@example.com', 'password123', '作者');
    $app_id = (int) db_insert(
        'INSERT INTO apps (title, author, user_id, copyright, is_private, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['扉絵テスト作品', '作者', $user_id, 'MIT', 0, time(), time()]
    );
    $png = _sec286_make_png();
    $file = ['error' => UPLOAD_ERR_OK, 'size' => filesize($png), 'tmp_name' => $png, 'name' => 'c.png'];

    $c1 = (int) n3s_save_cover_image($app_id, $user_id, $file);
    $c2 = (int) n3s_save_cover_image($app_id, $user_id, $file);
    expect($c2)->toBeGreaterThan($c1);
    expect(db_get1('SELECT * FROM images WHERE image_id=?', [$c1]))->toBeEmpty();
    expect(_sec286_image_files($c1))->toBeEmpty();

    // 解除でも専用アップロードは削除される
    n3s_unset_cover_image($app_id);
    expect(db_get1('SELECT * FROM images WHERE image_id=?', [$c2]))->toBeEmpty();
    expect(intval(db_get1('SELECT image_id FROM apps WHERE app_id=?', [$app_id])['image_id']))->toBe(0);

    // 一般素材 (専用アップロードではない) を扉絵に流用した場合は残る
    $mat = (int) db_insert(
        'INSERT INTO images (title, user_id, copyright, app_id, image_name, token, filename, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?)',
        ['普通の素材', $user_id, 'CC0', $app_id, '', '', 'dummy.png', time(), time()]
    );
    db_exec('UPDATE apps SET image_id=? WHERE app_id=?', [$mat, $app_id]);
    n3s_unset_cover_image($app_id);
    expect(db_get1('SELECT * FROM images WHERE image_id=?', [$mat]))->not->toBeEmpty();
    expect(intval(db_get1('SELECT image_id FROM apps WHERE app_id=?', [$app_id])['image_id']))->toBe(0);
    @unlink($png);
});

// ------------------------------------------------
// 管理操作の監査ログ
// ------------------------------------------------

test('管理: コメントブロック解除が監査ログに残る', function () {
    $admin_id = (int) n3s_add_user('admin286b@example.com', 'password123', '管理者'); // 最初のユーザー = 管理者
    $target = (int) n3s_add_user('blocked286@example.com', 'password123', '対象者');
    db_insert('INSERT INTO comment_user_blocks (user_id, ng_count, blocked, mtime) VALUES (?,?,?,?)',
        [$target, 3, 1, time()], 'main');
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $admin_id;
    $_SESSION['n3s_login_info'] = ['user_id' => $admin_id, 'name' => '管理者', 'email' => 'admin286b@example.com'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['mode' => 'unblock_comment', 'user_id' => (string) $target, 'edit_token' => n3s_getEditToken()];
    $_GET = [];
    $_REQUEST = $_POST;
    n3s_test_capture(fn () => n3s_web_admin());
    expect(intval(db_get1('SELECT blocked FROM comment_user_blocks WHERE user_id=?', [$target], 'main')['blocked']))->toBe(0);
    $log = db_get1("SELECT * FROM logs WHERE kind='admin_unblock_comment'", [], 'log');
    expect($log)->not->toBeEmpty();
    expect($log['body'])->toContain("user_id={$target}");
});

// ------------------------------------------------
// バッチの多重起動防止・その他 (ソース確認を含む)
// ------------------------------------------------

test('バッチ: 多重起動ロックは同じ名前で二重に取得できない', function () {
    $h1 = n3s_cli_batch_lock('phpunit_probe_286');
    expect($h1)->not->toBeFalse();
    $h2 = n3s_cli_batch_lock('phpunit_probe_286');
    expect($h2)->toBeFalse();
    if (is_resource($h1)) {
        fclose($h1);
    }
    $h3 = n3s_cli_batch_lock('phpunit_probe_286');
    expect($h3)->not->toBeFalse();
    if (is_resource($h3)) {
        fclose($h3);
    }
    @unlink(sys_get_temp_dir() . '/n3s_batch_phpunit_probe_286.lock');
});

test('バッチ・その他: ロック組み込みと審査ガード、プロンプト、Webhook、postMessage (ソース確認)', function () {
    foreach (['comment_audit.php', 'app_count.php', 'image_count.php', 'nadesiko3hub_sync.php'] as $f) {
        $src = file_get_contents(N3S_TEST_ROOT . '/scripts/' . $f);
        expect($src)->toContain('n3s_cli_batch_lock');
    }
    $cdn = file_get_contents(N3S_TEST_ROOT . '/scripts/cdn_download_count.php');
    expect($cdn)->toContain('n3s_batch_cdn_download_count.lock');

    $audit = file_get_contents(N3S_TEST_ROOT . '/scripts/comment_audit.php');
    expect($audit)->toContain("AND status = 'pending'");

    $llm = file_get_contents(N3S_TEST_ROOT . '/app/comment_audit_openrouter.inc.php');
    expect(substr_count($llm, '判定ルールとして扱わない'))->toBeGreaterThanOrEqual(2);

    $lib = file_get_contents(N3S_TEST_ROOT . '/app/n3s_lib.inc.php');
    expect($lib)->toContain("'allowed_mentions' => array('parse' => array())");

    $show = file_get_contents(N3S_TEST_ROOT . '/app/template/show.html');
    expect($show)->toContain('e.source !== iframe.contentWindow');

    $menu = file_get_contents(N3S_TEST_ROOT . '/app/template/parts_html_menu.html');
    expect($menu)->toContain('<form method="POST" action="index.php?page=my&amp;action=logout"');

    $mypage = file_get_contents(N3S_TEST_ROOT . '/app/template/mypage.html');
    expect($mypage)->toContain('<form method="POST" action="index.php?page=my&amp;action=logout">')
        ->and($mypage)->not->toContain('{{ $link_logout }}');
});
