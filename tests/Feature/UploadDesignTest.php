<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/upload.inc.php';

test('アップロード画面はマイページ共通レイアウトで描画される', function () {
    n3s_add_user('upload-design@example.com', 'password1', '素材太郎');
    expect(n3s_login('upload-design@example.com', 'password1'))->toBeTrue();

    $_GET['mode'] = '';
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)
        ->toContain('class="n3s-mypage-page n3s-upload-page"')
        ->toContain('n3s-upload-form')
        ->toContain('素材をアップロード')
        ->toContain('<legend>規約同意</legend>')
        ->toContain('<legend>ライセンス選択</legend>')
        ->toContain('name="edit_token"');
});

test('アップロード画面の説明欄は折りたたまず常に表示される', function () {
    n3s_add_user('upload-desc@example.com', 'password1', '説明太郎');
    expect(n3s_login('upload-desc@example.com', 'password1'))->toBeTrue();

    $_GET['mode'] = '';
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)
        ->toContain('name="description"')
        ->toContain('<textarea id="description" name="description"')
        ->not->toContain('<details class="n3s-upload-description">');
});

test('アップロード画面の「アプリ内で使う場合」はデフォルトで折りたたまれている', function () {
    n3s_add_user('upload-optional@example.com', 'password1', '任意太郎');
    expect(n3s_login('upload-optional@example.com', 'password1'))->toBeTrue();

    $_GET['mode'] = '';
    $out = n3s_test_capture(fn() => n3s_web_upload());

    // details 要素に open 属性が付いていない(=デフォルトで隠れている)こと
    expect($out)
        ->toContain('<details class="n3s-upload-optional">')
        ->toContain('<summary>アプリ内で使う場合</summary>')
        ->toContain('name="app_id"')
        ->toContain('name="image_name"')
        ->not->toContain('<details class="n3s-upload-optional" open>');
});

test('説明付きの素材詳細は説明文を表示し、説明が空なら代替文言を表示する', function () {
    n3s_add_user('upload-desc-show@example.com', 'password1', '説明表示太郎');
    expect(n3s_login('upload-desc-show@example.com', 'password1'))->toBeTrue();
    $user = n3s_get_login_info();
    $now = time();

    // 説明あり
    $with_desc = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['説明あり画像', 'これはテスト用の説明です。', '410.png', $user['user_id'], 'CC0', $now, $now]
    );
    $_GET['mode'] = 'show';
    $_GET['image_id'] = (string)$with_desc;
    $out = n3s_test_capture(fn() => n3s_web_upload());
    expect($out)
        ->toContain('<dt>説明</dt>')
        ->toContain('これはテスト用の説明です。');

    // 説明なし: 「説明」欄自体は表示されるが、代替文言になる
    $no_desc = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['説明なし画像', '', '411.png', $user['user_id'], 'CC0', $now, $now]
    );
    $_GET['image_id'] = (string)$no_desc;
    $out2 = n3s_test_capture(fn() => n3s_web_upload());
    expect($out2)
        ->toContain('<dt>説明</dt>')
        ->toContain('(説明はありません)');
});

test('go_upload 相当の INSERT で description が保存される', function () {
    // go_upload() は move_uploaded_file を伴うため単体では実行しづらい。
    // 保存に使う INSERT 文と同じ形で description カラムへ保存できることを確認する。
    $now = time();
    $image_id = db_insert(
        'INSERT INTO images (title,description,user_id,copyright,app_id,image_name,token,ctime,mtime)VALUES(?,?,?,?,?,?,?,?,?)',
        ['保存テスト', '保存される説明文', 1, 'CC0', 0, '', '', $now, $now]
    );
    $row = db_get1('SELECT description FROM images WHERE image_id=?', [$image_id]);
    expect($row['description'])->toBe('保存される説明文');
});

test('素材一覧は画像プレビューと非画像形式を安全に描画する', function () {
    $now = time();
    db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?)',
        ['公開画像', '401.png', 1, 'CC0', $now, $now]
    );
    db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?)',
        ['公開PDF', '402.pdf', 1, 'CC0', $now, $now]
    );

    $_GET['mode'] = 'list';
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)
        ->toContain('n3s-upload-list-row')
        ->toContain('/image.php?f=401.png')
        ->toContain('<span>PDF</span>')
        ->toContain('公開画像')
        ->toContain('公開PDF');
});

