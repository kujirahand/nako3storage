<?php
// tests/Unit/DiscordWebhookCurlTest.php
// todo-security.md #10:
// Discord Webhook送信用のcurlコマンドを組み立てる n3s_discord_webhook_curl_command() を検証する。
// 従来は常に --insecure を付けてTLS証明書検証を無効化していたが、サーバー環境によっては
// --insecure を外すとcurlがエラーになるため、既定(webhook_secure=false)は後方互換で --insecure を
// 維持しつつ、webhook_secure=true の環境ではTLS証明書検証を有効化できるようにした。

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/n3s_lib.inc.php';

test('webhook_secure が未設定(既定false)なら --insecure を付ける (後方互換)', function () {
    global $n3s_config;
    unset($n3s_config['webhook_secure']);
    $cmd = n3s_discord_webhook_curl_command('https://discord.com/api/webhooks/x', '{"content":"hi"}');
    expect($cmd)->toContain('--insecure');
});

test('webhook_secure=false なら --insecure を付ける', function () {
    global $n3s_config;
    $n3s_config['webhook_secure'] = false;
    $cmd = n3s_discord_webhook_curl_command('https://discord.com/api/webhooks/x', '{"content":"hi"}');
    expect($cmd)->toContain('--insecure');
});

test('webhook_secure=true なら --insecure を付けない(TLS証明書検証が有効になる)', function () {
    global $n3s_config;
    $n3s_config['webhook_secure'] = true;
    $cmd = n3s_discord_webhook_curl_command('https://discord.com/api/webhooks/x', '{"content":"hi"}');
    expect($cmd)->not->toContain('--insecure');
});

test('URLとJSON本文は escapeshellarg() でエスケープされてコマンドに含まれる(シェルインジェクション対策の維持)', function () {
    global $n3s_config;
    $n3s_config['webhook_secure'] = true;
    $url = 'https://discord.com/api/webhooks/x';
    // シングルクォートを含む入力(素朴な実装だとクォート抜けを起こしやすい代表例)
    $json = '{"content":"it'."'".'s a test; rm -rf /"}';
    $cmd = n3s_discord_webhook_curl_command($url, $json);

    // escapeshellarg() された形でしか値が現れないこと(生の文字列連結ではないこと)を確認する
    expect($cmd)->toContain(escapeshellarg($url));
    expect($cmd)->toContain(escapeshellarg($json));
});
