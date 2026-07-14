<?php
// 作品一覧掲載フラグ show_list のテスト (#202 / docs/show_list.md)。
//
// 従来はタグに w_noname が含まれると一覧非掲載だったが、apps.show_list カラムに
// 一本化した。既存DBは n3s_db_migrate_apps() が自動でカラム追加+バックフィルする。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/save.inc.php';
require_once N3S_TEST_ROOT . '/app/action/list.inc.php';
require_once N3S_TEST_ROOT . '/app/action/search.inc.php';

/**
 * ユーザーを登録してログイン済みセッションにする。
 */
function n3s_test_login_for_showlist(string $email, string $name): int
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
 * 投稿を保存し、保存された apps の行を返す。
 * $post で $_POST を上書き・追加する (show_list キーを含めなければ「未指定」を再現できる)。
 * $page > 0 なら更新 (mode=edit + page)。
 */
function n3s_test_save_app_for_showlist(array $post, int $page = 0): array
{
    global $n3s_config;
    // 本番では n3s_parseURI() が $_GET から $n3s_config['page'] を作る。
    // 保存処理は n3s_get_config('page') を参照するため、テストでは直接セットする。
    $n3s_config['page'] = $page;
    $_POST = array_merge([
        'title' => '一覧掲載テスト作品',
        'author' => 'なでしこユーザー',
        'body' => '「あ」と表示。# 十分な長さのテキスト ' . md5(json_encode([$post, $page])),
        'nakotype' => 'wnako',
        'copyright' => 'MIT',
        'is_private' => 0,
        'agree' => 'checked',
        'version' => '3.3.0',
    ], $post);
    $_REQUEST['edit_token'] = n3s_getEditToken();
    $_GET['mode'] = 'edit';
    $_GET['page'] = (string)$page;
    n3s_test_capture(fn () => n3s_web_save());

    if ($page > 0) {
        $app = db_get1('SELECT * FROM apps WHERE app_id=?', [$page]);
    } else {
        $app = db_get1('SELECT * FROM apps ORDER BY app_id DESC LIMIT 1', []);
    }
    expect($app)->not->toBeEmpty();
    return $app;
}

// --------------------------------------------------------
// 自動マイグレーション
// --------------------------------------------------------

test('n3s_db_migrate_apps は show_list カラムを追加し w_noname をバックフィルする', function () {
    // 旧スキーマ (show_list なし) を再現する
    db_exec('DROP TABLE apps', []);
    db_exec("CREATE TABLE apps (app_id INTEGER PRIMARY KEY, title TEXT DEFAULT '', tag TEXT DEFAULT '')", []);
    db_exec(
        "INSERT INTO apps (title, tag) VALUES " .
        "('a','w_noname'),('b','w_noname,game'),('c','game'),('d','')",
        []
    );

    n3s_db_migrate_apps();

    // w_noname を含む作品のみ 0、それ以外は 1
    $rows = [];
    foreach (db_get('SELECT title, show_list FROM apps', []) as $row) {
        $rows[$row['title']] = (int)$row['show_list'];
    }
    expect($rows)->toBe(['a' => 0, 'b' => 0, 'c' => 1, 'd' => 1]);
});

test('n3s_db_migrate_apps は冪等でありバックフィルを再実行しない', function () {
    db_exec('DROP TABLE apps', []);
    db_exec("CREATE TABLE apps (app_id INTEGER PRIMARY KEY, title TEXT DEFAULT '', tag TEXT DEFAULT '')", []);
    db_exec("INSERT INTO apps (title, tag) VALUES ('a','w_noname')", []);
    n3s_db_migrate_apps();

    // 手動で掲載に戻した作品が、再実行しても巻き戻らないこと
    db_exec("UPDATE apps SET show_list=1 WHERE title='a'", []);
    n3s_db_migrate_apps();
    $row = db_get1("SELECT show_list FROM apps WHERE title='a'", []);
    expect((int)$row['show_list'])->toBe(1);
});

test('新規DB (init-main.sql) では show_list=1 がデフォルトで、マイグレーションは何もしない', function () {
    n3s_db_migrate_apps(); // カラムが既にあるため早期リターン (例外にならないこと)
    db_exec("INSERT INTO apps (title) VALUES ('x')", []);
    $row = db_get1("SELECT show_list FROM apps WHERE title='x'", []);
    expect((int)$row['show_list'])->toBe(1);
});

// --------------------------------------------------------
// 保存時の show_list 決定ロジック
// --------------------------------------------------------

test('新規投稿の show_list はフォーム値に従い、未指定なら掲載になる', function () {
    n3s_test_login_for_showlist('showlist_save@example.com', 'なでしこユーザー');

    $app = n3s_test_save_app_for_showlist(['title' => '掲載する作品', 'show_list' => '1']);
    expect((int)$app['show_list'])->toBe(1);

    $app = n3s_test_save_app_for_showlist(['title' => '掲載しない作品', 'show_list' => '0']);
    expect((int)$app['show_list'])->toBe(0);

    // 旧クライアント互換: キー自体が無い場合は掲載
    $app = n3s_test_save_app_for_showlist(['title' => '未指定の作品']);
    expect((int)$app['show_list'])->toBe(1);
});

