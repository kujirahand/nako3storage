<?php
// tests/Feature/ImageLikeTest.php (#257 素材のいいね)
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/upload.inc.php';
require_once N3S_TEST_ROOT . '/app/action/mypage.inc.php';

/** 素材を直接DBへ追加して image_id を返す */
function n3s_test_add_image($user_id, string $copyright = 'CC0', string $title = 'テスト素材'): int
{
    return (int) db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES(?,?,?,?,?,?,?)',
        [$title, '', 'x.png', intval($user_id), $copyright, time(), time()]
    );
}

/** mode=fav を POST として呼び、JSON を配列で返す */
function n3s_test_image_fav(int $image_id, ?string $token = null): array
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['mode' => 'fav'];
    $_POST = ['image_id' => $image_id];
    if ($token !== null) {
        $_POST['edit_token'] = $token;
    }
    $_REQUEST = array_merge($_GET, $_POST);
    $out = n3s_test_capture(fn() => n3s_web_upload());
    $res = json_decode($out, true);
    return is_array($res) ? $res : ['result' => false, 'raw' => $out];
}

afterEach(function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
});

test('未ログインでは mode=fav が失敗する', function () {
    $owner = n3s_add_user('il-owner1@example.com', 'password', '提供者1');
    $image_id = n3s_test_add_image($owner);

    $res = n3s_test_image_fav($image_id, n3s_getEditToken());

    expect($res['result'])->toBeFalse();
    expect(db_get1('SELECT count(*) AS c FROM image_likes', [])['c'])->toEqual(0);
});

test('edit_token がない/違うと失敗する', function () {
    $owner = n3s_add_user('il-owner2@example.com', 'password', '提供者2');
    $image_id = n3s_test_add_image($owner);
    n3s_add_user('il-user2@example.com', 'password', '利用者2');
    n3s_web_login_execute('il-user2@example.com', 'password');
    n3s_getEditToken();

    $res1 = n3s_test_image_fav($image_id);
    $res2 = n3s_test_image_fav($image_id, 'wrong-token');

    expect($res1['result'])->toBeFalse();
    expect($res2['result'])->toBeFalse();
    expect(db_get1('SELECT count(*) AS c FROM image_likes', [])['c'])->toEqual(0);
});

test('いいねを付けると fav が1増え、もう一度で外れて0に戻る', function () {
    $owner = n3s_add_user('il-owner3@example.com', 'password', '提供者3');
    $image_id = n3s_test_add_image($owner);
    $uid = n3s_add_user('il-user3@example.com', 'password', '利用者3');
    n3s_web_login_execute('il-user3@example.com', 'password');
    $token = n3s_getEditToken();

    $res = n3s_test_image_fav($image_id, $token);
    expect($res['result'])->toBeTrue();
    expect($res['fav'])->toBe(1);
    expect($res['liked'])->toBe(1);
    expect((int) db_get1('SELECT fav FROM images WHERE image_id=?', [$image_id])['fav'])->toBe(1);
    expect(db_get1('SELECT * FROM image_likes WHERE image_id=? AND user_id=?', [$image_id, $uid]))->not->toBeNull();

    $res2 = n3s_test_image_fav($image_id, $token);
    expect($res2['result'])->toBeTrue();
    expect($res2['fav'])->toBe(0);
    expect($res2['liked'])->toBe(0);
    expect((int) db_get1('SELECT fav FROM images WHERE image_id=?', [$image_id])['fav'])->toBe(0);
    expect(db_get1('SELECT * FROM image_likes WHERE image_id=?', [$image_id]))->toBeFalsy();
});

test('自分の素材・SELF素材・存在しない素材にはいいねできない', function () {
    $owner = n3s_add_user('il-owner4@example.com', 'password', '提供者4');
    $uid = n3s_add_user('il-user4@example.com', 'password', '利用者4');
    $mine = n3s_test_add_image($uid);
    $self = n3s_test_add_image($owner, 'SELF');
    n3s_web_login_execute('il-user4@example.com', 'password');
    $token = n3s_getEditToken();

    expect(n3s_test_image_fav($mine, $token)['result'])->toBeFalse();
    expect(n3s_test_image_fav($self, $token)['result'])->toBeFalse();
    expect(n3s_test_image_fav(99999, $token)['result'])->toBeFalse();
    expect(db_get1('SELECT count(*) AS c FROM image_likes', [])['c'])->toEqual(0);
    expect((int) db_get1('SELECT fav FROM images WHERE image_id=?', [$self])['fav'])->toBe(0);
});

