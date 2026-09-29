<?php

// n3s_parse_nako3_functions() の対応記法ごとの回帰テスト (#276, #277)

test('「とは:」+ 次行の###DocStringを説明として取り出す', function () {
    $body = "●(Aの)(Bの)足すとは:\n    ### AとBを足して返す\n";
    $r = n3s_parse_nako3_functions($body);

    expect($r)->toHaveCount(1);
    expect($r[0]['name'])->toBe('足す');
    expect($r[0]['args'])->toBe(['Aの', 'Bの']);
    expect($r[0]['args_str'])->toBe('Aの、Bの');
    expect($r[0]['desc'])->toBe('AとBを足して返す');
});

test('「とは」+ ここまでブロック内の###DocStringを説明として取り出す', function () {
    $body = "●(Aの)引くとは\n    ### AからBを引いて返す\nここまで\n";
    $r = n3s_parse_nako3_functions($body);

    expect($r)->toHaveCount(1);
    expect($r[0]['name'])->toBe('引く');
    expect($r[0]['args'])->toBe(['Aの']);
    expect($r[0]['desc'])->toBe('AからBを引いて返す');
});

test('「とは: # 説明」のインラインコメントを説明として取り出す', function () {
    $body = "●(Aの)掛けるとは: # AにBを掛けて返す\n";
    $r = n3s_parse_nako3_functions($body);

    expect($r)->toHaveCount(1);
    expect($r[0]['name'])->toBe('掛ける');
    expect($r[0]['desc'])->toBe('AにBを掛けて返す');
});

test('「とは:」+ 次行の#DocString(DocString未設定形式)を説明として取り出す', function () {
    $body = "●(Aの)割るとは:\n    # AをBで割って返す\n";
    $r = n3s_parse_nako3_functions($body);

    expect($r)->toHaveCount(1);
    expect($r[0]['name'])->toBe('割る');
    expect($r[0]['desc'])->toBe('AをBで割って返す');
});

test('全角括弧・全角読点を使った「とは、」形式にも対応する', function () {
    $body = "●（Aに）お申し込むとは、\n    戻る。\n";
    $r = n3s_parse_nako3_functions($body);

    expect($r)->toHaveCount(1);
    expect($r[0]['name'])->toBe('お申し込む');
    expect($r[0]['args'])->toBe(['Aに']);
});

test('「とは」を省略した「●関数名(引数)」形式にも対応する', function () {
    $body = "//コメントの言いかえです\n●部品作成先変更(domに)\n　domにDOM親部品設定\n";
    $r = n3s_parse_nako3_functions($body);

    expect($r)->toHaveCount(1);
    expect($r[0]['name'])->toBe('部品作成先変更');
    expect($r[0]['args'])->toBe(['domに']);
    expect($r[0]['desc'])->toBe('コメントの言いかえです');
});

test('引数の無い関数は空の引数配列になる', function () {
    $body = "//dom を返します\n●部品作成先取得\n　DOM親要素を戻す\n";
    $r = n3s_parse_nako3_functions($body);

    expect($r)->toHaveCount(1);
    expect($r[0]['name'])->toBe('部品作成先取得');
    expect($r[0]['args'])->toBe([]);
    expect($r[0]['args_str'])->toBe('');
    expect($r[0]['desc'])->toBe('dom を返します');
});

test('直前の説明が無ければ説明は空文字のままになる', function () {
    $body = "部品作成先スタック=[]\n\n●部品作成先戻す\n　それを戻す\n";
    $r = n3s_parse_nako3_functions($body);

    expect($r)->toHaveCount(1);
    expect($r[0]['name'])->toBe('部品作成先戻す');
    expect($r[0]['desc'])->toBe('');
});

test('"/* ... */" ブロックコメント内の●記述は関数定義として検出しない', function () {
    $body = "/*\n ●部品作成先変更(domに)\n ●部品作成先取得\n*/\n\n//「DOM親部品設定」の言いかえ\n●部品作成先変更(domに)\n　domにDOM親部品設定\n\n//domを返します\n●部品作成先取得\n　DOM親要素を戻す\n";
    $r = n3s_parse_nako3_functions($body);

    // ブロックコメント内の記述が誤検出されず、実際のコード上の2つだけが検出される
    expect($r)->toHaveCount(2);
    expect($r[0]['name'])->toBe('部品作成先変更');
    expect($r[0]['desc'])->toBe('「DOM親部品設定」の言いかえ');
    expect($r[1]['name'])->toBe('部品作成先取得');
    expect($r[1]['desc'])->toBe('domを返します');
});

test('"#----" のような区切りの罫線は説明に含めない', function () {
    $body = "#-----------------------------------------------------------------------\n●部品作成先変更(domに)\n　domにDOM親部品設定\n";
    $r = n3s_parse_nako3_functions($body);

    expect($r)->toHaveCount(1);
    expect($r[0]['desc'])->toBe('');
});

test('前の関数の説明が、説明の無い次の関数に漏れて引き継がれない (レビュー指摘の回帰)', function () {
    $body = "●Aとは:\n    ### Aの説明\n●Bとは:\n    本体\n";
    $r = n3s_parse_nako3_functions($body);

    expect($r)->toHaveCount(2);
    expect($r[0]['name'])->toBe('A');
    expect($r[0]['desc'])->toBe('Aの説明');
    expect($r[1]['name'])->toBe('B');
    expect($r[1]['desc'])->toBe('');
});

test('本文が空文字なら空配列を返す', function () {
    expect(n3s_parse_nako3_functions(''))->toBe([]);
});
