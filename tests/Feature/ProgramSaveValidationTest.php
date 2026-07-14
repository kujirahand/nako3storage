<?php
// tests/Feature/ProgramSaveValidationTest.php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/save.inc.php';

test('n3s_action_save_check_param は不正なパラメータに対し正しく例外を投げる', function () {
    // 1. プログラム本文が空
    $a = [
        'body' => '',
        'author' => 'テスト太郎',
        'nakotype' => 'wnako',
        'user_id' => 0
    ];
    expect(fn() => n3s_action_save_check_param($a, true))
        ->toThrow(Exception::class, 'プログラムが空だと保存できません。');

    // 2. プログラム本文が30文字未満
    $a['body'] = '短い';
    expect(fn() => n3s_action_save_check_param($a, true))
        ->toThrow(Exception::class, 'プログラムは30字以上にしてください。');

    // 3. 作者名が2文字未満 (strlen判定のため半角1文字にする)
    $a['body'] = str_repeat('あ', 30);
    $a['author'] = 'a';
    expect(fn() => n3s_action_save_check_param($a, true))
        ->toThrow(Exception::class, '作者名は2文字以上にしてください。');

    // 4. ログインしていない状態でなでしこ(wnako)以外の言語を指定
    $a['author'] = 'テスト太郎';
    $a['nakotype'] = 'python';
    $a['user_id'] = 0;
    expect(fn() => n3s_action_save_check_param($a, true))
        ->toThrow(Exception::class, 'ログインしていない場合、なでしこ以外の言語は選べません。');
});

test('n3s_action_save_data_raw はNGワードが含まれる場合に例外を投げる', function () {
    // テスト環境のNGワード設定に 'だめ' を追加
    global $n3s_config;
    $n3s_config['ng_words'] = ['だめ'];
    
    // CSRFトークンを有効にする
    $token = n3s_getEditToken();
    $_POST['edit_token'] = $token;
    $_REQUEST['edit_token'] = $token;
    
    $data = [
        'title' => 'テストタイトル だめ', // タイトルにNGワード
        'author' => 'テスト太郎',
        'body' => str_repeat('あ', 30),
        'nakotype' => 'wnako',
        'copyright' => 'MIT',
        'is_private' => 0,
        'agree' => 'checked',
        'version' => '3.3.0',
        'edit_token' => $token,
    ];
    
    expect(fn() => n3s_action_save_data_raw($data, 'web'))
        ->toThrow(Exception::class, '申し訳ありません。NGワード「だめ」が含まれており、保存できません。');
});
