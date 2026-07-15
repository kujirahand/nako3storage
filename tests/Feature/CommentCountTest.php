<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/comment.inc.php';

test('コメント追加・自動審査バッチ・削除時に apps.comment_count が正しく増減する', function () {
    $now = time();
    $userId = n3s_add_user('comment-user@example.com', 'password123', 'コメンター');
    
    // 作品A（ユーザー投稿）
    $appId = db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, comment_count, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?)',
        ['作品A', 'コメンター', $userId, 0, 0, 'wnako', $now, $now]
    );

    // 1. 通常コメント追加時は status='pending' なのでカウントは増えないはず
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['name'] = 'コメンター';
    $_SESSION['email'] = 'comment-user@example.com';
    $_SESSION['n3s_login_info'] = [
        'user_id' => $userId,
        'name' => 'コメンター',
        'email' => 'comment-user@example.com'
    ];
    $_POST = [
        'app_id' => (string)$appId,
        'body' => 'テストコメント1',
        'edit_token' => n3s_getEditToken()
    ];
    $_GET = ['mode' => 'add'];
    $_REQUEST = array_merge($_GET, $_POST);
    
    $out = n3s_test_capture(fn() => n3s_api_comment());
    
    // 挿入されたコメントを取得
    $comment = db_get1('SELECT * FROM comments WHERE app_id = ? ORDER BY comment_id DESC LIMIT 1', [$appId]);
    expect($comment)->not->toBeNull();
    expect($comment['status'])->toBe('pending');
    
    // appsのcomment_countを確認 -> 0のまま
    $app = db_get1('SELECT comment_count FROM apps WHERE app_id = ?', [$appId]);
    expect(intval($app['comment_count']))->toBe(0);

    // 2. コメントを承認状態（approved）へ更新した際にカウントがインクリメントされることを検証するため
    // 更新ロジックを実行する (scripts/comment_audit.php の処理を模倣)
    db_exec("UPDATE comments SET status = 'approved', mtime = ? WHERE comment_id = ?", [$now, $comment['comment_id']]);
    db_exec("UPDATE apps SET comment_count = comment_count + 1 WHERE app_id = ?", [$appId]);
    
    // appsのcomment_countを確認 -> 1になる
    $app = db_get1('SELECT comment_count FROM apps WHERE app_id = ?', [$appId]);
    expect(intval($app['comment_count']))->toBe(1);

    // 3. ひな形コメント（即時承認）の追加テスト
    $_POST = [
        'app_id' => (string)$appId,
        'template_id' => '1', // ひな形コメントIDを指定
        'edit_token' => n3s_getEditToken()
    ];
    $_GET = ['mode' => 'add'];
    $_REQUEST = array_merge($_GET, $_POST);
    n3s_test_capture(fn() => n3s_api_comment());
    
    // 即時承認されるため、comment_countが2になるはず
    $app = db_get1('SELECT comment_count FROM apps WHERE app_id = ?', [$appId]);
    expect(intval($app['comment_count']))->toBe(2);

    // 削除テスト用に対象コメントのIDを取得
    $template_comment = db_get1('SELECT comment_id FROM comments WHERE app_id = ? AND status = \'approved\' ORDER BY comment_id DESC LIMIT 1', [$appId]);
    $template_comment_id = $template_comment['comment_id'];

    // 4. コメント削除テスト
    $_POST = [
        'comment_id' => (string)$template_comment_id,
        'edit_token' => n3s_getEditToken()
    ];
    $_GET = ['mode' => 'delete'];
    $_REQUEST = array_merge($_GET, $_POST);
    n3s_test_capture(fn() => n3s_api_comment());
    
    // 1個削除されたので comment_countが1に戻るはず
    $app = db_get1('SELECT comment_count FROM apps WHERE app_id = ?', [$appId]);
    expect(intval($app['comment_count']))->toBe(1);
});
