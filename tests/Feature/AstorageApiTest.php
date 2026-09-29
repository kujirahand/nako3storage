<?php
// tests/Feature/AstorageApiTest.php
// 貯蔵庫API(アプリ内ストレージ)の権限とデータ分離 (#194, docs/api.md)。
// エラー時の api_error() は exit するため、ここでは正常系と権限判定関数を検証する。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/api.inc.php';

beforeEach(function () {
    $dir = n3s_get_config('dir_data', '') . '/astorage';
    mkdir($dir, 0777, true);
    n3s_set_config('dir_astorage', $dir);
    n3s_set_config('admin_users', [1]);
});

function astorage_test_app(int $owner_id): int
{
    return (int) db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['貯蔵庫APIテスト', 'テスト', $owner_id, 0, 'wnako', time(), time()]
    );
}

function astorage_call(string $page, array $ctx, array $req = []): array
{
    $_REQUEST = $req;
    $out = n3s_test_capture(fn () => call_user_func("n3s_api__{$page}", $ctx));
    return json_decode($out, true);
}

function astorage_ctx(int $app_id, int $user_id): array
{
    return ['app_id' => $app_id, 'user_id' => $user_id, 'expire' => time() + 60];
}

test('呼び出せるAPIは一覧に定義したものだけで、対応する関数がすべて存在する', function () {
    foreach (n3s_astorage_api_pages() as $page) {
        expect(function_exists("n3s_api__{$page}"))->toBeTrue($page);
    }
    expect(in_array('astorage_db', n3s_astorage_api_pages(), true))->toBeFalse();
});

test('is_logined / get_user はトークンの user_id で判定する(セッションは見ない)', function () {
    $_SESSION['n3s_login'] = true;
    $_SESSION['user_id'] = 1;
    $app_id = astorage_test_app(0);

    expect(astorage_call('is_logined', astorage_ctx($app_id, 0))['logined'])->toBeFalse();
    $uid = (int) n3s_add_user('a@example.com', 'password1234', 'ユーザーA');
    $r = astorage_call('get_user', astorage_ctx($app_id, $uid));
    expect($r['result'])->toBeTrue()
        ->and($r['user_id'])->toBe($uid)
        ->and($r['name'])->toBe('ユーザーA');
});

test('user 領域は user_id と app_id ごとに分離される', function () {
    $app1 = astorage_test_app(0);
    $app2 = astorage_test_app(0);

    astorage_call('set_key_as_user', astorage_ctx($app1, 10), ['key' => 'k', 'value' => 'user10-app1']);
    astorage_call('set_key_as_user', astorage_ctx($app2, 10), ['key' => 'k', 'value' => 'user10-app2']);
    astorage_call('set_key_as_user', astorage_ctx($app1, 11), ['key' => 'k', 'value' => 'user11-app1']);

    expect(astorage_call('get_key_as_user', astorage_ctx($app1, 10), ['key' => 'k'])['value'])->toBe('user10-app1');
    expect(astorage_call('get_key_as_user', astorage_ctx($app2, 10), ['key' => 'k'])['value'])->toBe('user10-app2');
    expect(astorage_call('get_key_as_user', astorage_ctx($app1, 11), ['key' => 'k'])['value'])->toBe('user11-app1');
});

test('app 領域は app_id ごとに分離され、ゲストでも読み取れる', function () {
    $app1 = astorage_test_app(0);
    $app2 = astorage_test_app(0);

    astorage_call('insert_item_as_app', astorage_ctx($app1, 10), ['key' => 'bbs', 'value' => 'hello']);

    $r = astorage_call('select_items_as_app', astorage_ctx($app1, 0), ['key' => 'bbs']);
    expect($r['values'])->toHaveCount(1)
        ->and($r['values'][0]['value'])->toBe('hello')
        ->and($r['values'][0]['user_id'])->toBe(10);
    expect(astorage_call('select_items_as_app', astorage_ctx($app2, 0), ['key' => 'bbs'])['values'])->toBe([]);
});

test('app 領域の行は、書き込んだ本人・作品の作者・管理者だけが変更できる', function () {
    $app_id = astorage_test_app(20);

    expect(n3s_astorage_can_modify_app_row(astorage_ctx($app_id, 10), 10))->toBeTrue();  // 本人
    expect(n3s_astorage_can_modify_app_row(astorage_ctx($app_id, 11), 10))->toBeFalse(); // 他人
    expect(n3s_astorage_can_modify_app_row(astorage_ctx($app_id, 20), 10))->toBeTrue();  // 作品の作者
    expect(n3s_astorage_can_modify_app_row(astorage_ctx($app_id, 1), 10))->toBeTrue();   // 管理者
    expect(n3s_astorage_can_modify_app_row(astorage_ctx($app_id, 0), 0))->toBeFalse();   // ゲスト
    // 移行前の行(user_id=0)は作者・管理者のみ
    expect(n3s_astorage_can_modify_app_row(astorage_ctx($app_id, 11), 0))->toBeFalse();
    expect(n3s_astorage_can_modify_app_row(astorage_ctx($app_id, 20), 0))->toBeTrue();
});