test('素材詳細はプレビューと管理操作を共通レイアウトで描画する', function () {
    n3s_add_user('upload-detail@example.com', 'password1', '詳細太郎');
    expect(n3s_login('upload-detail@example.com', 'password1'))->toBeTrue();
    $user = n3s_get_login_info();
    $now = time();
    $image_id = db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,token,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['詳細画像', '403.png', $user['user_id'], 'SELF', 'detail-token', $now, $now]
    );

    $_GET['mode'] = 'show';
    $_GET['image_id'] = (string)$image_id;
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)
        ->toContain('n3s-upload-detail-grid')
        ->toContain('n3s-upload-danger-zone')
        ->toContain('/image.php?t=detail-token&amp;f=403.png')
        ->toContain('素材を削除');
});

test('素材詳細は所有者にタイトル・説明の編集フォームをデフォルトで折りたたんで表示する', function () {
    n3s_add_user('upload-edit-form@example.com', 'password1', '編集太郎');
    expect(n3s_login('upload-edit-form@example.com', 'password1'))->toBeTrue();
    $user = n3s_get_login_info();
    $now = time();
    $image_id = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['編集前タイトル', '編集前の説明', '404.png', $user['user_id'], 'CC0', $now, $now]
    );

    $_GET['mode'] = 'show';
    $_GET['image_id'] = (string)$image_id;
    $out = n3s_test_capture(fn() => n3s_web_upload());

    // <details class="n3s-mypage-section n3s-upload-section"> 要素に open 属性が付いていない(=デフォルトで隠れている)こと
    expect($out)
        ->toContain('action="index.php?action=upload&amp;mode=update"')
        ->toContain('id="edit_title"')
        ->toContain('value="編集前タイトル"')
        ->toContain('id="edit_description"')
        ->toContain('編集前の説明')
        ->toContain('<details class="n3s-mypage-section n3s-upload-section">')
        ->toContain('<summary>素材情報を編集</summary>')
        ->not->toContain('n3s-upload-section" open>');
});

test('素材詳細はアクセス数の下に説明を表示し、説明が空でも「説明」欄自体は表示する', function () {
    n3s_add_user('upload-desc-order@example.com', 'password1', '順序太郎');
    expect(n3s_login('upload-desc-order@example.com', 'password1'))->toBeTrue();
    $user = n3s_get_login_info();
    $now = time();

    // 説明あり
    $with_desc = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['説明順序テスト', '順序確認用の説明です。', '406.png', $user['user_id'], 'CC0', $now, $now]
    );
    $_GET['mode'] = 'show';
    $_GET['image_id'] = (string)$with_desc;
    $out = n3s_test_capture(fn() => n3s_web_upload());

    $view_pos = strpos($out, '<dt>アクセス数</dt>');
    $desc_pos = strpos($out, '<dt>説明</dt>');
    expect($view_pos)->not->toBeFalse();
    expect($desc_pos)->not->toBeFalse();
    expect($desc_pos)->toBeGreaterThan($view_pos);
    expect($out)->toContain('順序確認用の説明です。');

    // 説明なしでも「説明」欄自体は表示される
    $no_desc = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['説明なしテスト', '', '407.png', $user['user_id'], 'CC0', $now, $now]
    );
    $_GET['image_id'] = (string)$no_desc;
    $out2 = n3s_test_capture(fn() => n3s_web_upload());
    expect($out2)->toContain('<dt>説明</dt>');
});

