<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/list.inc.php';

test('ユーザー別一覧(action=list&user_id=XX)は新ページへ301リダイレクトされ、出力を早期終了することを確認', function () {
    $userId = n3s_add_user('list-user@example.com', 'password1', '一覧太郎');

    $_GET = ['user_id' => (string)$userId];

    // n3s_web_list はリダイレクトヘッダーを送信し、テスト環境なので exit せずに return する。
    // そのため、画面出力（テンプレート描画など）は行われず、空文字列が返るはず。
    $out = n3s_test_capture(fn() => n3s_web_list());

    expect($out)->toBeEmpty();
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
