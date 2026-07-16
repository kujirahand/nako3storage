<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/user.inc.php';

test('公開ユーザーページで指定ユーザーの公開作品のみが取得されることを確認', function () {
    $now = time();

    // ユーザーAの作成
    $userIdA = n3s_add_user('userA_test@example.com', 'password123', 'ユーザーA');
    // ユーザーBの作成
    $userIdB = n3s_add_user('userB_test@example.com', 'password123', 'ユーザーB');

    // ユーザーAの公開作品
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, show_list, bad, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        ['作品A-1', 'ユーザーA', $userIdA, 0, 1, 0, 1, 10, 'wnako', $now, $now]
    );

    // ユーザーAの非公開作品
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, show_list, bad, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        ['作品A-非公開', 'ユーザーA', $userIdA, 1, 1, 0, 0, 0, 'wnako', $now, $now]
    );

    // ユーザーAの一覧非掲載作品
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, show_list, bad, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        ['作品A-非掲載', 'ユーザーA', $userIdA, 0, 0, 0, 0, 0, 'wnako', $now, $now]
    );

    // ユーザーAの通報された作品
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, show_list, bad, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        ['作品A-通報', 'ユーザーA', $userIdA, 0, 1, 1, 0, 0, 'wnako', $now, $now]
    );

    // ユーザーBの公開作品
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, show_list, bad, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        ['作品B-1', 'ユーザーB', $userIdB, 0, 1, 0, 5, 50, 'wnako', $now, $now]
    );

    // ユーザーAの作品取得
    $_GET = ['user_id' => $userIdA];
    $res = n3s_user_get();

    expect($res)->not->toBeNull();
    expect($res['user']['name'])->toBe('ユーザーA');
    expect($res['total_count'])->toBe(1);
    expect($res['list'][0]['title'])->toBe('作品A-1');

    // 他ユーザーの作品や、非公開・非掲載・通報された作品が含まれていないことを確認
    foreach ($res['list'] as $app) {
        expect($app['user_id'])->toEqual($userIdA);
        expect($app['title'])->not->toBe('作品A-非公開');
        expect($app['title'])->not->toBe('作品A-非掲載');
        expect($app['title'])->not->toBe('作品A-通報');
        expect($app['title'])->not->toBe('作品B-1');
    }
});

test('公開ユーザーページでのソート順の検証', function () {
    $now = time();
    $userId = n3s_add_user('user_sort@example.com', 'password123', 'ソート太郎');

    // 作品1 (mtime: now, view: 10, fav: 5)
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, show_list, bad, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        ['作品1', 'ソート太郎', $userId, 0, 1, 0, 5, 10, 'wnako', $now, $now]
    );
    // 作品2 (mtime: now-100, view: 50, fav: 1)
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, show_list, bad, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        ['作品2', 'ソート太郎', $userId, 0, 1, 0, 1, 50, 'wnako', $now - 100, $now - 100]
    );

    // 最新順 (mtime)
    $_GET = ['user_id' => $userId, 'sort' => 'mtime'];
    $res = n3s_user_get();
    expect($res['list'][0]['title'])->toBe('作品1');
    expect($res['list'][1]['title'])->toBe('作品2');

    // アクセス数順 (view)
    $_GET = ['user_id' => $userId, 'sort' => 'view'];
    $res = n3s_user_get();
    expect($res['list'][0]['title'])->toBe('作品2');
    expect($res['list'][1]['title'])->toBe('作品1');

    // お気に入り順 (fav)
    $_GET = ['user_id' => $userId, 'sort' => 'fav'];
    $res = n3s_user_get();
    expect($res['list'][0]['title'])->toBe('作品1');
    expect($res['list'][1]['title'])->toBe('作品2');

    // 不正なソート値はmtimeにフォールバック
    $_GET = ['user_id' => $userId, 'sort' => 'invalid_sort'];
    $res = n3s_user_get();
    expect($res['sort'])->toBe('mtime');
    expect($res['list'][0]['title'])->toBe('作品1');
});

