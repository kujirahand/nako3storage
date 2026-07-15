<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/edit.inc.php';

test('新規プログラム編集画面は作業画面の構造と保存契約を維持する', function () {
    $_GET['page'] = 'new';
    $out = n3s_test_capture(fn() => n3s_web_edit());

    expect($out)
        ->toContain('class="n3s-mypage-page n3s-edit-page"')
        ->toContain('id="edit-workspace"')
        ->toContain('id="runButton"')
        ->toContain('id="editLayoutButton"')
        ->toContain('id="nako3code"')
        ->toContain('id="runbox"')
        ->toContain('id="canvas_w"')
        ->toContain('id="canvas_h"')
        ->toContain('id="recover_btn"')
        ->toContain('<details class="n3s-edit-version-area">')
        ->toContain('id="forceNakoVer"')
        ->toContain('id="n3s_save_form"')
        ->toContain('name="body"')
        ->toContain('新規保存');
});

test('編集画面のマイページと素材リンクは別タブで開く', function () {
    n3s_add_user('edit-design@example.com', 'password1', '編集太郎');
    expect(n3s_login('edit-design@example.com', 'password1'))->toBeTrue();

    $_GET['page'] = 'new';
    $out = n3s_test_capture(fn() => n3s_web_edit());

    expect($out)
        ->toContain('<a target="_blank" rel="noopener" href="index.php?action=mypage">マイページ</a>')
        ->toContain('<a target="_blank" rel="noopener" href="index.php?action=mypage&amp;mode=material">素材</a>');
});
