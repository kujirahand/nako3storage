<?php
// ========================================================
// nako3storage tests/Feature/CommentTest.php
// ========================================================
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/comment.inc.php';

if (!function_exists('check_comment_with_gemini')) {
    function check_comment_with_gemini($body, $api_key)
    {
        if ($api_key === 'trigger-error') {
            return 'error';
        }
        return 'approved';
    }
}

beforeEach(function () {
    n3s_test_setup();
});

test('未ログインでのコメント投稿・いいねは拒否される', function () {
    global $n3s_config;
    
    $_POST['app_id'] = 1;
    $_POST['parent_id'] = 0;
    $_POST['body'] = 'テストコメントです。';
    $_POST['edit_token'] = n3s_getEditToken();
    $_GET['mode'] = 'add';
    $_REQUEST = array_merge($_GET, $_POST);
    
    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(false);
    expect($res['msg'])->toBe('ログインが必要です。');

    $_POST['comment_id'] = 1;
    $_GET['mode'] = 'fav';
    $_REQUEST = array_merge($_GET, $_POST);
    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(false);
});

test('ログイン中ならコメントを投稿できる（status=pendingになる）', function () {
    $user_id = n3s_test_add_legacy_user('test@nadesi.com', 'password123', 'テストユーザー');
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = 'テストユーザー';
    $_SESSION['email'] = 'test@nadesi.com';
    $_SESSION['n3s_login_info'] = [
        'user_id' => $user_id,
        'name' => 'テストユーザー',
        'email' => 'test@nadesi.com',
    ];

    $_POST['app_id'] = 1;
    $_POST['parent_id'] = 0;
    $_POST['body'] = 'これはテストコメント本文です。';
    $_POST['edit_token'] = n3s_getEditToken();
    $_GET['mode'] = 'add';
    $_REQUEST = array_merge($_GET, $_POST);

    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(true);
    expect($res['msg'])->toContain('審査した後に公開');

    // DB に status=pending で保存されているか検証
    $comment = db_get1("SELECT * FROM comments WHERE body = ?", ['これはテストコメント本文です。'], 'main');
    expect($comment)->not->toBeNull();
    expect($comment['status'])->toBe('pending');
    expect((int)$comment['user_id'])->toBe($user_id);
});

test('NGワードが含まれるコメントは弾かれる', function () {
    global $n3s_config;
    $n3s_config['ng_words'] = ['だめ'];

    $user_id = n3s_test_add_legacy_user('test@nadesi.com', 'password123', 'テストユーザー');
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = 'テストユーザー';
    $_SESSION['email'] = 'test@nadesi.com';
    $_SESSION['n3s_login_info'] = [
        'user_id' => $user_id,
        'name' => 'テストユーザー',
        'email' => 'test@nadesi.com',
    ];

    $_POST['app_id'] = 1;
    $_POST['parent_id'] = 0;
    $_POST['body'] = 'これは だめ です。';
    $_POST['edit_token'] = n3s_getEditToken();
    $_GET['mode'] = 'add';
    $_REQUEST = array_merge($_GET, $_POST);

    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(false);
    expect($res['msg'])->toContain('不適切な言葉');
});

test('コメントのいいね機能', function () {
    $user_id = n3s_test_add_legacy_user('test@nadesi.com', 'password123', 'テストユーザー');
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = 'テストユーザー';
    $_SESSION['email'] = 'test@nadesi.com';
    $_SESSION['n3s_login_info'] = [
        'user_id' => $user_id,
        'name' => 'テストユーザー',
        'email' => 'test@nadesi.com',
    ];

    // 公開済みコメントを用意する
    $now = time();
    $comment_id = db_insert(
        "INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime, mtime)
         VALUES (?, 1, 0, '太郎', '公開テストコメント', 'approved', 0, ?, ?)",
        [$user_id, $now, $now],
        'main'
    );

    $_POST['comment_id'] = $comment_id;
    $_POST['edit_token'] = n3s_getEditToken();
    $_GET['mode'] = 'fav';
    $_REQUEST = array_merge($_GET, $_POST);

    // 1回目のいいね（追加）
    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(true);
    expect($res['action'])->toBe('added');
    expect($res['fav'])->toBe(1);

    // DB確認
    $comment = db_get1("SELECT * FROM comments WHERE comment_id = ?", [$comment_id], 'main');
    expect((int)$comment['fav'])->toBe(1);
    $like = db_get1("SELECT * FROM comment_likes WHERE comment_id = ? AND user_id = ?", [$comment_id, $user_id], 'main');
    expect($like)->not->toBeNull();

    // 2回目のいいね（解除）
    $_REQUEST = array_merge($_GET, $_POST);
    $out2 = n3s_test_capture(fn() => n3s_api_comment());
    $res2 = json_decode($out2, true);
    expect($res2['result'])->toBe(true);
    expect($res2['action'])->toBe('removed');
    expect($res2['fav'])->toBe(0);

    // DB確認
    $comment2 = db_get1("SELECT * FROM comments WHERE comment_id = ?", [$comment_id], 'main');
    expect((int)$comment2['fav'])->toBe(0);
});

