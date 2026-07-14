<?php
// ========================================================
// nako3storage comment.inc.php
// ========================================================

function n3s_web_comment()
{
    n3s_error('コメント機能はWeb経由の直接呼び出しをサポートしていません。APIを利用してください。');
}

function n3s_api_comment()
{
    $mode = empty($_GET['mode']) ? 'list' : $_GET['mode'];
    
    if ($mode == 'list') {
        comment_api_list();
        return;
    }
    
    // 投稿やいいねはログイン必須
    if (!n3s_is_login()) {
        n3s_api_output(false, ['msg' => 'ログインが必要です。']);
        return;
    }
    
    if ($mode == 'add') {
        comment_api_add();
        return;
    }
    if ($mode == 'fav') {
        comment_api_fav();
        return;
    }
    if ($mode == 'delete') {
        comment_api_delete();
        return;
    }
    
    n3s_api_output(false, ['msg' => '無効なモードです。']);
}

function comment_api_list()
{
    $app_id = intval(empty($_GET['app_id']) ? '0' : $_GET['app_id']);
    if ($app_id <= 0) {
        n3s_api_output(false, ['msg' => 'app_idが不正です。']);
        return;
    }
    
    // 作品の存在確認と閲覧制限チェック
    $app = db_get1("SELECT * FROM apps WHERE app_id = ?", [$app_id], 'main');
    if (!$app) {
        n3s_api_output(false, ['msg' => '作品が見つかりません。']);
        return;
    }
    $editkey = empty($_GET['editkey']) ? '' : $_GET['editkey'];
    if (!n3s_private_access_allowed($app, $editkey)) {
        n3s_api_output(false, ['msg' => 'この作品の閲覧権限がありません。']);
        return;
    }
    
    // 1. 親コメント (parent_id = 0, status IN ('approved', 'pending', 'ng')) を、スレッド全体のいいね合計でソートして取得
    $parents = db_get(
        "SELECT c.*, 
                (c.fav + IFNULL((SELECT SUM(child.fav) FROM comments child WHERE child.parent_id = c.comment_id AND child.status IN ('approved', 'pending', 'ng')), 0)) as thread_fav
         FROM comments c
         WHERE c.app_id = ? AND c.parent_id = 0 AND c.status IN ('approved', 'pending', 'ng')
         ORDER BY thread_fav DESC, c.ctime DESC",
        [$app_id],
        'main'
    );
    
    if (empty($parents)) {
        n3s_api_output(true, ['comments' => []]);
        return;
    }
    
    // 親コメントのID一覧を収集
    $parent_ids = [];
    foreach ($parents as $p) {
        $parent_ids[] = intval($p['comment_id']);
    }
    
    // 2. 子コメントを一括取得 (IN句)
    $in_clause_parents = implode(',', $parent_ids);
    $children = db_get(
        "SELECT * FROM comments 
         WHERE parent_id IN ($in_clause_parents) AND status IN ('approved', 'pending', 'ng')
         ORDER BY ctime ASC",
        [],
        'main'
    );
    
    // 子コメントを親IDごとにグループ化
    $children_by_parent = [];
    foreach ($children as $c) {
        $pid = intval($c['parent_id']);
        if (!isset($children_by_parent[$pid])) {
            $children_by_parent[$pid] = [];
        }
        $children_by_parent[$pid][] = $c;
    }
    
    // 親・子すべてのコメントIDを収集
    $all_comment_ids = [];
    foreach ($parents as $p) {
        $all_comment_ids[] = intval($p['comment_id']);
    }
    foreach ($children as $c) {
        $all_comment_ids[] = intval($c['comment_id']);
    }
    
    // 3. ログイン中なら、自分がいいねしたコメントIDを一括取得 (IN句)
    $liked_comment_ids = [];
    $user_id = n3s_get_user_id();
    if ($user_id > 0 && !empty($all_comment_ids)) {
        $in_clause_all = implode(',', $all_comment_ids);
        $likes = db_get(
            "SELECT comment_id FROM comment_likes WHERE user_id = ? AND comment_id IN ($in_clause_all)",
            [$user_id],
            'main'
        );
        foreach ($likes as $l) {
            $liked_comment_ids[intval($l['comment_id'])] = true;
        }
    }
    
    $results = [];
    $is_admin = n3s_is_admin();
    
    foreach ($parents as $p) {
        $comment_id = intval($p['comment_id']);
        $liked = isset($liked_comment_ids[$comment_id]);
        $is_pending = ($p['status'] === 'pending');
        $is_ng = ($p['status'] === 'ng');
        $can_delete = ($user_id > 0 && (intval($p['user_id']) === $user_id || $is_admin));
        
        $p_body = $p['body'];
        if ($is_pending) {
            $p_body = '(現在内容を審査中…)';
        } else if ($is_ng) {
            $p_body = '(AIによりコメントが却下されました)';
        }
        
        $p_data = [
            'comment_id' => $comment_id,
            'user_id' => intval($p['user_id']),
            'name' => $p['name'],
            'body' => $p_body,
            'ctime' => intval($p['ctime']),
            'fav' => intval($p['fav']),
            'status' => $p['status'],
            'liked' => $liked,
            'can_delete' => $can_delete,
            'replies' => []
        ];
        
        $my_children = isset($children_by_parent[$comment_id]) ? $children_by_parent[$comment_id] : [];
        foreach ($my_children as $c) {
            $c_comment_id = intval($c['comment_id']);
            $c_liked = isset($liked_comment_ids[$c_comment_id]);
            $is_c_pending = ($c['status'] === 'pending');
            $is_c_ng = ($c['status'] === 'ng');
            $can_c_delete = ($user_id > 0 && (intval($c['user_id']) === $user_id || $is_admin));
            
            $c_body = $c['body'];
            if ($is_c_pending) {
                $c_body = '(現在内容を審査中…)';
            } else if ($is_c_ng) {
                $c_body = '(AIによりコメントが却下されました)';
            }
            
            $p_data['replies'][] = [
                'comment_id' => $c_comment_id,
                'user_id' => intval($c['user_id']),
                'name' => $c['name'],
                'body' => $c_body,
                'ctime' => intval($c['ctime']),
                'fav' => intval($c['fav']),
                'status' => $c['status'],
                'liked' => $c_liked,
                'can_delete' => $can_c_delete
            ];
        }
        
        $results[] = $p_data;
    }
    
    n3s_api_output(true, ['comments' => $results]);
}

