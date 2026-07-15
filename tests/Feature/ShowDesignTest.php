<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/show.inc.php';

test('作品表示ページは共通デザインと既存の操作契約を維持する', function () {
    n3s_set_config('baseurl', 'http://localhost:7450/repos/nako3storage');
    $userId = n3s_add_user('show-design@example.com', 'password1', '表示太郎');
    db_exec('UPDATE users SET screen_name=? WHERE user_id=?', ['show_taro', $userId], 'users');

    $appId = db_insert(
        'INSERT INTO apps (title, author, memo, user_id, is_private, nakotype, copyright, tag, version, ctime, mtime) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        ['表示デザインテスト', '表示太郎', '作品表示ページの説明文です。', $userId, 0, 'wnako', 'MIT', 'テスト,表示', '3.7.2', time(), time()]
    );
    $dbname = n3s_getMaterialDB($appId);
    db_insert(
        'INSERT INTO materials (material_id, body) VALUES (?,?)',
        [$appId, '「作品表示ページのテストです」と表示する。十分な長さの本文です。'],
        $dbname
    );

    $_GET['page'] = (string)$appId;
    $out = n3s_test_capture(fn() => n3s_web_show());

    expect($out)
        ->toContain('class="n3s-mypage-page n3s-show-page"')
        ->toContain('aria-label="作品ページのナビゲーション"')
        ->toContain('<h1>表示デザインテスト</h1>')
        ->toContain('class="show_cover_start n3s-show-cover"')
        ->toContain('id="a_run_button"')
        ->toContain('>プログラムを実行</a>')
        ->toContain('id="program_area" class="n3s-show-section n3s-show-program"')
        ->toContain('id="nako3code"')
        ->toContain('id="runButton"')
        ->toContain('class="n3s-show-meta"')
        ->toContain('id="info_accordion_header"')
        ->toContain('id="comment_accordion_header"')
        ->toContain('id="comment_list_area"')
        ->toContain('class="pure-button n3s-show-x-share"')
        ->toContain('class="n3s-show-x-icon" aria-hidden="true">X</span>')
        ->toContain('<span>Xへ投稿</span>')
        ->toContain("window.app_id = {$appId};");
});
