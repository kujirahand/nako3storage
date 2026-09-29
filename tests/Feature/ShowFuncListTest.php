<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/show.inc.php';

// ライブラリ作品(app_name設定済み)の作品ページに関数一覧が表示されるかのテスト (#276, #277)

function n3s_test_insert_funclist_app(array $overrides = []): int
{
    $now = time();
    $defaults = [
        'title' => 'テストライブラリ',
        'author' => '作者',
        'memo' => '',
        'user_id' => 0,
        'is_private' => 0,
        'nakotype' => 'wnako',
        'copyright' => 'MIT',
        'tag' => '',
        'version' => '3.7.2',
        'app_name' => '',
        'ctime' => $now,
        'mtime' => $now,
    ];
    $a = array_merge($defaults, $overrides);
    return (int)db_insert(
        'INSERT INTO apps (title, author, memo, user_id, is_private, nakotype, copyright, tag, version, app_name, ctime, mtime)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        array_values($a)
    );
}

test('app_nameが設定されたライブラリ作品は関数一覧を表示する', function () {
    $appId = n3s_test_insert_funclist_app(['app_name' => 'testlib_' . uniqid()]);
    $dbname = n3s_getMaterialDB($appId);
    db_insert(
        'INSERT INTO materials (material_id, body) VALUES (?,?)',
        [$appId, "//AとBを足す\n●(Aの)(Bの)足すとは:\n    ### AとBを足して返す\n"],
        $dbname
    );

    $_GET['page'] = (string)$appId;
    $out = n3s_test_capture(fn() => n3s_web_show());

    expect($out)
        ->toContain('id="func_list_area"')
        ->toContain('関数一覧')
        ->toContain('<td>足す</td>')
        ->toContain('<td>Aの、Bの</td>')
        ->toContain('AとBを足して返す')
        ->toContain('n3s-show-source-details')
        ->toContain('ソースを表示');
});

test('app_name未設定の作品は関数一覧を表示せず、ソースをそのまま表示する', function () {
    $appId = n3s_test_insert_funclist_app(['app_name' => '']);
    $dbname = n3s_getMaterialDB($appId);
    db_insert(
        'INSERT INTO materials (material_id, body) VALUES (?,?)',
        [$appId, "●(Aの)(Bの)足すとは:\n    ### AとBを足して返す\n"],
        $dbname
    );

    $_GET['page'] = (string)$appId;
    $out = n3s_test_capture(fn() => n3s_web_show());

    expect($out)
        ->not->toContain('id="func_list_area"')
        ->not->toContain('n3s-show-source-details')
        ->toContain('id="nako3code"');
});

test('app_nameが設定されていても関数定義が見つからなければ関数一覧を表示しない', function () {
    $appId = n3s_test_insert_funclist_app(['app_name' => 'testlib_' . uniqid()]);
    $dbname = n3s_getMaterialDB($appId);
    db_insert(
        'INSERT INTO materials (material_id, body) VALUES (?,?)',
        [$appId, "「こんにちは」と表示する。\n"],
        $dbname
    );

    $_GET['page'] = (string)$appId;
    $out = n3s_test_capture(fn() => n3s_web_show());

    expect($out)
        ->not->toContain('id="func_list_area"')
        ->not->toContain('n3s-show-source-details');
});