test('素材の削除セクションはデフォルトで折りたたまれ、注意書きを含む', function () {
    n3s_add_user('upload-danger@example.com', 'password1', '危険太郎');
    expect(n3s_login('upload-danger@example.com', 'password1'))->toBeTrue();
    $user = n3s_get_login_info();
    $now = time();
    $image_id = db_insert(
        'INSERT INTO images (title,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?)',
        ['削除対象', '405.png', $user['user_id'], 'CC0', $now, $now]
    );

    $_GET['mode'] = 'show';
    $_GET['image_id'] = (string)$image_id;
    $out = n3s_test_capture(fn() => n3s_web_upload());

    // <details class="...n3s-upload-danger-zone"> 要素に open 属性が付いていない(=デフォルトで隠れている)こと
    expect($out)
        ->toContain('<details class="n3s-mypage-section n3s-upload-section n3s-upload-danger-zone">')
        ->toContain('<summary>素材の削除</summary>')
        ->toContain('素材を削除すると復元できません。ライセンスによっては他のユーザーが使っていることがあります。')
        ->not->toContain('n3s-upload-danger-zone" open>');
});

test('mode=update は所有者本人ならタイトルと説明を更新できる', function () {
    n3s_add_user('update-owner@example.com', 'password1', '更新太郎');
    n3s_web_login_execute('update-owner@example.com', 'password1');
    $user = n3s_get_login_info();
    $now = time();
    $image_id = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['元タイトル', '元の説明', '420.png', $user['user_id'], 'CC0', $now, $now]
    );

    // show 画面を開いて acc_token をセッションに載せる (削除フォームと共通の仕組み)
    $_GET['mode'] = 'show';
    $_GET['image_id'] = (string)$image_id;
    n3s_test_capture(fn() => n3s_web_upload());
    $token = $_SESSION['n3s_acc_token_upload'];

    $_GET['mode'] = 'update';
    $_POST = [
        'image_id' => (string)$image_id,
        'acc_token' => $token,
        'title' => '新しいタイトル',
        'description' => '新しい説明文',
    ];
    n3s_test_capture(fn() => n3s_web_upload());

    $row = db_get1('SELECT title, description FROM images WHERE image_id=?', [$image_id]);
    expect($row['title'])->toBe('新しいタイトル');
    expect($row['description'])->toBe('新しい説明文');
});

test('mode=update はトークンが不正だと更新を拒否する', function () {
    n3s_add_user('update-badtoken@example.com', 'password1', 'トークン太郎');
    n3s_web_login_execute('update-badtoken@example.com', 'password1');
    $user = n3s_get_login_info();
    $now = time();
    $image_id = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['元タイトル', '元の説明', '421.png', $user['user_id'], 'CC0', $now, $now]
    );

    $_GET['mode'] = 'update';
    $_POST = [
        'image_id' => (string)$image_id,
        'acc_token' => 'invalid-token',
        'title' => '書き換え後',
        'description' => '書き換え後説明',
    ];
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)->toContain('更新できません');
    $row = db_get1('SELECT title FROM images WHERE image_id=?', [$image_id]);
    expect($row['title'])->toBe('元タイトル');
});

test('mode=update は他人の素材を更新できない', function () {
    $owner_id = n3s_add_user('update-owner2@example.com', 'password1', '所有者');
    $now = time();
    $image_id = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['他人の画像', '説明', '422.png', $owner_id, 'CC0', $now, $now]
    );

    // 別ユーザーでログインし、show画面を開いて自分のセッションにacc_tokenを載せる
    n3s_add_user('update-other@example.com', 'password1', '別人');
    n3s_web_login_execute('update-other@example.com', 'password1');
    $_GET['mode'] = 'show';
    $_GET['image_id'] = (string)$image_id;
    n3s_test_capture(fn() => n3s_web_upload());
    $token = $_SESSION['n3s_acc_token_upload'];

    $_GET['mode'] = 'update';
    $_POST = [
        'image_id' => (string)$image_id,
        'acc_token' => $token,
        'title' => '乗っ取りタイトル',
        'description' => '乗っ取り説明',
    ];
    $out = n3s_test_capture(fn() => n3s_web_upload());

    expect($out)->toContain('更新権限がありません');
    $row = db_get1('SELECT title FROM images WHERE image_id=?', [$image_id]);
    expect($row['title'])->toBe('他人の画像');
});

