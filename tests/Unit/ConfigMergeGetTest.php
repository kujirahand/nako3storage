<?php
// tests/Unit/ConfigMergeGetTest.php
// n3s_parseURI() が $_GET を $n3s_config へ取り込む処理 (#194 のレビュー指摘)。
// 以前は全GETキーを無条件にコピーしていたため、?astorage_token_secret=... で署名鍵を
// 差し替えてトークンを偽造したり、sandbox_url / agent / dir_action などの設定を
// 書き換えたりできた。

declare(strict_types=1);

test('既定設定で定義済みのセキュリティ関連キーはGETで上書きできない', function () {
    $config = [
        'astorage_token_secret' => '',
        'sandbox_url' => 'https://sandbox.example.com/',
        'login_allowed_hosts' => [],
        'admin_users' => [1],
        'agent' => 'web',
        'dir_action' => '/app/action',
    ];
    $get = [
        'astorage_token_secret' => str_repeat('x', 32),
        'sandbox_url' => '',
        'login_allowed_hosts' => ['sandbox.example.com'],
        'admin_users' => ['5'],
        'agent' => 'api',
        'dir_action' => '/tmp',
    ];

    expect(n3s_config_merge_get($config, $get))->toBe($config);
});

test('page / action / search_word と、既定設定に無いキーはGETから取り込む', function () {
    $config = ['page' => 'all', 'action' => 'list', 'search_word' => '', 'sandbox_url' => ''];
    $get = ['page' => '12', 'action' => 'show', 'search_word' => 'ゲーム', 'mode' => 'edit', 'user_id' => '3'];

    $r = n3s_config_merge_get($config, $get);
    expect($r['page'])->toBe('12')
        ->and($r['action'])->toBe('show')
        ->and($r['search_word'])->toBe('ゲーム')
        ->and($r['mode'])->toBe('edit')
        ->and($r['user_id'])->toBe('3')
        ->and($r['sandbox_url'])->toBe('');
});

test('GETで署名鍵を指定しても、そのGETを取り込んだ後のトークン検証には使われない', function () {
    global $n3s_config;
    $attacker_secret = str_repeat('x', 32);
    // 攻撃者が自分の鍵で署名したトークン
    $n3s_config['astorage_token_secret'] = $attacker_secret;
    $forged = n3s_astorage_token_create(1, 1);
    // 本来の設定(鍵は未設定 → info テーブルの鍵を使う)に GET を取り込む
    $n3s_config['astorage_token_secret'] = '';
    $n3s_config = n3s_config_merge_get($n3s_config, ['astorage_token_secret' => $attacker_secret]);

    expect(n3s_astorage_token_verify($forged))->toBeNull();
});
