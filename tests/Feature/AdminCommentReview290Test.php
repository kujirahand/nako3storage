<?php
// ========================================================
// nako3storage tests/Feature/AdminCommentReview290Test.php
// 管理画面でのコメント手動審査 (#290)
// ========================================================
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/admin.inc.php';

// 外部APIを呼ばないスタブ (CommentTest.php と同じ)
if (!function_exists('check_comment_with_openrouter')) {
    function check_comment_with_openrouter($body, $api_key, $model)
    {
        if ($api_key === 'trigger-error') {
            return 'error';
        }
        if ($api_key === 'trigger-ng') {
            return 'ng';
        }
        return 'approved';
    }
}

function n3s_test_admin_review_login(): int
{
    $admin_id = n3s_test_add_legacy_user('admin@example.com', 'password123', '管理者');
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $admin_id;
    $_SESSION['n3s_login_info'] = ['user_id' => $admin_id, 'name' => '管理者', 'email' => 'admin@example.com'];
    return $admin_id;
}

function n3s_test_admin_review_post(array $post): string
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = [];
    $_POST = $post;
    $_REQUEST = $_POST;
    return n3s_test_capture(fn() => n3s_web_admin());
}

function n3s_test_admin_review_comment(int $user_id, string $body, string $status): int
{
    return (int)db_insert(
        "INSERT INTO comments (user_id, app_id, parent_id, name, body, status, fav, ctime, mtime) VALUES (?, 1, 0, '名前', ?, ?, 0, ?, ?)",
        [$user_id, $body, $status, time(), time()],
        'main'
    );
}

function n3s_test_admin_review_status(int $id): string
{
    return db_get1('SELECT status FROM comments WHERE comment_id = ?', [$id], 'main')['status'];
}

function n3s_test_admin_review_count(): int
{
    return intval(db_get1('SELECT comment_count FROM apps WHERE app_id = 1', [], 'main')['comment_count']);
}

beforeEach(function () {
    n3s_test_setup();
    db_insert("INSERT INTO apps (app_id, user_id, title, author, is_private, editkey, ctime, mtime, comment_count) VALUES (1, 1, 'テスト作品1', '太郎', 0, 'editkey1', 123, 123, 0)", [], 'main');
    $this->admin_id = n3s_test_admin_review_login();
    $this->poster_id = n3s_test_add_legacy_user('poster@example.com', 'password123', '投稿者');
});

test('管理画面に審査待ちと公開中のコメントが表示される', function () {
    n3s_test_admin_review_comment($this->poster_id, '審査待ちの本文', 'pending');
    n3s_test_admin_review_comment($this->poster_id, '公開中の本文', 'approved');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $html = n3s_test_capture(fn() => n3s_web_admin());
    expect($html)->toContain('審査待ちの本文');
    expect($html)->toContain('公開中の本文');
    expect($html)->toContain('name="ng_ids[]"');
    expect($html)->toContain('反映');
});

test('review_comments でチェックした方だけNGになり、他は承認される', function () {
    $c1 = n3s_test_admin_review_comment($this->poster_id, '普通', 'pending');
    $c2 = n3s_test_admin_review_comment($this->poster_id, '悪い', 'pending');
    n3s_test_admin_review_post([
        'mode' => 'review_comments',
        'comment_ids' => [(string)$c1, (string)$c2],
        'ng_ids' => [(string)$c2],
        'edit_token' => n3s_getEditToken(),
    ]);
    expect(n3s_test_admin_review_status($c1))->toBe('approved');
    expect(n3s_test_admin_review_status($c2))->toBe('ng');
    expect(n3s_test_admin_review_count())->toBe(1);
    $block = db_get1('SELECT ng_count, blocked FROM comment_user_blocks WHERE user_id = ?', [$this->poster_id], 'main');
    expect(intval($block['ng_count']))->toBe(1);
    expect(intval($block['blocked']))->toBe(0);
});

test('同一ユーザーの3件をNGにするとブロックされる', function () {
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $ids[] = (string)n3s_test_admin_review_comment($this->poster_id, 'NG' . $i, 'pending');
    }
    n3s_test_admin_review_post([
        'mode' => 'review_comments',
        'comment_ids' => $ids,
        'ng_ids' => $ids,
        'edit_token' => n3s_getEditToken(),
    ]);
    $block = db_get1('SELECT ng_count, blocked FROM comment_user_blocks WHERE user_id = ?', [$this->poster_id], 'main');
    expect(intval($block['ng_count']))->toBe(3);
    expect(intval($block['blocked']))->toBe(1);
    expect(n3s_test_admin_review_count())->toBe(0);
});

