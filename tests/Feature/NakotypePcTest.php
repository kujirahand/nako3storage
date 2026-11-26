<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/save.inc.php';
require_once N3S_TEST_ROOT . '/app/action/show.inc.php';

// 投稿形式にPC版(gonako/lnako/vnako)を追加 (#288)

test('保存画面の形式選択にPC版の選択肢が表示される', function () {
    $_GET['page'] = '0';
    $out = n3s_test_capture(fn() => n3s_web_save());

    expect($out)
        ->toContain('value="cnako"')
        ->toContain('PC版 (cnako3)')
        ->toContain('value="gonako"')
        ->toContain('PC版 (gonako)')
        ->toContain('value="lnako"')
        ->toContain('PC版 (lnako)')
        ->toContain('value="vnako"')
        ->toContain('旧PC版 (vnako.exe)')
        ->toContain("fmt != 'gonako' && fmt != 'lnako' && fmt != 'vnako'")
        ->not->toContain('なでしこ (cnako)');
});

function n3s_test_insert_pc_app(string $nakotype): int
{
    $now = time();
    $appId = (int)db_insert(
        'INSERT INTO apps (title, author, memo, user_id, is_private, nakotype, copyright, tag, version, app_name, ctime, mtime)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        ['PCテスト', '作者', '', 0, 0, $nakotype, 'MIT', '', '3.7.2', 'pclib_' . $nakotype . uniqid(), $now, $now]
    );
    db_insert(
        'INSERT INTO materials (material_id, body) VALUES (?,?)',
        [$appId, "●(Aの)(Bの)足すとは:\n    ### AとBを足して返す\n"],
        n3s_getMaterialDB($appId)
    );
    return $appId;
}

test('PC版の拡張子は gonako/lnako が .nako3、vnako が .nako になる', function () {
    foreach (['gonako' => '.nako3', 'lnako' => '.nako3', 'vnako' => '.nako'] as $type => $ext) {
        $appId = n3s_test_insert_pc_app($type);
        $_GET['page'] = (string)$appId;
        $a = n3s_show_get('show', 'web', true, true);
        expect($a['ext'])->toBe($ext);
    }
});

test('gonako/lnakoのライブラリ作品は関数一覧を表示し、vnakoは表示しない', function () {
    foreach (['gonako', 'lnako'] as $type) {
        $_GET['page'] = (string)n3s_test_insert_pc_app($type);
        $out = n3s_test_capture(fn() => n3s_web_show());
        expect($out)->toContain('id="func_list_area"');
    }
    $_GET['page'] = (string)n3s_test_insert_pc_app('vnako');
    $out = n3s_test_capture(fn() => n3s_web_show());
    expect($out)->not->toContain('id="func_list_area"');
});
