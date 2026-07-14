<?php
// app_id 再利用時の materials 主キー衝突の回帰テスト。
//
// apps.app_id は AUTOINCREMENT なしの INTEGER PRIMARY KEY のため、最大IDの作品を
// 削除すると次の新規投稿で同じ app_id が再利用される。かつて削除処理は apps の行しか
// 消さなかったため、material DB に残った行と主キー衝突して新規投稿が例外死し、
// author が既定値 '(no name)' のままの壊れた行が残る不具合があった。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/save.inc.php';

/**
 * ユーザーを登録してログイン済みセッションにする。
 */
function n3s_test_login_for_reuse(string $email, string $name): int
{
    $password = 'password123';
    $user_id = (int)n3s_add_user($email, $password, $name);
    expect($user_id)->toBeGreaterThan(0);

    $token = n3s_getEditToken();
    $_POST['email'] = $email;
    $_POST['password'] = $password;
    $_REQUEST['edit_token'] = $token;
    n3s_test_capture(fn () => n3s_web_login_trylogin());
    expect(n3s_is_login())->toBeTrue();
    return $user_id;
}

/**
 * ログイン済みユーザーとして新規投稿し、保存された apps の行を返す。
 */
function n3s_test_save_program_for_reuse(int $user_id, string $name, string $title, string $body): array
{
    $_POST = [
        'title' => $title,
        'author' => $name,
        'body' => $body,
        'nakotype' => 'wnako',
        'copyright' => 'MIT',
        'is_private' => 0,
        'agree' => 'checked',
        'version' => '3.3.0',
    ];
    $_REQUEST['edit_token'] = n3s_getEditToken();
    $_GET['mode'] = 'edit';
    $_GET['page'] = '0'; // 新規投稿
    n3s_test_capture(fn () => n3s_web_save());

    $app = db_get1('SELECT * FROM apps WHERE user_id=? ORDER BY app_id DESC LIMIT 1', [$user_id]);
    expect($app)->not->toBeEmpty();
    return $app;
}

test('materials に削除済み作品の残骸があっても、app_id 再利用時の新規投稿が壊れない', function () {
    $name = 'なでしこユーザー';
    $user_id = n3s_test_login_for_reuse('test_reuse@example.com', $name);

    // 1. 作品Aを投稿する
    $app_a = n3s_test_save_program_for_reuse($user_id, $name, '作品A', '「あ」と表示。# 十分な長さのテキストその1');
    $app_id = (int)$app_a['app_id'];

    // 2. 旧実装の削除(appsのみ削除)を再現し、materialsに残骸を残す
    db_exec('DELETE FROM apps WHERE app_id=?', [$app_id]);
    $leftover = n3s_getMaterialData($app_id);
    expect($leftover)->not->toBeEmpty(); // 残骸があることが前提条件

    // 3. 作品Bを投稿する (最大IDが消えたので同じ app_id が再利用される)
    $app_b = n3s_test_save_program_for_reuse($user_id, $name, '作品B', '「い」と表示。# 十分な長さのテキストその2');
    expect((int)$app_b['app_id'])->toBe($app_id); // 再利用の前提を確認

    // 4. 例外死せず author と本文が正しく保存されている (かつては '(no name)' で壊れた)
    expect($app_b['author'])->toBe($name);
    expect($app_b['title'])->toBe('作品B');
    $material = n3s_getMaterialData($app_id);
    expect(trim($material['body']))->toBe('「い」と表示。# 十分な長さのテキストその2');
});

test('作品を削除すると materials の本文行も削除される', function () {
    $name = 'なでしこユーザー';
    $user_id = n3s_test_login_for_reuse('test_delete@example.com', $name);

    $app = n3s_test_save_program_for_reuse($user_id, $name, '削除対象の作品', '「う」と表示。# 十分な長さのテキストその3');
    $app_id = (int)$app['app_id'];
    expect(n3s_getMaterialData($app_id))->not->toBeEmpty();

    // 削除を実行
    $_GET['mode'] = 'delete';
    $_GET['page'] = (string)$app_id;
    $_POST = ['yesno' => 'yes'];
    $_REQUEST['edit_token'] = n3s_getEditToken();
    n3s_test_capture(fn () => n3s_web_save());

    // apps と materials の両方から消えている (db_get1 は行がないとき false を返す)
    expect(db_get1('SELECT * FROM apps WHERE app_id=?', [$app_id]))->toBeFalsy();
    expect(n3s_getMaterialData($app_id))->toBeFalsy();
});