test('素材一覧の検索機能：タイトル・解説で検索可能、SELF素材の除外、ワイルドカードのエスケープ、引き継ぎURL', function () {
    $now = time();
    // ユーザー作成とログイン
    n3s_add_user('search-material@example.com', 'password1', '検索太郎');
    expect(n3s_login('search-material@example.com', 'password1'))->toBeTrue();
    $user = n3s_get_login_info();

    // テストデータの投入
    // 1. タイトルが一致する公開素材
    $id_title_match = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['青空の画像', 'これは青い空です。', 'search1.png', $user['user_id'], 'CC0', $now, $now]
    );
    // 2. 解説が一致する公開素材
    $id_desc_match = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['夕暮れの写真', 'とても美しい夕日空です。', 'search2.png', $user['user_id'], 'CC0', $now, $now]
    );
    // 3. 一致しない公開素材
    $id_no_match = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['緑の草原', '緑が綺麗です。', 'search3.png', $user['user_id'], 'CC0', $now, $now]
    );
    // 4. タイトルが一致するがSELF（自分専用）素材
    $id_self_match = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['青空のヒミツ画像', '自分用の青空写真。', 'search4.png', $user['user_id'], 'SELF', $now, $now]
    );
    // 5. ワイルドカード文字を含む公開素材
    $id_wildcard = db_insert(
        'INSERT INTO images (title,description,filename,user_id,copyright,ctime,mtime) VALUES (?,?,?,?,?,?,?)',
        ['100%の力', 'パーセント記号を含むテスト', 'search5.png', $user['user_id'], 'CC0', $now, $now]
    );

    // テストA: sort=search で「空」で検索
    $_GET = [
        'mode' => 'list',
        'search_word' => '空',
        'sort' => 'search'
    ];
    $out = n3s_test_capture(fn() => n3s_web_upload());

    // タイトル一致（青空の画像）、解説一致（夕暮れの写真）が含まれていること
    expect($out)->toContain('青空の画像');
    expect($out)->toContain('夕暮れの写真');
    // 一致しない（緑の草原）が含まれていないこと
    expect($out)->not->toContain('緑の草原');
    // SELF素材（青空のヒミツ画像）が含まれていないこと
    expect($out)->not->toContain('青空のヒミツ画像');

    // 検索タブのリンクと次ページURLなどに search_word が引き継がれていること
    expect($out)->toContain('search_word=%E7%A9%BA');
    expect($out)->toContain('sort=ranking');
    expect($out)->toContain('sort=mtime');
    expect($out)->toContain('sort=search');

    // テストB: sort=search でヒットしないワードで検索
    $_GET = [
        'mode' => 'list',
        'search_word' => '存在しないはずのキーワード',
        'sort' => 'search'
    ];
    $out_empty = n3s_test_capture(fn() => n3s_web_upload());
    expect($out_empty)->toContain('『存在しないはずのキーワード』に一致する公開素材はありません。');

    // テストC: sort=search で%での検索（ワイルドカードのエスケープ検証）
    $_GET = [
        'mode' => 'list',
        'search_word' => '%',
        'sort' => 'search'
    ];
    $out_pct = n3s_test_capture(fn() => n3s_web_upload());
    // "100%の力" はヒットするはず
    expect($out_pct)->toContain('100%の力');
    // それ以外の素材（%を含まないもの）はヒットしないはず（ワイルドカードがエスケープされているため、全部にはヒットしない）
    expect($out_pct)->not->toContain('青空の画像');
    expect($out_pct)->not->toContain('夕暮れの写真');

    // テストD: sort=mtime (最新順タブ) のときは検索フォームが表示されず、全件表示されること
    $_GET = [
        'mode' => 'list',
        'search_word' => '空',
        'sort' => 'mtime'
    ];
    $out_mtime = n3s_test_capture(fn() => n3s_web_upload());
    // 検索フォームは非表示
    expect($out_mtime)->not->toContain('class="n3s-material-search-form');
    // 全件表示されること
    expect($out_mtime)->toContain('青空の画像');
    expect($out_mtime)->toContain('夕暮れの写真');
    expect($out_mtime)->toContain('緑の草原');
    expect($out_mtime)->toContain('100%の力');
});