test('承認済みのコメントを再送信しても二重加算されない', function () {
    $c1 = n3s_test_admin_review_comment($this->poster_id, '普通', 'pending');
    $post = [
        'mode' => 'review_comments',
        'comment_ids' => [(string)$c1],
        'edit_token' => n3s_getEditToken(),
    ];
    n3s_test_admin_review_post($post);
    n3s_test_admin_review_post($post);
    expect(n3s_test_admin_review_count())->toBe(1);
    // 承認済みをNGとして送っても変更されない
    $post['ng_ids'] = [(string)$c1];
    n3s_test_admin_review_post($post);
    expect(n3s_test_admin_review_status($c1))->toBe('approved');
    expect(db_get1('SELECT COUNT(*) AS n FROM comment_user_blocks', [], 'main')['n'])->toBe(0);
});

test('edit_token が不正な review_comments では何も変わらない', function () {
    $c1 = n3s_test_admin_review_comment($this->poster_id, '普通', 'pending');
    n3s_test_admin_review_post([
        'mode' => 'review_comments',
        'comment_ids' => [(string)$c1],
        'edit_token' => 'invalid',
    ]);
    expect(n3s_test_admin_review_status($c1))->toBe('pending');
    expect(n3s_test_admin_review_count())->toBe(0);
});

test('replace_comment_body は公開中コメントの本文だけを置き換える', function () {
    $approved = n3s_test_admin_review_comment($this->poster_id, '公開中の本文', 'approved');
    $pending = n3s_test_admin_review_comment($this->poster_id, '審査待ちの本文', 'pending');
    db_exec('UPDATE apps SET comment_count = 1 WHERE app_id = 1', [], 'main');

    n3s_test_admin_review_post([
        'mode' => 'replace_comment_body',
        'comment_id' => (string)$approved,
        'edit_token' => n3s_getEditToken(),
    ]);
    $row = db_get1('SELECT body, status FROM comments WHERE comment_id = ?', [$approved], 'main');
    expect($row['body'])->toBe('(すみません削除されました)');
    expect($row['status'])->toBe('approved');
    expect(n3s_test_admin_review_count())->toBe(1);

    n3s_test_admin_review_post([
        'mode' => 'replace_comment_body',
        'comment_id' => (string)$pending,
        'edit_token' => n3s_getEditToken(),
    ]);
    $row = db_get1('SELECT body, status FROM comments WHERE comment_id = ?', [$pending], 'main');
    expect($row['body'])->toBe('審査待ちの本文');
    expect($row['status'])->toBe('pending');
});

function n3s_test_admin_run_audit(string $api_key, bool $auto_approve = false, ?string $token = null): string
{
    global $n3s_config;
    $n3s_config['openrouter_api_key'] = $api_key;
    $n3s_config['comment_audit_auto_approve'] = $auto_approve;
    return n3s_test_admin_review_post([
        'mode' => 'run_comment_audit',
        'edit_token' => $token ?? n3s_getEditToken(),
    ]);
}

test('管理画面に「AI審査を実行」ボタンが表示される', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $html = n3s_test_capture(fn() => n3s_web_admin());
    expect($html)->toContain('AI審査を実行');
    expect($html)->toContain('value="run_comment_audit"');
});

test('run_comment_audit で pending が approved になり comment_count が増える', function () {
    $c = n3s_test_admin_review_comment($this->poster_id, '普通のコメント', 'pending');
    n3s_test_admin_run_audit('test-key');
    expect(n3s_test_admin_review_status($c))->toBe('approved');
    expect(n3s_test_admin_review_count())->toBe(1);
});

test('run_comment_audit でNG判定なら ng になり投稿者の ng_count が1になる', function () {
    $c = n3s_test_admin_review_comment($this->poster_id, '悪いコメント', 'pending');
    n3s_test_admin_run_audit('trigger-ng');
    expect(n3s_test_admin_review_status($c))->toBe('ng');
    $block = db_get1('SELECT ng_count FROM comment_user_blocks WHERE user_id = ?', [$this->poster_id], 'main');
    expect((int)$block['ng_count'])->toBe(1);
});

test('run_comment_audit でAPIエラーなら pending のまま', function () {
    $c = n3s_test_admin_review_comment($this->poster_id, '保留コメント', 'pending');
    n3s_test_admin_run_audit('trigger-error');
    expect(n3s_test_admin_review_status($c))->toBe('pending');
});

test('run_comment_audit は edit_token が不正なら実行されない', function () {
    $c = n3s_test_admin_review_comment($this->poster_id, '未審査コメント', 'pending');
    n3s_test_admin_run_audit('test-key', false, 'invalid-token');
    expect(n3s_test_admin_review_status($c))->toBe('pending');
});

test('run_comment_audit は審査バッチ実行中 (ロック取得中) は実行されない', function () {
    $c = n3s_test_admin_review_comment($this->poster_id, '未審査コメント', 'pending');
    $lock = n3s_cli_batch_lock('comment_audit');
    expect($lock)->not->toBeFalse();
    try {
        $html = n3s_test_admin_run_audit('test-key', false);
        expect(n3s_test_admin_review_status($c))->toBe('pending');
        expect($html)->toContain('既に実行中');
    } finally {
        if (is_resource($lock)) {
            fclose($lock);
        }
    }
});