function comment_api_add()
{
    // CSRF対策
    if (!n3s_checkEditToken()) {
        n3s_api_output(false, ['msg' => 'トークンが一致しません。リロードして再度お試しください。']);
        return;
    }
    
    $app_id = intval(empty($_POST['app_id']) ? '0' : $_POST['app_id']);
    $parent_id = intval(empty($_POST['parent_id']) ? '0' : $_POST['parent_id']);
    $body = empty($_POST['body']) ? '' : trim($_POST['body']);
    
    if ($app_id <= 0) {
        n3s_api_output(false, ['msg' => 'app_idが不正です。']);
        return;
    }

    $app = db_get1("SELECT * FROM apps WHERE app_id = ?", [$app_id], 'main');
    if (!$app) {
        n3s_api_output(false, ['msg' => '作品が見当たりません。']);
        return;
    }
    $editkey = isset($_REQUEST['editkey']) ? (string)$_REQUEST['editkey'] : '';
    if (!n3s_private_access_allowed($app, $editkey)) {
        n3s_api_output(false, ['msg' => 'この作品にコメントできません。']);
        return;
    }
    
    // 作品の存在確認と閲覧制限チェック
    $app = db_get1("SELECT * FROM apps WHERE app_id = ?", [$app_id], 'main');
    if (!$app) {
        n3s_api_output(false, ['msg' => '作品が見つかりません。']);
        return;
    }
    $editkey = empty($_POST['editkey']) ? '' : $_POST['editkey'];
    if (!n3s_private_access_allowed($app, $editkey)) {
        n3s_api_output(false, ['msg' => 'この作品の閲覧権限がありません。']);
        return;
    }
    
    if ($body === '') {
        n3s_api_output(false, ['msg' => 'コメント内容が空です。']);
        return;
    }
    
    if (mb_strlen($body) > 1000) {
        n3s_api_output(false, ['msg' => 'コメントは1000文字以内で入力してください。']);
        return;
    }
    
    // (1) NGワード機能によるフィルタ
    $ng_words = n3s_get_config('ng_words', []);
    foreach ($ng_words as $ng) {
        if (strpos($body, $ng) !== false) {
            n3s_api_output(false, ['msg' => "申し訳ありません。不適切な言葉（NGワード）が含まれているため、投稿できません。"]);
            return;
        }
    }
    
    // parent_id の検証
    if ($parent_id > 0) {
        $parent = db_get1(
            "SELECT * FROM comments WHERE comment_id = ? AND app_id = ? AND status = 'approved'",
            [$parent_id, $app_id],
            'main'
        );
        if (!$parent) {
            n3s_api_output(false, ['msg' => '返信先のコメントが見つかりません。']);
            return;
        }
        if (intval($parent['parent_id']) !== 0) {
            n3s_api_output(false, ['msg' => '返信に対する返信はできません。']);
            return;
        }
    }
    
    $user_id = n3s_get_user_id();
    $login_info = n3s_get_login_info();
    $name = isset($login_info['name']) ? $login_info['name'] : '名無し';
    
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $now = time();
    
    db_begin();
    try {
        db_exec(
            "INSERT INTO comments (user_id, app_id, parent_id, name, body, ip, status, fav, ctime, mtime)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, ?, ?)",
            [$user_id, $app_id, $parent_id, $name, $body, $ip, $now, $now],
            'main'
        );
        db_commit();
    } catch (Exception $e) {
        db_rollback();
        n3s_api_output(false, ['msg' => 'データベースへの書き込み中にエラーが発生しました。']);
        return;
    }
    
    n3s_api_output(true, ['msg' => 'コメントを送信しましたが、不適切な内容が含まれていないかAIが審査した後に公開されます。しばらくお待ちください。']);
}