test('非ログイン投稿(作者 user_id=0)の作品には作者権限を持つユーザーがいない', function () {
    $app_id = astorage_test_app(0);
    expect(n3s_astorage_is_app_manager(astorage_ctx($app_id, 10)))->toBeFalse();
    expect(n3s_astorage_is_app_manager(astorage_ctx($app_id, 0)))->toBeFalse();
});

test('本人は自分のアイテムを更新・削除できる', function () {
    $app_id = astorage_test_app(0);
    $ctx = astorage_ctx($app_id, 10);
    $item_id = astorage_call('insert_item_as_app', $ctx, ['key' => 'bbs', 'value' => 'v1'])['item_id'];

    expect(astorage_call('update_item_as_app', $ctx, ['key' => 'bbs', 'item_id' => $item_id, 'value' => 'v2'])['result'])->toBeTrue();
    expect(astorage_call('select_items_as_app', $ctx, ['key' => 'bbs'])['values'][0]['value'])->toBe('v2');
    expect(astorage_call('delete_item_as_app', $ctx, ['key' => 'bbs', 'item_id' => $item_id])['result'])->toBeTrue();
    expect(astorage_call('select_items_as_app', $ctx, ['key' => 'bbs'])['values'])->toBe([]);
});

test('作者が上書きしてもキーの作成者は変わらない', function () {
    $app_id = astorage_test_app(20);
    astorage_call('set_key_as_app', astorage_ctx($app_id, 10), ['key' => 'k', 'value' => 'a']);
    astorage_call('set_key_as_app', astorage_ctx($app_id, 20), ['key' => 'k', 'value' => 'b']);

    $row = db_get1('SELECT * FROM keys WHERE app_id=? AND key=?', [$app_id, 'k'], AS_APP);
    expect($row['value'])->toBe('b')
        ->and((int)$row['user_id'])->toBe(10);
});

test('deleteall_key_as_app は app 領域だけを削除し、user 領域は消さない', function () {
    $app_id = astorage_test_app(20);
    $owner = astorage_ctx($app_id, 20);
    astorage_call('set_key_as_user', $owner, ['key' => 'mine', 'value' => 'keep']);
    astorage_call('set_key_as_app', $owner, ['key' => 'shared', 'value' => 'x']);

    expect(astorage_call('deleteall_key_as_app', $owner)['result'])->toBeTrue();
    expect(astorage_call('list_key_as_app', $owner)['keys'])->toBe([]);
    expect(astorage_call('get_key_as_user', $owner, ['key' => 'mine'])['value'])->toBe('keep');
});

test('既存の app DB には user_id 列が自動で追加される', function () {
    $app_id = astorage_test_app(0);
    $path = n3s_get_config('dir_astorage', '') . '/apps/app' . str_pad((string)$app_id, 6, '0', STR_PAD_LEFT) . '.sqlite3';
    mkdir(dirname($path), 0777, true);
    $pdo = new PDO("sqlite:$path");
    $pdo->exec('CREATE TABLE items (item_id INTEGER PRIMARY KEY, app_id INTEGER NOT NULL, key TEXT NOT NULL, value TEXT NOT NULL, ctime INTEGER, mtime INTEGER)');
    $pdo->exec('CREATE TABLE keys (key_id INTEGER PRIMARY KEY, app_id INTEGER NOT NULL, key TEXT NOT NULL, value TEXT NOT NULL, ctime INTEGER, mtime INTEGER)');
    $pdo->exec("INSERT INTO items (app_id, key, value) VALUES ($app_id, 'bbs', 'old')");
    $pdo = null;

    $r = astorage_call('select_items_as_app', astorage_ctx($app_id, 0), ['key' => 'bbs']);
    expect($r['values'][0]['value'])->toBe('old')
        ->and($r['values'][0]['user_id'])->toBe(0);
});

test('select_items の limit は最大30件、負の offset は0として扱う', function () {
    $app_id = astorage_test_app(0);
    $ctx = astorage_ctx($app_id, 10);
    for ($i = 0; $i < 35; $i++) {
        astorage_call('insert_item_as_app', $ctx, ['key' => 'k', 'value' => "v$i"]);
    }
    expect(astorage_call('select_items_as_app', $ctx, ['key' => 'k', 'limit' => '100'])['values'])->toHaveCount(30);
    $r = astorage_call('select_items_as_app', $ctx, ['key' => 'k', 'offset' => '-5', 'limit' => '1', 'sort' => 'desc']);
    expect($r['values'][0]['value'])->toBe('v34');
});
