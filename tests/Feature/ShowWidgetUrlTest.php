<?php
// tests/Feature/ShowWidgetUrlTest.php
// n3s_show_get() が組み立てる widget_url_run_allow の回帰テスト。
//
// 不具合: sandbox_url は n3s_config.def.php で常に '' として定義済みのため、
// n3s_get_config('sandbox_url', $n3s_url) は(キーが存在するので)常に '' を返し、
// 意図されていた「未設定ならアプリ自身のbaseurlへフォールバック」が効かなかった。
// さらに空文字に無条件で '/' を付与していたため、sandbox_url未設定時の
// widget_url_run_allow が "/widget.php?..." というルート相対URLになってしまい、
// サブディレクトリ配置(例: http://host/repos/nako3storage/)では
// ブラウザが "http://host/widget.php?..." に解決してしまい、実行リンクが壊れていた。
// (todo-security.md #4 のsandbox_urlガード追加に伴い、ローカル開発での動作確認中に発覚)

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/show.inc.php';

test('sandbox_url未設定・サブディレクトリ配置でも widget_url_run_allow はサブディレクトリを含む絶対URLになる', function () {
    n3s_set_config('sandbox_url', '');
    n3s_set_config('baseurl', 'http://localhost:7450/repos/nako3storage');

    $app_id = db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['実行URLテスト', 'テスト', 0, 0, 'wnako', time(), time()]
    );
    $dbname = n3s_getMaterialDB($app_id);
    db_insert('INSERT INTO materials (material_id, body) VALUES (?,?)', [$app_id, str_repeat('あ', 30)], $dbname);

    $_GET['page'] = (string)$app_id;
    $a = n3s_show_get('show', 'web', true, true);

    expect($a['widget_url_run_allow'])
        ->toBe("http://localhost:7450/repos/nako3storage/widget.php?{$app_id}&run=1&allow=1&nakotype=wnako");
});

test('sandbox_urlが設定されていれば、そのURLを使って widget_url_run_allow を組み立てる', function () {
    n3s_set_config('sandbox_url', 'https://sandbox.example.com/');
    n3s_set_config('baseurl', 'http://localhost:7450/repos/nako3storage');

    $app_id = db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?)',
        ['実行URLテスト2', 'テスト', 0, 0, 'wnako', time(), time()]
    );
    $dbname = n3s_getMaterialDB($app_id);
    db_insert('INSERT INTO materials (material_id, body) VALUES (?,?)', [$app_id, str_repeat('あ', 30)], $dbname);

    $_GET['page'] = (string)$app_id;
    $a = n3s_show_get('show', 'web', true, true);

    expect($a['widget_url_run_allow'])
        ->toBe("https://sandbox.example.com/widget.php?{$app_id}&run=1&allow=1&nakotype=wnako");
});