function comment_api_fav()
{
    // CSRF対策
    if (!n3s_checkEditToken()) {
        n3s_api_output(false, ['msg' => 'トークンが一致しません。リロードして再度お試しください。']);
        return;
    }

    $comment_id = intval(empty($_POST['comment_id']) ? '0' : $_POST['comment_id']);
    if ($comment_id <= 0) {
        n3s_api_output(false, ['msg' => 'comment_idが不正です。']);
        return;
    }
    
    $comment = db_get1(
        "SELECT * FROM comments WHERE comment_id = ? AND status = 'approved'",
        [$comment_id],
        'main'
    );
    if (!$comment) {
        n3s_api_output(false, ['msg' => '対象のコメントが見つかりません。']);
        return;
    }

    $app = db_get1("SELECT * FROM apps WHERE app_id = ?", [$comment['app_id']], 'main');
    if (!$app) {
        n3s_api_output(false, ['msg' => '作品が見当たりません。']);
        return;
    }
    $editkey = isset($_REQUEST['editkey']) ? (string)$_REQUEST['editkey'] : '';
    if (!n3s_private_access_allowed($app, $editkey)) {
        n3s_api_output(false, ['msg' => 'この作品のコメントを操作できません。']);
        return;
    }
    
    // 作品の存在確認と閲覧制限チェック
    $app = db_get1("SELECT * FROM apps WHERE app_id = ?", [$comment['app_id']], 'main');
    if (!$app) {
        n3s_api_output(false, ['msg' => '作品が見つかりません。']);
        return;
    }
    $editkey = empty($_POST['editkey']) ? '' : $_POST['editkey'];
    if (!n3s_private_access_allowed($app, $editkey)) {
        n3s_api_output(false, ['msg' => 'この作品の閲覧権限がありません。']);
        return;
    }
    
    $user_id = n3s_get_user_id();
    
    $like = db_get1(
        "SELECT * FROM comment_likes WHERE user_id = ? AND comment_id = ?",
        [$user_id, $comment_id],
        'main'
    );
    
    db_begin();
    try {
        if (isset($like['comment_like_id'])) {
            db_exec(
                "DELETE FROM comment_likes WHERE comment_like_id = ?",
                [$like['comment_like_id']],
                'main'
            );
            db_exec(
                "UPDATE comments SET fav = CASE WHEN fav > 0 THEN fav - 1 ELSE 0 END WHERE comment_id = ?",
                [$comment_id],
                'main'
            );
            $action = 'removed';
            $fav_diff = -1;
        } else {
            db_exec(
                "INSERT INTO comment_likes (user_id, comment_id, ctime) VALUES (?, ?, ?)",
                [$user_id, $comment_id, time()],
                'main'
            );
            db_exec(
                "UPDATE comments SET fav = fav + 1 WHERE comment_id = ?",
                [$comment_id],
                'main'
            );
            $action = 'added';
            $fav_diff = 1;
        }
        db_commit();
    } catch (Exception $e) {
        db_rollback();
        n3s_api_output(false, ['msg' => 'いいねの更新に失敗しました。']);
        return;
    }
    
    $fav_row = db_get1("SELECT fav FROM comments WHERE comment_id = ?", [$comment_id], 'main');
    $new_fav = $fav_row ? intval($fav_row['fav']) : 0;
    n3s_api_output(true, ['action' => $action, 'fav' => $new_fav]);
}