test('コメントリストの取得とトータルいいねソート機能', function () {
    $now = time();
    // 2つのスレッド（親コメント）を準備する
    // スレッド1: 親(いいね=0) + 子(いいね=1) = トータル1
    // スレッド2: 親(いいね=3) = トータル3
    // 期待結果: スレッド2が1件目にくること
    
    $p1 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '親1', 'スレッド1', 'approved', 0, ?)", [$now], 'main');
    $c1 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, ?, '子1', 'スレッド1返信', 'approved', 1, ?)", [$p1, $now + 1], 'main');

    $p2 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '親2', 'スレッド2', 'approved', 3, ?)", [$now + 2], 'main');

    $_GET['app_id'] = 1;
    $_GET['mode'] = 'list';

    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(true);
    
    // スレッド2が先頭にきており、次にスレッド1が並んでいること
    expect($res['comments'][0]['comment_id'])->toBe((int)$p2);
    expect($res['comments'][1]['comment_id'])->toBe((int)$p1);
    
    // スレッド1の返信（子）が replies に含まれていること
    expect(count($res['comments'][1]['replies']))->toBe(1);
    expect($res['comments'][1]['replies'][0]['comment_id'])->toBe((int)$c1);
});

test('自動審査バッチ処理 (comment_audit.php) の動作検証', function () {
    // 審査待ちコメントをDBに入れる
    $now = time();
    $c1 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '太郎', '普通コメント', 'pending', 0, ?)", [$now], 'main');
    
    // 1. auto_approve をオンにした状態で実行
    global $n3s_config;
    $n3s_config['comment_audit_auto_approve'] = true;
    
    // scripts/comment_audit.php をシミュレート
    ob_start();
    include N3S_TEST_ROOT . '/scripts/comment_audit.php';
    $out = ob_get_clean();
    
    expect($out)->toContain("ステータスを 'approved' に更新");
    
    $comment = db_get1("SELECT status FROM comments WHERE comment_id = ?", [$c1], 'main');
    expect($comment['status'])->toBe('approved');
});

test('自動審査バッチ処理 - APIキー未指定時にパスして承認されること', function () {
    // 審査待ちコメントをDBに入れる
    $now = time();
    $c2 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '次郎', 'APIキー無しコメント', 'pending', 0, ?)", [$now], 'main');
    
    global $n3s_config;
    $n3s_config['gemini_api_key'] = '';
    $n3s_config['comment_audit_auto_approve'] = false;
    
    // scripts/comment_audit.php をシミュレート
    ob_start();
    include N3S_TEST_ROOT . '/scripts/comment_audit.php';
    $out = ob_get_clean();
    
    expect($out)->toContain("審査をパスして自動承認");
    expect($out)->toContain("ステータスを 'approved' に更新");
    
    $comment = db_get1("SELECT status FROM comments WHERE comment_id = ?", [$c2], 'main');
    expect($comment['status'])->toBe('approved');
});

test('コメントリスト取得時、pending状態のコメントも取得でき、内容が上書きされること', function () {
    $now = time();
    $p = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '親ペンド', '秘密の未審査文面', 'pending', 0, ?)", [$now], 'main');
    $c = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, ?, '子ペンド', '秘密の未審査返信', 'pending', 0, ?)", [$p, $now + 1], 'main');

    $_GET['app_id'] = 1;
    $_GET['mode'] = 'list';
    $_REQUEST = $_GET;

    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(true);
    
    // pending コメントが取得できていること
    expect(count($res['comments']))->toBe(1);
    expect($res['comments'][0]['comment_id'])->toBe((int)$p);
    expect($res['comments'][0]['status'])->toBe('pending');
    
    // 内容が上書きされていること
    expect($res['comments'][0]['body'])->toBe('(現在内容を審査中…)');
    
    // 返信についても同様
    expect(count($res['comments'][0]['replies']))->toBe(1);
    expect($res['comments'][0]['replies'][0]['comment_id'])->toBe((int)$c);
    expect($res['comments'][0]['replies'][0]['status'])->toBe('pending');
    expect($res['comments'][0]['replies'][0]['body'])->toBe('(現在内容を審査中…)');
});

