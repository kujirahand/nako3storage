<?php
// tests/Feature/SqlParameterizationRegressionTest.php
// todo-security.md #9:
// save.inc.php / bad.inc.php / list.inc.php にあった SQL 変数直接埋め込みを
// プレースホルダ化した (db_get1/db_exec、および list.inc.php では $wheres の "?" と
// 出現順に対応する $where_params 配列を導入)。実際には intval() 済みの値のみだったため
// 注入自体は不可能だったが、書き方として脆く、パラメータの並び順を間違えると
// (特に list.inc.php の LIMIT/OFFSET との合成) 動作が壊れる。
// リファクタ後も機能が同じであることを確認する回帰テスト。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/save.inc.php';
require_once N3S_TEST_ROOT . '/app/action/list.inc.php';
require_once N3S_TEST_ROOT . '/app/action/bad.inc.php';

function n3s_test_insert_app(array $overrides = [])
{
    $defaults = [
        'title' => 'テスト作品', 'author' => 'テスト', 'user_id' => 0,
        'is_private' => 0, 'tag' => '', 'bad' => 0, 'fav_lastip' => '',
        'ctime' => time(), 'mtime' => time(),
    ];
    $a = array_merge($defaults, $overrides);
    return db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, tag, bad, fav_lastip, ctime, mtime) ' .
        'VALUES (?,?,?,?,?,?,?,?,?)',
        [$a['title'], $a['author'], $a['user_id'], $a['is_private'], $a['tag'], $a['bad'], $a['fav_lastip'], $a['ctime'], $a['mtime']]
    );
}

test('n3s_action_save_delete は指定app_idのみ削除し、他の作品は残る (プレースホルダ化の回帰確認)', function () {
    $admin_id = n3s_add_user('admin_del@example.com', 'password', '管理者');
    expect($admin_id)->toEqual(1);
    n3s_web_login_execute('admin_del@example.com', 'password');

    $target_id = n3s_test_insert_app(['title' => '削除対象']);
    $other_id = n3s_test_insert_app(['title' => '残る作品']);

    $token = n3s_getEditToken();
    $_GET['page'] = (string)$target_id;
    $_POST['yesno'] = 'yes';
    $_REQUEST['edit_token'] = $token;

    n3s_test_capture(fn () => n3s_action_save_delete([]));

    expect(db_get1('SELECT * FROM apps WHERE app_id=?', [$target_id]))->toBeFalsy();
    expect(db_get1('SELECT * FROM apps WHERE app_id=?', [$other_id]))->not->toBeFalsy();
});

test('n3s_action_save_reset_bad は指定app_idのbad値のみ更新する (プレースホルダの並び順の回帰確認)', function () {
    $admin_id = n3s_add_user('admin_reset@example.com', 'password', '管理者');
    expect($admin_id)->toEqual(1);
    n3s_web_login_execute('admin_reset@example.com', 'password');

    $target_id = n3s_test_insert_app(['title' => '通報対象', 'bad' => 5]);
    $other_id = n3s_test_insert_app(['title' => '別の作品', 'bad' => 5]);

    $token = n3s_getEditToken();
    $_GET['page'] = (string)$target_id;
    $_POST['bad_value'] = '0';
    $_REQUEST['edit_token'] = $token;

    n3s_test_capture(fn () => n3s_action_save_reset_bad([]));

    $target = db_get1('SELECT * FROM apps WHERE app_id=?', [$target_id]);
    $other = db_get1('SELECT * FROM apps WHERE app_id=?', [$other_id]);
    expect((int)$target['bad'])->toBe(0);
    expect((int)$other['bad'])->toBe(5); // 他の作品は変更されない
});

test('n3s_list_get の user_id 絞り込みは、指定ユーザーの作品のみを返す (where_params合成の回帰確認)', function () {
    $user_a = n3s_add_user('list_user_a@example.com', 'password', 'ユーザーA');
    $user_b = n3s_add_user('list_user_b@example.com', 'password', 'ユーザーB');
    n3s_test_insert_app(['title' => 'Aの作品1', 'user_id' => $user_a]);
    n3s_test_insert_app(['title' => 'Aの作品2', 'user_id' => $user_a]);
    n3s_test_insert_app(['title' => 'Bの作品', 'user_id' => $user_b]);

    $_GET['user_id'] = (string)$user_a;
    $_GET['mode'] = 'list';
    $r = n3s_list_get();

    expect($r['find_user_id'])->toEqual($user_a); // n3s_list_get側はintval済み、db_insertは文字列IDのため緩い比較
    $titles = array_map(fn ($row) => $row['title'], $r['list']);
    expect($titles)->toContain('Aの作品1', 'Aの作品2');
    expect($titles)->not->toContain('Bの作品');
});

test('echo_bad の通報アップは指定app_idのbadのみ加算する (トランザクション内プレースホルダの回帰確認)', function () {
    // 最初に別ユーザーを登録してuser_id=1(=既定admin_users)を埋め、
    // 通報者が管理者(100件分カウント)にならないようにする
    n3s_add_user('dummy_first@example.com', 'password', 'ダミー');
    n3s_add_user('reporter@example.com', 'password', '通報者');
    n3s_web_login_execute('reporter@example.com', 'password');

    $target_id = n3s_test_insert_app(['title' => '通報される作品', 'bad' => 0]);
    $other_id = n3s_test_insert_app(['title' => '別の作品', 'bad' => 0]);

    $_GET['page'] = (string)$target_id;
    $_GET['q'] = 'up';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.55';

    $out = n3s_test_capture(fn () => echo_bad());

    expect(trim($out))->toBe('1');
    $target = db_get1('SELECT bad FROM apps WHERE app_id=?', [$target_id]);
    $other = db_get1('SELECT bad FROM apps WHERE app_id=?', [$other_id]);
    expect((int)$target['bad'])->toBe(1);
    expect((int)$other['bad'])->toBe(0);
});