function comment_api_delete()
{
    // CSRF対策
    if (!n3s_checkEditToken()) {
        n3s_api_output(false, ['msg' => 'トークンが一致しません。リロードして再度お試しください。']);
        return;
    }

    $comment_id = intval(empty($_POST['comment_id']) ? '0' : $_POST['comment_id']);
    if ($comment_id <= 0) {
        n3s_api_output(false, ['msg' => 'comment_idが不正です。']);
        return;
    }
    
    // 対象コメントの取得
    $comment = db_get1(
        "SELECT * FROM comments WHERE comment_id = ?",
        [$comment_id],
        'main'
    );

    if (!$comment) {
        n3s_api_output(false, ['msg' => '対象のコメントが見つかりません。']);
        return;
    }

    $app = db_get1("SELECT * FROM apps WHERE app_id = ?", [$comment['app_id']], 'main');
    if (!$app) {
        n3s_api_output(false, ['msg' => '作品が見当たりません。']);
        return;
    }
    $editkey = isset($_REQUEST['editkey']) ? (string)$_REQUEST['editkey'] : '';
    if (!n3s_private_access_allowed($app, $editkey)) {
        n3s_api_output(false, ['msg' => 'この作品のコメントを操作できません。']);
        return;
    }
    
    // 作品の存在確認と閲覧制限チェック
    $app = db_get1("SELECT * FROM apps WHERE app_id = ?", [$comment['app_id']], 'main');
    if (!$app) {
        n3s_api_output(false, ['msg' => '作品が見つかりません。']);
        return;
    }
    $editkey = empty($_POST['editkey']) ? '' : $_POST['editkey'];
    if (!n3s_private_access_allowed($app, $editkey)) {
        n3s_api_output(false, ['msg' => 'この作品の閲覧権限がありません。']);
        return;
    }
    
    // 権限チェック
    $user_id = n3s_get_user_id();
    $is_admin = n3s_is_admin();
    
    if (intval($comment['user_id']) !== $user_id && !$is_admin) {
        n3s_api_output(false, ['msg' => '他人のコメントは削除できません。']);
        return;
    }
    
    db_begin();
    try {
        // 1. コメント本体の削除
        db_exec(
            "DELETE FROM comments WHERE comment_id = ?",
            [$comment_id],
            'main'
        );
        // 2. いいね情報の削除
        db_exec(
            "DELETE FROM comment_likes WHERE comment_id = ?",
            [$comment_id],
            'main'
        );
        
        // 3. 親コメントだった場合、子コメントとそのいいね情報も削除
        if (intval($comment['parent_id']) === 0) {
            $children = db_get(
                "SELECT comment_id FROM comments WHERE parent_id = ?",
                [$comment_id],
                'main'
            );
            if ($children) {
                foreach ($children as $c) {
                    db_exec(
                        "DELETE FROM comment_likes WHERE comment_id = ?",
                        [$c['comment_id']],
                        'main'
                    );
                }
            }
            db_exec(
                "DELETE FROM comments WHERE parent_id = ?",
                [$comment_id],
                'main'
            );
        }
        
        db_commit();
    } catch (Exception $e) {
        db_rollback();
        n3s_api_output(false, ['msg' => 'コメントの削除に失敗しました。']);
        return;
    }
    
    n3s_api_output(true, ['msg' => 'コメントを削除しました。']);
}
