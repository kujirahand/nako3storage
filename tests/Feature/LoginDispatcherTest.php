<?php
// app/action/login.inc.php の n3s_web_login()
// (実際にルーターから呼ばれるエントリーポイント。AGENTS.md #5 の
//  `n3s_{$agent}_{$action}` 組み立てにより `action=login` はここへ来る)
//
// これまでのテストは n3s_web_login_trylogin() などサブ関数を直接呼んでいたが、
// 実際の入口である n3s_web_login() 自体と、その中で行われる back URL の
// ホワイトリスト検証 (docs/user_login.md #2, #9) は未検証だったため補う。
//
// 注意: $page の判定は $_GET ではなく $n3s_config['page'] を見る
// (n3s_get_config('page', '')。本番では n3s_parseURI() が $_GET を $n3s_config に
// コピーしてから n3s_action() が呼ばれるが、テストでは n3s_parseURI() を通さないため
// 直接 $n3s_config['page'] をセットしてルーティングを模擬する)。

function n3s_test_set_page(string $page): void
{
    global $n3s_config;
    $n3s_config['page'] = $page;
}

test('back パラメータがサイト内URL (index.php?action=...) ならセッションに保存される', function () {
    $_GET['back'] = 'index.php?action=mypage';

    n3s_test_capture(fn () => n3s_web_login());

    expect($_SESSION['n3s_backurl'])->toBe('index.php?action=mypage');
});

test('back パラメータが外部URLの場合は無視される (オープンリダイレクト対策)', function () {
    $_GET['back'] = 'https://evil.example.com/phishing';

    n3s_test_capture(fn () => n3s_web_login());

    expect($_SESSION)->not->toHaveKey('n3s_backurl');
});

test('back パラメータがプロトコル相対URL(//)の場合も無視される', function () {
    $_GET['back'] = '//evil.example.com/';

    n3s_test_capture(fn () => n3s_web_login());

    expect($_SESSION)->not->toHaveKey('n3s_backurl');
});

test('back パラメータが無指定なら何も保存されない', function () {
    n3s_test_capture(fn () => n3s_web_login());

    expect($_SESSION)->not->toHaveKey('n3s_backurl');
});

test('page未指定時は既定でログイン試行として扱われる (n3s_web_login_trylogin への委譲)', function () {
    n3s_add_user('dispatch1@example.com', 'right-password', '委譲太郎');
    $token = n3s_getEditToken();
    $_POST['email'] = 'dispatch1@example.com';
    $_POST['password'] = 'right-password';
    $_REQUEST['edit_token'] = $token;

    n3s_test_capture(fn () => n3s_web_login());

    expect(n3s_is_login())->toBeTrue();
});

test('page=trylogin は明示的にログイン試行として扱われる', function () {
    n3s_add_user('dispatch2@example.com', 'right-password', '委譲二郎');
    n3s_test_set_page('trylogin');
    $token = n3s_getEditToken();
    $_POST['email'] = 'dispatch2@example.com';
    $_POST['password'] = 'right-password';
    $_REQUEST['edit_token'] = $token;

    n3s_test_capture(fn () => n3s_web_login());

    expect(n3s_is_login())->toBeTrue();
});

test('page=register は登録処理へ委譲される', function () {
    n3s_test_set_page('register');
    $_POST['email'] = 'dispatch3@example.com';
    $_POST['email2'] = 'dispatch3@example.com';
    $_POST['name'] = 'とうろくたろう';
    $_POST['itazura'] = '違う答え';

    $out = n3s_test_capture(fn () => n3s_web_login());

    expect($out)->toContain('イタズラ防止用の質問が間違っています')
        ->and(n3s_get_user_id_by_email('dispatch3@example.com'))->toBe(0);
});

test('page=forgot はパスワード再設定申請へ委譲される', function () {
    n3s_add_user('dispatch4@example.com', 'password1', '再設定四郎');
    n3s_test_set_page('forgot');
    $_POST['email'] = 'dispatch4@example.com';
    $_POST['email2'] = 'dispatch4@example.com';
    $_POST['quiz'] = '違うこたえ';

    $out = n3s_test_capture(fn () => n3s_web_login());

    expect($out)->toContain('クイズの答えを入力してください');
});