test('コメント削除機能の権限および動作検証', function () {
    $now = time();
    // 太郎(user_id=1)のコメント
    $c1 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '太郎', '太郎のコメ', 'approved', 0, ?)", [$now], 'main');
    // 次郎(user_id=2)の返信コメント
    $c2 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (2, 1, ?, '次郎', '次郎の返信', 'approved', 0, ?)", [$c1, $now + 1], 'main');

    // 1. 未ログインでの削除要求は失敗すること
    $_SESSION = [];
    $_GET = ['mode' => 'delete'];
    $_POST = ['comment_id' => $c1, 'edit_token' => n3s_getEditToken()];
    $_REQUEST = array_merge($_GET, $_POST);
    
    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(false);
    expect($res['msg'])->toContain('ログインが必要です');

    // 2. ログインした他人がコメントを削除しようとしても失敗すること
    // 次郎(user_id=2)でログインして、太郎(user_id=1)のコメントを削除しようとする
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = 2;
    $_SESSION['name'] = '次郎';
    $_SESSION['email'] = 'jiro@example.com';
    $_SESSION['n3s_login_info'] = [
        'user_id' => 2,
        'name' => '次郎',
        'email' => 'jiro@example.com',
    ];
    $_GET = ['mode' => 'delete'];
    $_POST = ['comment_id' => $c1, 'edit_token' => n3s_getEditToken()];
    $_REQUEST = array_merge($_GET, $_POST);
    
    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(false);
    expect($res['msg'])->toContain('他人のコメントは削除できません');

    // 3. コメント投稿者本人が正当に削除できること (太郎がログインして自分のコメントを削除)
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = 1;
    $_SESSION['name'] = '太郎';
    $_SESSION['email'] = 'taro@example.com';
    $_SESSION['n3s_login_info'] = [
        'user_id' => 1,
        'name' => '太郎',
        'email' => 'taro@example.com',
    ];
    $_GET = ['mode' => 'delete'];
    $_POST = ['comment_id' => $c1, 'edit_token' => n3s_getEditToken()];
    $_REQUEST = array_merge($_GET, $_POST);
    
    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(true);
    expect($res['msg'])->toContain('削除しました');
    
    // DBから削除されていること、および親コメント削除により子コメントも削除されていること
    $comment1 = db_get1("SELECT * FROM comments WHERE comment_id = ?", [$c1], 'main');
    $comment2 = db_get1("SELECT * FROM comments WHERE comment_id = ?", [$c2], 'main');
    expect($comment1)->toBeFalse();
    expect($comment2)->toBeFalse();
    
    // 4. 管理者が任意のコメントを削除できること
    // 新しいコメントを登録
    $c3 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '太郎', '太郎のコメ2', 'approved', 0, ?)", [$now], 'main');
    
    // 管理者(user_id=99)でログイン。configに管理者IDを設定
    global $n3s_config;
    $n3s_config['admin_users'] = [99];
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = 99;
    $_SESSION['name'] = '管理者';
    $_SESSION['email'] = 'admin@example.com';
    $_SESSION['n3s_login_info'] = [
        'user_id' => 99,
        'name' => '管理者',
        'email' => 'admin@example.com',
    ];
    
    $_GET = ['mode' => 'delete'];
    $_POST = ['comment_id' => $c3, 'edit_token' => n3s_getEditToken()];
    $_REQUEST = array_merge($_GET, $_POST);
    
    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(true);
    
    $comment3 = db_get1("SELECT * FROM comments WHERE comment_id = ?", [$c3], 'main');
    expect($comment3)->toBeFalse();
});

test('自動審査バッチ処理 - キャッシュ機能の動作検証', function () {
    $now = time();
    
    // 審査待ちコメントを2件入れる（本文は同一のものにする）
    $c1 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '太郎', '同一のコメント本文', 'pending', 0, ?)", [$now], 'main');
    $c2 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '次郎', '同一のコメント本文', 'pending', 0, ?)", [$now + 1], 'main');
    
    // APIキーを指定し、自動承認はオフ
    global $n3s_config;
    $n3s_config['gemini_api_key'] = 'mock-key';
    $n3s_config['comment_audit_auto_approve'] = false;
    
    // テスト用のダミーの審査結果をキャッシュテーブルに事前に手動でインサートしておく
    // これにより、APIが呼ばれずにキャッシュから'approved'になるはず
    $body_hash = hash('sha256', trim('同一のコメント本文'));
    db_exec(
        "INSERT INTO comment_audit_cache (body_hash, result, reason, ctime) VALUES (?, 'approved', 'Test Cache', ?)",
        [$body_hash, $now],
        'main'
    );
    
    // バッチを実行
    ob_start();
    include N3S_TEST_ROOT . '/scripts/comment_audit.php';
    $out = ob_get_clean();
    
    // キャッシュが適用されていることを検証
    expect($out)->toContain("キャッシュされた審査結果を適用します");
    
    $comment1 = db_get1("SELECT status FROM comments WHERE comment_id = ?", [$c1], 'main');
    $comment2 = db_get1("SELECT status FROM comments WHERE comment_id = ?", [$c2], 'main');
    expect($comment1['status'])->toBe('approved');
    expect($comment2['status'])->toBe('approved');
});