test('SELF素材へのいいね応答は存在しない素材と同じで fav 数を漏らさない', function () {
    $owner = n3s_add_user('il-owner9@example.com', 'password', '提供者9');
    $self = n3s_test_add_image($owner, 'SELF');
    db_exec('UPDATE images SET fav=5 WHERE image_id=?', [$self]);
    n3s_add_user('il-user9@example.com', 'password', '利用者9');
    n3s_web_login_execute('il-user9@example.com', 'password');
    $token = n3s_getEditToken();

    $res_self = n3s_test_image_fav($self, $token);
    $res_none = n3s_test_image_fav(99998, $token);

    expect($res_self['result'])->toBeFalse()
        ->and($res_self['fav'])->toBe(0)
        ->and($res_self['msg'])->toBe($res_none['msg']);
});

test('素材一覧にアクセス数・いいね数・ボタンが表示される', function () {
    $owner = n3s_add_user('il-owner5@example.com', 'password', '提供者5');
    $image_id = n3s_test_add_image($owner, 'CC0', '一覧表示素材');
    db_exec('UPDATE images SET view=12, fav=3 WHERE image_id=?', [$image_id]);
    n3s_add_user('il-user5@example.com', 'password', '利用者5');
    n3s_web_login_execute('il-user5@example.com', 'password');

    $_GET = ['mode' => 'list'];
    $_REQUEST = $_GET;
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)->toContain('👀 12')
        ->and($out)->toContain('❤️')
        ->and($out)->toContain('n3s-image-fav-count')
        ->and($out)->toContain('n3s-image-fav-btn')
        ->and($out)->toContain('data-image-id="' . $image_id . '"');
});

test('マイページにいいねした素材のタイトルが表示される', function () {
    $owner = n3s_add_user('il-owner6@example.com', 'password', '提供者6');
    $image_id = n3s_test_add_image($owner, 'CC0', 'いいね済み素材タイトル');
    $uid = n3s_add_user('il-user6@example.com', 'password', '利用者6');
    expect(n3s_login('il-user6@example.com', 'password'))->toBeTrue();
    $token = n3s_getEditToken();
    expect(n3s_test_image_fav($image_id, $token)['result'])->toBeTrue();

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['page' => '0'];
    $_POST = [];
    $_REQUEST = $_GET;
    $out = n3s_test_capture(fn() => n3s_web_mypage());

    expect($out)->toContain('いいねした素材')
        ->and($out)->toContain('いいね済み素材タイトル');
});

test('マイページでは SELF化した他人の素材といいね解除済みの素材は表示されない', function () {
    $owner = n3s_add_user('il-owner8@example.com', 'password', '提供者8');
    $image_id = n3s_test_add_image($owner, 'CC0', '後からSELF化する素材');
    $uid = n3s_add_user('il-user8@example.com', 'password', '利用者8');
    expect(n3s_login('il-user8@example.com', 'password'))->toBeTrue();
    $token = n3s_getEditToken();
    expect(n3s_test_image_fav($image_id, $token)['result'])->toBeTrue();
    // 所有者が自分専用に変更した
    db_exec("UPDATE images SET copyright='SELF' WHERE image_id=?", [$image_id]);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['page' => '0'];
    $_POST = [];
    $_REQUEST = $_GET;
    $out = n3s_test_capture(fn() => n3s_web_mypage());

    expect($out)->toContain('いいねした素材')
        ->and($out)->not->toContain('後からSELF化する素材');
});

test('素材を削除すると image_likes の行も消える', function () {
    $owner = n3s_add_user('il-owner7@example.com', 'password', '提供者7');
    $image_id = n3s_test_add_image($owner);
    db_exec('INSERT INTO image_likes (user_id, image_id, ctime) VALUES(?,?,?)', [99, $image_id, time()]);

    n3s_delete_image_record_and_file($image_id);

    expect(db_get1('SELECT count(*) AS c FROM image_likes', [])['c'])->toEqual(0);
});
