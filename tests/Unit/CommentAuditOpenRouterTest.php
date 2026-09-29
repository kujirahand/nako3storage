<?php

require_once N3S_TEST_ROOT . '/app/comment_audit_openrouter.inc.php';

test('Jev では Decisions API に選択式の審査質問を送る', function () {
    list($url, $data) = n3s_comment_audit_request('投稿ありがとうございます', '~typesafe/jev-latest');

    expect($url)->toBe('https://openrouter.ai/api/alpha/decisions');
    expect($data['model'])->toBe('~typesafe/jev-latest');
    expect($data['state']['comment'])->toBe('投稿ありがとうございます');
    expect($data['questions']['moderation']['type'])->toBe('choice');
    expect(array_keys($data['questions']['moderation']['criteria']))->toBe(['approved', 'ng']);
});

test('Jev の選択結果だけを判定として受け取り、不正な応答は保留する', function () {
    $model = '~typesafe/jev-latest';
    expect(n3s_comment_audit_result(['answers' => ['moderation' => ['type' => 'choice', 'choice' => 'approved']]], $model))->toBe('approved');
    expect(n3s_comment_audit_result(['answers' => ['moderation' => ['type' => 'choice', 'choice' => 'ng']]], $model))->toBe('ng');
    expect(n3s_comment_audit_result(['answers' => ['moderation' => ['type' => 'choice', 'choice' => 'unknown']]], $model))->toBe('error');
    expect(n3s_comment_audit_result(['answers' => []], $model))->toBe('error');
    expect(n3s_comment_audit_result(['error' => ['message' => 'failed']], $model))->toBe('error');
});

test('Gemma の Chat Completions 経路も利用できる', function () {
    $model = 'google/gemma-3-12b-it';
    list($url, $data) = n3s_comment_audit_request('テスト', $model);

    expect($url)->toBe('https://openrouter.ai/api/v1/chat/completions');
    expect($data['messages'][0]['content'])->toContain('テスト');
    expect(n3s_comment_audit_result(['choices' => [['message' => ['content' => '{"approved":true}']]]], $model))->toBe('approved');
    expect(n3s_comment_audit_result(['choices' => [['message' => ['content' => '{"approved":false}']]]], $model))->toBe('ng');
});
