<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/save.inc.php';

test('新規投稿の保存画面は投稿フォームの主要入力を同一レイアウトで描画する', function () {
    $_GET['page'] = '0';
    $out = n3s_test_capture(fn() => n3s_web_save());

    expect($out)
        ->toContain('class="n3s-mypage-page n3s-save-page"')
        ->toContain('id="save-form"')
        ->toContain('id="program_options"')
        ->toContain('id="main_options"')
        ->toContain('id="body"')
        ->toContain('id="title"')
        ->toContain('id="memo"')
        ->toContain('id="format_options" class="n3s-save-field n3s-save-setting-row"')
        ->toContain('id="is_private"')
        ->toContain('id="show_list"')
        ->toContain('id="editkey_options"')
        ->toContain('id="save_options_button"')
        ->toContain('aria-controls="save_options"')
        ->toContain('id="save_options" class="n3s-save-advanced n3s-save-advanced-panel" hidden')
        ->toContain('for="version"')
        ->toContain('<span>利用規約に同意する</span>')
        ->toContain('>（利用規約を見る）</a>')
        ->toContain('class="n3s-save-submit-note">保存すると、投稿内容と選択した公開設定が反映されます。</p>')
        ->toContain('name="edit_token"')
        ->toContain('保存する');
});

test('ログイン時の保存画面は新規タブの管理リンクと扉絵入力を表示する', function () {
    n3s_add_user('save-design@example.com', 'password1', '保存太郎');
    expect(n3s_login('save-design@example.com', 'password1'))->toBeTrue();

    $_GET['page'] = '0';
    $out = n3s_test_capture(fn() => n3s_web_save());

    expect($out)
        ->toContain('<a target="_blank" rel="noopener" href="index.php?action=mypage">マイページ</a>')
        ->toContain('<a target="_blank" rel="noopener" href="index.php?action=mypage&amp;mode=material">素材</a>')
        ->toContain('name="cover_image"')
        ->toContain('id="author" name="author" type="hidden"');
});

test('プログラム削除は二段階の確認画面を初期状態で隠して表示する', function () {
    $userId = n3s_add_user('save-delete-design@example.com', 'password1', '削除太郎');
    expect(n3s_login('save-delete-design@example.com', 'password1'))->toBeTrue();

    $appId = db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, ctime, mtime, nakotype) VALUES (?,?,?,?,?,?,?)',
        ['削除表示テスト', '削除太郎', $userId, 0, time(), time(), 'wnako']
    );

    $_GET['page'] = (string)$appId;
    $out = n3s_test_capture(fn() => n3s_web_save());

    expect($out)
        ->toContain('id="delete-options-button"')
        ->toContain('aria-controls="delete-options"')
        ->toContain('id="delete-options" class="n3s-save-delete-panel" hidden')
        ->toContain('<h2 id="save-delete-title">完全に削除</h2>')
        ->toContain('一度、削除すると内容を戻すことはできません')
        ->toContain('name="yesno" type="hidden" value="yes"')
        ->toContain('value="完全削除を実行"')
        ->toContain('show_delete_options(false)');
});
