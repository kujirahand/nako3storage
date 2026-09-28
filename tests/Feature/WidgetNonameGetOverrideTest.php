<?php
// tests/Feature/WidgetNonameGetOverrideTest.php
// レビュー指摘(PR #255): n3s_parseURI() は $_GET の全キーを無条件に $n3s_config へ
// コピーし、n3s_template_fw() は $n3s_config + $params ($n3s_config優先) でマージするため、
// widget.inc.php / widget_frame.inc.php が計算した $a['w_noname'] と同名の
// ?w_noname=... というGETパラメータが飛んでくると、テンプレートに渡る値がGET側で
// 上書きされてしまい、w_noname タグ付き作品のタイトル・作者の秘匿が破られる。
//
// n3s_widget_force_config() が、計算済みの値で $n3s_config 側を上書きし直すことで、
// このGETからの偽装を防いでいることを確認する。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/widget_frame.inc.php';

beforeEach(function () {
    n3s_test_setup();
    $_SERVER['SCRIPT_NAME'] = '/index.php';
});

test('n3s_parseURI() は ?w_noname=... をそのまま $n3s_config にコピーする(再現)', function () {
    $_GET['w_noname'] = '0';
    n3s_parseURI();

    // 対策前の状態を再現: GET由来の値がそのまま $n3s_config に乗る
    expect($GLOBALS['n3s_config']['w_noname'])->toBe('0');
});

test('n3s_widget_force_config() で上書きした後は、GET由来の値より計算済みの値が優先される', function () {
    // 攻撃者が w_noname タグ付き作品を ?w_noname=0 付きで開こうとするケース
    $_GET['w_noname'] = '0';
    n3s_parseURI();
    expect($GLOBALS['n3s_config']['w_noname'])->toBe('0'); // まだGET由来のまま

    // widget.inc.php / widget_frame.inc.php が計算した「本当は秘匿すべき」値(true)で強制上書き
    n3s_widget_force_config('w_noname', true);
    expect($GLOBALS['n3s_config']['w_noname'])->toBeTrue();

    // n3s_template_fw() が実際に行うマージ ($n3s_config + $params) を再現し、
    // $params 側に矛盾した値(false)が来てもテンプレートに渡る最終値は
    // $n3s_config 側の true (秘匿する) のままであることを確認する
    $params = ['w_noname' => false];
    $merged = $GLOBALS['n3s_config'] + $params;
    expect($merged['w_noname'])->toBeTrue();
});

test('n3s_web_widget() を ?w_noname=0 付きで呼んでも、w_noname タグ付き作品の表示は秘匿されたままになる', function () {
    require_once N3S_TEST_ROOT . '/app/action/widget.inc.php';

    $app_id = db_insert(
        'INSERT INTO apps (title, author, user_id, is_private, tag, nakotype, ctime, mtime) VALUES (?,?,?,?,?,?,?,?)',
        ['ヒミツの作品', 'テスト作者', 0, 0, 'w_noname', 'wnako', time(), time()]
    );
    $dbname = n3s_getMaterialDB($app_id);
    db_insert('INSERT INTO materials (material_id, body) VALUES (?,?)', [$app_id, str_repeat('あ', 30)], $dbname);

    $_GET = [
        'page' => (string)$app_id,
        'ui' => '1',
        'w_noname' => '0', // 秘匿判定を無効化しようとする攻撃者由来のパラメータ
    ];
    n3s_parseURI();

    $out = n3s_test_capture(function () {
        n3s_web_widget();
    });

    // <title>タグ(ブラウザのタブタイトル)は w_noname に関係なく従来から出力されるため対象外とし、
    // 画面に表示されるヘッダ本文(<body>以降)にタイトル・作者名が漏れていないことを確認する
    $body = substr($out, (int)strpos($out, '<body'));
    expect($body)->not->toContain('ヒミツの作品');
    expect($body)->not->toContain('テスト作者');
});
