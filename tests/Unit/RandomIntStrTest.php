<?php

test('n3s_randomIntStr は指定した桁数の数字文字列を返す', function () {
    $s = n3s_randomIntStr(7);

    expect($s)->toHaveLength(7)
        ->and($s)->toMatch('/^[0-9]{7}$/');
});

test('n3s_randomIntStr は桁数を指定できる', function () {
    expect(n3s_randomIntStr(3))->toHaveLength(3);
    expect(n3s_randomIntStr(4))->toHaveLength(4);
});
