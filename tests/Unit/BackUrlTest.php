<?php

test('n3s_setBackURL で保存したURLは n3s_getBackURL で一度だけ取得できる', function () {
    n3s_setBackURL('index.php?action=show&page=1');

    expect(n3s_getBackURL())->toBe('index.php?action=show&page=1')
        ->and(n3s_getBackURL())->toBe('');
});

test('何も設定していない場合 n3s_getBackURL は空文字を返す', function () {
    expect(n3s_getBackURL())->toBe('');
});
