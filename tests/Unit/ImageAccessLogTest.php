<?php
// tests/Unit/ImageAccessLogTest.php
// 素材(image.php)アクセスの生ログ n3s_record_image_access() と、
// 集計用マイグレーション n3s_db_migrate_images() の単体テスト。
//
// テスト対象:
//  - n3s_record_image_access() が image_access_log (log DB) へ1行追加する
//  - 同一IP・同一image_idを複数回呼んでも書き込み時点では重複除去しない
//    (重複除去は scripts/image_count.php の集計時に行う)
//  - image_id が 0 以下の場合は何も記録しない
//  - n3s_db_migrate_images() が既存DB(view カラム無し)にカラムを追加する
//  - n3s_db_migrate_images() は冪等

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/n3s_lib.inc.php';

/** image_access_log の行を全件取得する */
function _image_access_rows(): array
{
    return db_get('SELECT * FROM image_access_log ORDER BY log_id ASC', [], 'log') ?: [];
}

test('n3s_record_image_access() は image_access_log へ1行追加する', function () {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
    n3s_record_image_access(5);

    $rows = _image_access_rows();
    expect($rows)->toHaveCount(1);
    expect((int)$rows[0]['image_id'])->toBe(5);
    expect($rows[0]['ip'])->toBe('198.51.100.1');
    expect((int)$rows[0]['ctime'])->toBeGreaterThan(0);
});

test('n3s_record_image_access() を複数回呼ぶと重複除去せずその都度1行追加される', function () {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.2';
    n3s_record_image_access(7);
    n3s_record_image_access(7);
    n3s_record_image_access(7);

    $rows = _image_access_rows();
    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect((int)$row['image_id'])->toBe(7);
        expect($row['ip'])->toBe('198.51.100.2');
    }
});

test('異なるIPからのアクセスはそれぞれ別の行として記録される', function () {
    n3s_record_image_access(9);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.3';
    n3s_record_image_access(9);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.4';
    n3s_record_image_access(9);

    $rows = _image_access_rows();
    expect($rows)->toHaveCount(3);
    $ips = array_column($rows, 'ip');
    expect(count(array_unique($ips)))->toBe(3);
});

test('image_id が 0 以下なら何も記録しない', function () {
    n3s_record_image_access(0);
    n3s_record_image_access(-1);
    expect(_image_access_rows())->toHaveCount(0);
});

test('n3s_db_migrate_images() は既存DB(view カラム無し)に view カラムを追加する', function () {
    // 旧スキーマ (view なし) を再現する
    db_exec('DROP TABLE images', []);
    db_exec(
        "CREATE TABLE images (image_id INTEGER PRIMARY KEY, filename TEXT DEFAULT '')",
        []
    );
    db_exec("INSERT INTO images (filename) VALUES ('1.png')", []);

    n3s_db_migrate_images();

    $row = db_get1('SELECT view FROM images WHERE filename=?', ['1.png']);
    expect((int)$row['view'])->toBe(0);
});

test('n3s_db_migrate_images() は冪等で、既存の view 値を巻き戻さない', function () {
    db_exec('DROP TABLE images', []);
    db_exec(
        "CREATE TABLE images (image_id INTEGER PRIMARY KEY, filename TEXT DEFAULT '')",
        []
    );
    db_exec("INSERT INTO images (filename) VALUES ('1.png')", []);
    n3s_db_migrate_images();
    db_exec("UPDATE images SET view=42 WHERE filename='1.png'", []);

    n3s_db_migrate_images(); // 2回目もエラーにならず、既存値を変更しない

    $row = db_get1('SELECT view FROM images WHERE filename=?', ['1.png']);
    expect((int)$row['view'])->toBe(42);
});

test('新規DB (init-main.sql / init-log.sql) では view カラムと image_access_log が最初から存在する', function () {
    n3s_db_migrate_images(); // カラム・テーブルとも既にあるため早期リターン (例外にならないこと)
    db_exec("INSERT INTO images (filename) VALUES ('x.png')", []);
    $row = db_get1("SELECT view FROM images WHERE filename='x.png'", []);
    expect((int)$row['view'])->toBe(0);
    expect(_image_access_rows())->toHaveCount(0);
});
