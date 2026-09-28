<?php
// tests/Unit/Nadesiko3hubSyncTest.php
// nadesiko3hubへの投稿取りこぼし補完バッチ (Issue #270) の単体テスト
//
// テスト対象:
//  - n3s_nadesiko3hub_save() の $skip_if_exists=true は既存ファイルを上書きしない
//  - n3s_nadesiko3hub_sync() は is_private=0 の作品のみを対象に、
//    既に .nako3 が存在する作品はスキップし、未出力の作品だけ保存する
//  - n3s_nadesiko3hub_sync() の $since_ts はスキャン対象を ctime/mtime で絞り込む

declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/n3s_lib.inc.php';

/** テスト用のnadesiko3hub出力先ディレクトリを用意し、$n3s_configへ設定する */
function _hub_setup_dir(): string
{
    global $n3s_config;
    $dir = N3S_TEST_TMP . '/' . uniqid('hub', true);
    mkdir($dir, 0777, true);
    $n3s_config['nadesiko3hub_enabled'] = true;
    $n3s_config['nadesiko3hub_dir'] = $dir;
    return $dir;
}

/** apps テーブルへテスト用の作品を1件挿入し、app_idを返す */
function _hub_insert_app(array $overrides = []): int
{
    $a = array_merge([
        'title'     => 'テスト作品',
        'author'    => '作者',
        'user_id'   => 1,
        'copyright' => 'MIT',
        'memo'      => '',
        'version'   => '3.6.0',
        'url'       => '',
        'nakotype'  => 'wnako',
        'tag'       => '',
        'is_private' => 0,
        'ctime'     => time(),
        'mtime'     => time(),
    ], $overrides);
    return (int)db_insert(
        'INSERT INTO apps (title, author, user_id, copyright, memo, version, url, nakotype, tag, is_private, ctime, mtime)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $a['title'], $a['author'], $a['user_id'], $a['copyright'], $a['memo'],
            $a['version'], $a['url'], $a['nakotype'], $a['tag'], $a['is_private'],
            $a['ctime'], $a['mtime'],
        ],
        'main'
    );
}

/** materials テーブルへ本文を保存する (n3s_getMaterialData() が参照する) */
function _hub_insert_material(int $app_id, string $body): void
{
    $dbname = n3s_getMaterialDB($app_id);
    db_exec(
        'INSERT INTO materials (material_id, body, app_id) VALUES (?,?,?)',
        [$app_id, $body, $app_id],
        $dbname
    );
}

// ------------------------------------------------
// n3s_nadesiko3hub_save(): $skip_if_exists
// ------------------------------------------------

test('n3s_nadesiko3hub_save() は $skip_if_exists=true のとき既存ファイルを上書きしない', function () {
    _hub_setup_dir();
    $data = [
        'app_id' => 100, 'title' => 'A', 'author' => 'author', 'user_id' => 1,
        'copyright' => 'MIT', 'memo' => '', 'version' => '3.6.0', 'url' => '',
        'nakotype' => 'wnako', 'tag' => '', 'is_private' => 0,
        'body' => '最初の本文', 'ctime' => time(), 'mtime' => time(),
    ];
    n3s_nadesiko3hub_save(100, $data);
    $file = n3s_nadesiko3hub_get_savefile(100);
    expect(file_exists($file))->toBeTrue();

    $data['body'] = '更新された本文';
    n3s_nadesiko3hub_save(100, $data, true);
    expect(file_get_contents($file))->toContain('最初の本文');
    expect(file_get_contents($file))->not->toContain('更新された本文');
});

test('n3s_nadesiko3hub_save() は $skip_if_exists=false (既定) だと上書きする', function () {
    _hub_setup_dir();
    $data = [
        'app_id' => 101, 'title' => 'A', 'author' => 'author', 'user_id' => 1,
        'copyright' => 'MIT', 'memo' => '', 'version' => '3.6.0', 'url' => '',
        'nakotype' => 'wnako', 'tag' => '', 'is_private' => 0,
        'body' => '最初の本文', 'ctime' => time(), 'mtime' => time(),
    ];
    n3s_nadesiko3hub_save(101, $data);
    $data['body'] = '更新された本文';
    n3s_nadesiko3hub_save(101, $data);
    $file = n3s_nadesiko3hub_get_savefile(101);
    expect(file_get_contents($file))->toContain('更新された本文');
});

// ------------------------------------------------
// n3s_nadesiko3hub_sync()
// ------------------------------------------------

test('n3s_nadesiko3hub_sync() は nadesiko3hub_enabled が無効なら何もしない', function () {
    global $n3s_config;
    $n3s_config['nadesiko3hub_enabled'] = false;
    $r = n3s_nadesiko3hub_sync();
    expect($r)->toBe(['total' => 0, 'saved' => 0, 'skipped' => 0]);
});

test('n3s_nadesiko3hub_sync() は未出力の公開作品だけを保存し、非公開は対象外にする', function () {
    _hub_setup_dir();
    $public_id = _hub_insert_app(['is_private' => 0]);
    _hub_insert_material($public_id, '「公開」と表示する。');
    $private_id = _hub_insert_app(['is_private' => 1]);
    _hub_insert_material($private_id, '「非公開」と表示する。');

    $r = n3s_nadesiko3hub_sync();

    expect($r['total'])->toBe(1); // is_private=0 のみが対象
    expect($r['saved'])->toBe(1);
    expect($r['skipped'])->toBe(0);
    expect(file_exists(n3s_nadesiko3hub_get_savefile($public_id)))->toBeTrue();
    expect(file_exists(n3s_nadesiko3hub_get_savefile($private_id)))->toBeFalse();
});

test('n3s_nadesiko3hub_sync() は既に .nako3 が存在する作品をスキップする', function () {
    _hub_setup_dir();
    $app_id = _hub_insert_app();
    _hub_insert_material($app_id, '「最初」と表示する。');

    $r1 = n3s_nadesiko3hub_sync();
    expect($r1['saved'])->toBe(1);
    expect($r1['skipped'])->toBe(0);

    // 本文を更新しても、既にファイルがあるので2回目は書き込まれない
    $dbname = n3s_getMaterialDB($app_id);
    db_exec('UPDATE materials SET body=? WHERE material_id=?', ['「更新後」と表示する。', $app_id], $dbname);

    $r2 = n3s_nadesiko3hub_sync();
    expect($r2['total'])->toBe(1);
    expect($r2['saved'])->toBe(0);
    expect($r2['skipped'])->toBe(1);

    $content = file_get_contents(n3s_nadesiko3hub_get_savefile($app_id));
    expect($content)->toContain('最初');
    expect($content)->not->toContain('更新後');
});

test('n3s_nadesiko3hub_sync() の $since_ts は ctime/mtime でスキャン対象を絞り込む', function () {
    _hub_setup_dir();
    $now = time();
    $old_id = _hub_insert_app(['ctime' => $now - 30 * 86400, 'mtime' => $now - 30 * 86400]);
    _hub_insert_material($old_id, '「古い作品」と表示する。');
    $new_id = _hub_insert_app(['ctime' => $now, 'mtime' => $now]);
    _hub_insert_material($new_id, '「新しい作品」と表示する。');

    $since_ts = $now - 7 * 86400;
    $r = n3s_nadesiko3hub_sync($since_ts);

    expect($r['total'])->toBe(1);
    expect(file_exists(n3s_nadesiko3hub_get_savefile($new_id)))->toBeTrue();
    expect(file_exists(n3s_nadesiko3hub_get_savefile($old_id)))->toBeFalse();
});