test('公開ユーザーページでの検索絞り込み (通常とワイルドカード)', function () {
    $now = time();
    $userId = n3s_add_user('user_search@example.com', 'password123', '検索二郎');

    db_insert(
        'INSERT INTO apps (title, author, user_id, memo, tag, is_private, show_list, bad, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        ['なでしこゲーム', '検索二郎', $userId, '面白いゲームです', 'ゲーム', 0, 1, 0, 0, 0, 'wnako', $now, $now]
    );
    db_insert(
        'INSERT INTO apps (title, author, user_id, memo, tag, is_private, show_list, bad, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        ['なでしこツール', '検索二郎', $userId, '便利なツールです', 'ツール', 0, 1, 0, 0, 0, 'wnako', $now, $now]
    );

    // タイトル検索
    $_GET = ['user_id' => $userId, 'search_word' => 'ゲーム'];
    $res = n3s_user_get();
    expect($res['total_count'])->toBe(1);
    expect($res['list'][0]['title'])->toBe('なでしこゲーム');

    // メモ検索
    $_GET = ['user_id' => $userId, 'search_word' => '便利'];
    $res = n3s_user_get();
    expect($res['total_count'])->toBe(1);
    expect($res['list'][0]['title'])->toBe('なでしこツール');

    // ワイルドカード検索
    $_GET = ['user_id' => $userId, 'search_word' => 'なでしこ*'];
    $res = n3s_user_get();
    expect($res['total_count'])->toBe(2);

    // 1文字のときはエラーが発生し、全件取得される
    $_GET = ['user_id' => $userId, 'search_word' => 'な'];
    $res = n3s_user_get();
    expect($res['search_error'])->toContain('2文字以上');
    expect($res['total_count'])->toBe(2);
});

test('公開ユーザーページでの存在しないユーザーの扱い', function () {
    $_GET = ['user_id' => 999999]; // 存在しないID
    $res = n3s_user_get();
    expect($res)->toBeNull();
});

test('公開ユーザーページでのscreen_name検証とXリンク生成', function () {
    $now = time();
    $userId = n3s_add_user('user_x@example.com', 'password123', 'エックス');
    
    // 正しい screen_name の更新
    db_exec('UPDATE users SET screen_name = ? WHERE user_id = ?', ['valid_name123', $userId], 'users');
    $_GET = ['user_id' => $userId];
    $res = n3s_user_get();
    expect($res['x_url'])->toBe('https://x.com/valid_name123');

    // 不正な形式の screen_name の更新
    db_exec('UPDATE users SET screen_name = ? WHERE user_id = ?', ['invalid-name!', $userId], 'users');
    $res = n3s_user_get();
    expect($res['x_url'])->toBe('');
});

test('公開ユーザーページでのページネーションURL構築', function () {
    $now = time();
    $userId = n3s_add_user('user_page@example.com', 'password123', 'ページング');

    // 25件の作品を挿入 (1ページあたり24件)
    for ($i = 1; $i <= 25; $i++) {
        db_insert(
            'INSERT INTO apps (title, author, user_id, is_private, show_list, bad, fav, view, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            ["作品-{$i}", 'ページング', $userId, 0, 1, 0, 0, 0, 'wnako', $now, $now]
        );
    }

    // 1ページ目 (offset=0)
    $_GET = ['user_id' => $userId, 'offset' => 0];
    $res = n3s_user_get();
    expect($res['total_count'])->toBe(25);
    expect($res['prev_url'])->toBeNull();
    expect($res['next_url'])->toContain('offset=24');

    // 2ページ目 (offset=24)
    $_GET = ['user_id' => $userId, 'offset' => 24];
    $res = n3s_user_get();
    expect($res['prev_url'])->toContain('offset=0');
    expect($res['next_url'])->toBeNull();
});
