<?php
declare(strict_types=1);

test('一覧カードの形式アイコンはなでしことその他で分ける', function () {
    $list = [
        ['nakotype' => 'wnako'],
        ['nakotype' => 'cnako'],
        ['nakotype' => 'js'],
        ['nakotype' => 'dncl'],
    ];

    n3s_list_setIcon($list);

    expect($list[0]['icon'])->toBe('https://n3s.nadesi.com/image.php?f=727.png')
        ->and($list[1]['icon'])->toBe('https://n3s.nadesi.com/image.php?f=727.png')
        ->and($list[2]['icon'])->toBe('https://n3s.nadesi.com/image.php?f=729.png')
        ->and($list[3]['icon'])->toBe('https://n3s.nadesi.com/image.php?f=729.png');
});