test('不承認（ng）ステータスのコメント一覧取得時の本文マスク処理', function () {
    $now = time();
    // 承認されたコメント、審査中のコメント、却下されたコメントをそれぞれインサート
    $c1 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '太郎', '公開コメント', 'approved', 0, ?)", [$now], 'main');
    $c2 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (2, 1, 0, '次郎', '審査中コメント', 'pending', 0, ?)", [$now + 1], 'main');
    $c3 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (3, 1, 0, '三郎', '悪口が含まれているコメント', 'ng', 0, ?)", [$now + 2], 'main');

    // コメント一覧APIを実行
    $_GET = ['app_id' => 1];
    $_POST = [];
    $_REQUEST = $_GET;

    $out = n3s_test_capture(fn() => n3s_api_comment());
    $res = json_decode($out, true);
    expect($res['result'])->toBe(true);

    $comments = $res['comments'];
    // 3件とも返されていること
    expect(count($comments))->toBe(3);

    // それぞれの本文が正しくマスク/出力されていること
    // 太郎（c1）: 承認済みなので本文そのまま
    $comment1 = array_values(array_filter($comments, fn($c) => $c['comment_id'] == $c1))[0];
    expect($comment1['body'])->toBe('公開コメント');
    expect($comment1['status'])->toBe('approved');

    // 次郎（c2）: 審査中なのでマスク
    $comment2 = array_values(array_filter($comments, fn($c) => $c['comment_id'] == $c2))[0];
    expect($comment2['body'])->toBe('(現在内容を審査中…)');
    expect($comment2['status'])->toBe('pending');

    // 三郎（c3）: 却下なので「AIによりコメントが却下されました」にマスク
    $comment3 = array_values(array_filter($comments, fn($c) => $c['comment_id'] == $c3))[0];
    expect($comment3['body'])->toBe('(AIによりコメントが却下されました)');
    expect($comment3['status'])->toBe('ng');

    // DB上には「悪口が含まれているコメント」の生内容が保持されていること
    $db_comment3 = db_get1("SELECT * FROM comments WHERE comment_id = ?", [$c3], 'main');
    expect($db_comment3['body'])->toBe('悪口が含まれているコメント');
});

test('自動審査バッチ処理 - エラー発生時に保留すること、および全件エラー時のメール送信検証', function () {
    $now = time();
    
    // 審査待ちのコメントを2件登録
    $c1 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '太郎', '保留にするコメント1', 'pending', 0, ?)", [$now], 'main');
    $c2 = db_insert("INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime) VALUES (1, 1, 0, '太郎', '保留にするコメント2', 'pending', 0, ?)", [$now + 1], 'main');
    
    // APIキーに trigger-error を指定してエラーを発生させる
    global $n3s_config;
    $n3s_config['gemini_api_key'] = 'trigger-error';
    $n3s_config['comment_audit_auto_approve'] = false;
    $n3s_config['admin_email'] = 'admin@example.com';
    
    // バッチを実行
    ob_start();
    include N3S_TEST_ROOT . '/scripts/comment_audit.php';
    $out = ob_get_clean();
    
    // エラーにより保留され、全件エラーのためメール送信がトリガーされたことを検証
    expect($out)->toContain("審査中にエラーが発生したため、判定結果を変更せず保留します");
    expect($out)->toContain("すべての判定がエラーになったため、管理者にアラートメールを送信しました");
    
    // DB上のステータスが更新されず 'pending' のままであること
    $comment1 = db_get1("SELECT status FROM comments WHERE comment_id = ?", [$c1], 'main');
    $comment2 = db_get1("SELECT status FROM comments WHERE comment_id = ?", [$c2], 'main');
    expect($comment1['status'])->toBe('pending');
    expect($comment2['status'])->toBe('pending');
});