test('タグに w_noname が含まれると show_list 指定より優先して非掲載になる', function () {
    n3s_test_login_for_showlist('showlist_tag@example.com', 'なでしこユーザー');

    $app = n3s_test_save_app_for_showlist([
        'title' => 'w_nonameの作品',
        'tag' => 'w_noname',
        'show_list' => '1',
    ]);
    expect((int)$app['show_list'])->toBe(0);
});

test('更新時に show_list 未指定なら既存値を維持し、明示指定で変更できる', function () {
    n3s_test_login_for_showlist('showlist_update@example.com', 'なでしこユーザー');

    $app = n3s_test_save_app_for_showlist(['title' => '非掲載で作る作品', 'show_list' => '0']);
    $app_id = (int)$app['app_id'];
    expect((int)$app['show_list'])->toBe(0);

    // 旧クライアント互換: show_list キー無しの更新では 0 が維持される
    $app = n3s_test_save_app_for_showlist(['title' => '非掲載で作る作品'], $app_id);
    expect((int)$app['show_list'])->toBe(0);

    // 明示的に 1 を送れば掲載に戻せる
    $app = n3s_test_save_app_for_showlist(['title' => '非掲載で作る作品', 'show_list' => '1'], $app_id);
    expect((int)$app['show_list'])->toBe(1);
});

test('非ログイン投稿でも show_list を指定でき、editkey で変更できる', function () {
    $app = n3s_test_save_app_for_showlist([
        'title' => 'ゲスト作品',
        'author' => 'ゲスト作者',
        'editkey' => 'guest-key-123',
        'show_list' => '0',
    ]);
    $app_id = (int)$app['app_id'];
    expect((int)$app['user_id'])->toBe(0);
    expect((int)$app['show_list'])->toBe(0);

    $app = n3s_test_save_app_for_showlist([
        'title' => 'ゲスト作品',
        'author' => 'ゲスト作者',
        'editkey' => 'guest-key-123',
        'show_list' => '1',
    ], $app_id);
    expect((int)$app['show_list'])->toBe(1);
});

// --------------------------------------------------------
// 一覧・ランキング・検索からの除外
// --------------------------------------------------------

/**
 * 一覧テスト用に、掲載作品Aと非掲載作品Bを投稿して [A行, B行] を返す。
 */
function n3s_test_make_pair_for_showlist(): array
{
    $a = n3s_test_save_app_for_showlist(['title' => '掲載検索サンプルA', 'show_list' => '1']);
    $b = n3s_test_save_app_for_showlist(['title' => '掲載検索サンプルB', 'show_list' => '0']);
    return [$a, $b];
}

/** 一覧の結果 (行の配列) に app_id が含まれるか */
function n3s_test_list_has_app(array $list, int $app_id): bool
{
    foreach ($list as $row) {
        if ((int)$row['app_id'] === $app_id) {
            return true;
        }
    }
    return false;
}

test('show_list=0 の作品は一覧・トップページランキングに表示されない', function () {
    n3s_test_login_for_showlist('showlist_list@example.com', 'なでしこユーザー');
    [$a, $b] = n3s_test_make_pair_for_showlist();

    // ランキングにも載るように fav を仕込む
    db_exec('UPDATE apps SET fav=5 WHERE app_id IN (?,?)', [$a['app_id'], $b['app_id']]);

    // 通常一覧 (トップページ: ranking_all も同時に検証)
    $_GET = [];
    $r = n3s_test_call(fn () => n3s_list_get());
    expect(n3s_test_list_has_app($r['list'], (int)$a['app_id']))->toBeTrue();
    expect(n3s_test_list_has_app($r['list'], (int)$b['app_id']))->toBeFalse();
    expect(n3s_test_list_has_app($r['ranking_all'], (int)$a['app_id']))->toBeTrue();
    expect(n3s_test_list_has_app($r['ranking_all'], (int)$b['app_id']))->toBeFalse();

    // ランキングモード
    $_GET = ['mode' => 'ranking'];
    $r = n3s_test_call(fn () => n3s_list_get());
    expect(n3s_test_list_has_app($r['list'], (int)$a['app_id']))->toBeTrue();
    expect(n3s_test_list_has_app($r['list'], (int)$b['app_id']))->toBeFalse();
});

test('show_list=0 の作品は検索結果に表示されない', function () {
    n3s_test_login_for_showlist('showlist_search@example.com', 'なでしこユーザー');
    [$a, $b] = n3s_test_make_pair_for_showlist();

    // タイトル検索 (target=normal)
    $_GET = ['search_word' => '掲載検索サンプル', 'target' => 'normal'];
    $r = n3s_test_call(fn () => n3s_list_search());
    expect(n3s_test_list_has_app($r['list'], (int)$a['app_id']))->toBeTrue();
    expect(n3s_test_list_has_app($r['list'], (int)$b['app_id']))->toBeFalse();

    // 作者検索 (target=author)
    $_GET = ['search_word' => 'なでしこユーザー', 'target' => 'author'];
    $r = n3s_test_call(fn () => n3s_list_search());
    expect(n3s_test_list_has_app($r['list'], (int)$a['app_id']))->toBeTrue();
    expect(n3s_test_list_has_app($r['list'], (int)$b['app_id']))->toBeFalse();
});
