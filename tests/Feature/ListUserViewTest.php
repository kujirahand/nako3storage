<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/list.inc.php';

test('ユーザー別一覧では全体統計と投稿一覧の見出しを表示しない', function () {
    $userId = n3s_add_user('list-user@example.com', 'password1', '一覧太郎');
    db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['一覧太郎の作品', '一覧太郎', $userId, 0, 'wnako', time(), time()]
    );

    $_GET = ['user_id' => (string)$userId];
    $out = n3s_test_capture(fn() => n3s_web_list());

    expect($out)
        ->toContain('<h1>一覧太郎 さんの投稿</h1>')
        ->toContain('class="n3s-app-icon" src="https://n3s.nadesi.com/image.php?f=727.png"')
        ->toContain(">一覧太郎 作</a>\n        <br>\n        <span class=\"n3s-app-date\">")
        ->not->toContain('<h1>投稿数:')
        ->not->toContain('<h1>ユーザー数:')
        ->not->toContain('<h1>最新の投稿 ');
});

test('通常一覧では全体の投稿数とユーザー数の見出しを表示する', function () {
    n3s_add_user('list-all@example.com', 'password1', '一覧花子');

    $_GET = [];
    $out = n3s_test_capture(fn() => n3s_web_list());

    expect($out)
        ->toContain('<h1>投稿数: 0件</h1>')
        ->toContain('<h1>ユーザー数: 1名</h1>')
        ->toContain('<h1>最新の投稿 </h1>');
});
