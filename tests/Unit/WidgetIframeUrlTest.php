<?php
// tests/Unit/WidgetIframeUrlTest.php
// 実行画面(widget)が作る iframe URL とテンプレート変数の優先順位 (#194 のレビュー指摘)。
// - editkey を生のまま連結していたため、editkey=x&page=456 で別作品にトークンを渡せた。
// - GET から取り込んだ iframe_url 等が、アクションの計算値よりテンプレート上で優先されていた。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/widget.inc.php';

test('editkey に & や page= を含めても、iframe で実行する作品はトークンの発行対象と一致する', function () {
    $token = n3s_astorage_token_create(123, 7);
    $url = n3s_widget_iframe_url('https://sandbox.example.com/', [
        'page' => 123,
        'run' => 1,
        'editkey' => "x&page=456&api_token=evil'\"<>",
        'api_token' => $token,
        'nakotype' => 'wnako',
    ]);

    expect($url)->toStartWith('https://sandbox.example.com/index.php?action=widget_frame&');
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    expect($q['action'])->toBe('widget_frame')
        ->and($q['page'])->toBe('123')
        ->and($q['editkey'])->toBe("x&page=456&api_token=evil'\"<>")
        ->and($q['api_token'])->toBe($token)
        ->and(n3s_astorage_token_verify($q['api_token'])['app_id'])->toBe((int)$q['page']);
    expect($url)->not->toContain("'")->not->toContain('<');
});

test('GETから取り込んだキーは、アクションが計算したテンプレート変数を上書きできない', function () {
    $config = ['sandbox_params' => 'safe', 'iframe_url' => 'https://evil.example.com/', 'mode' => 'x'];
    $params = ['sandbox_params' => 'wide', 'iframe_url' => 'https://sandbox.example.com/ok', 'title' => 't'];

    $p = n3s_template_params($config, $params, ['iframe_url', 'mode']);

    expect($p['iframe_url'])->toBe('https://sandbox.example.com/ok')
        // GET由来でない設定値は従来どおり設定が優先される
        ->and($p['sandbox_params'])->toBe('safe')
        // 計算値が無いGETキーはそのまま使える
        ->and($p['mode'])->toBe('x')
        ->and($p['title'])->toBe('t');
});

test('n3s_parseURI は GET から取り込んだキーを記録する', function () {
    $_SERVER['REQUEST_URI'] = '/index.php?action=widget&iframe_url=x';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_GET = ['action' => 'widget', 'iframe_url' => 'https://evil.example.com/'];
    n3s_parseURI();

    expect(n3s_request_config_keys())->toBe(['action', 'iframe_url']);
});
