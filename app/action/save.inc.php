<?php
// license
require_once dirname(__DIR__).'/license.inc.php';

function n3s_api_save()
{
    n3s_api_output(false, ["msg" => 'API経由のアクセスは停止中です。']);
}

function n3s_web_save()
{
    // check mode
    $mode = empty($_GET['mode']) ? '?' : $_GET['mode'];
    switch ($mode) {
    case 'edit': // DBに保存
      return n3s_action_save_data($_POST, 'web');
    case 'delete': // 投稿を削除
      return n3s_action_save_delete($_POST, 'web');
    case 'reset_bad': // 迷惑投稿をリセット
      return n3s_action_save_reset_bad($_POST, 'web');
    // 安全のため無効にしている
    // case 'verup_0_7': // update database (#80)
    //   return n3s_action_save_verup_0_7();
    default:
        // なでしこ簡易エディタなどからの投稿
        return n3s_show_save_form($mode);
    }
}

function n3s_show_save_form($mode)
{
    // $_GET['app_id'] のみチェックする
    $app_id = intval(isset($_GET['page']) ? $_GET['page'] : 0);
    $a = array();
    if ($app_id > 0) {
        n3s_web_save_check($app_id, $a);
        $a['agree'] = 'checked';
    }
    $_GET['rewrite'] = empty($_GET['rewrite']) ? '' : $_GET['rewrite'];

    if (!empty($_POST['body'])) {
        $cols = ["body", "canvas_w", "canvas_h", "version"];
        foreach ($cols as $key) {
            $a[$key] = empty($_POST[$key]) ? '' : $_POST[$key];
        }
    }
    // パラメータチェックを行う (ここでチェックは行わない)
    n3s_action_save_check_param($a, false);
  
    // rewrite パラメータのチェック
    //      yes : localStorageからデータを読み出してフォームを埋める(Twitterログイン後に内容を復元する)
    //      no : localStorageへデータを保存(ページ遷移しても大丈夫なように)
    //      @see save.html
    $a['rewrite'] = $_GET['rewrite'] = ($_GET['rewrite'] == 'yes') ? 'yes' : 'no';

    // ログイン情報を反映させる
    if ($app_id == 0 && n3s_is_login()) {
        $user = n3s_get_login_info();
        $a['user_id'] = $user['user_id'];
        $a['author'] = $user['name'];
    }
    $a['presave'] = 'no';
    $a['edit_token'] = n3s_getEditToken();
    // set backurl
    n3s_setBackURL(n3s_getURL($app_id, 'save', ['rewrite'=>$a['rewrite']]));
    n3s_template_fw('save.html', $a);
}

function n3s_web_save_check($app_id, &$a)
{
    global $n3s_config;
    $db = n3s_get_db();
    $msg = '';
    $a = $db->query("SELECT * FROM apps WHERE app_id=$app_id")->fetch();
    if (!$a) {
        n3s_jump(0, 'save'); // 新規保存のページを表示
        exit;
    }
    // ログインしていない投稿
    if ($a['user_id'] == 0) {
        // エラーにしない
    } else {
        if (!n3s_is_login()) {
            n3s_error('ログインが必要', '編集するにはログインしてください。');
            exit;
        }
    }
    if (n3s_is_admin()) {
        // ok
    } else {
        $a_user_id = $a['user_id'];
        $my_user_id = n3s_get_user_id();
        if ($a_user_id != $my_user_id) {
            n3s_error('自分の作品だけ編集できます', '他人の作品は編集できません。');
            exit;
        }
    }
}

function n3s_action_save_post_by_web()
{
    n3s_action_save_data($_POST);
}

function n3s_action_save_load_body(&$a)
{
    $material_id = intval(isset($a['app_id']) ? $a['app_id'] : 0);
    if ($material_id > 0) {
        $m = n3s_getMaterialData($material_id);
        if ($m) {
            $a['body'] = $m['body']."\n\n\n"; // エディタに余白が必要
        }
    } else {
        $a['body'] = '';
    }
}

function n3s_check_field_size(&$a)
{
    // get max size
  $size_source_max = n3s_get_config('size_source_max', 1024 * 1024 * 5); // 5MB
  $size_field_max = n3s_get_config('size_field_max', 1024 * 5); // 5KB
  // check size & trim data
  foreach ($a as $k => &$v) {
      if (!isset($v) || !is_string($v)) {
          continue;
      }
      $v = trim($v); // 値は自動でトリムする
      $size = strlen($v);
      // body ?
      if ($k == 'body') {
          if ($size > $size_source_max) {
              throw new Exception('プログラムが最大文字数を超えています。');
          }
          continue;
      }
      // other
      if ($size > $size_field_max) {
          throw new Exception('フィールドが最大文字数を超えています。');
      }
  }
}


function n3s_action_save_check_param(&$a, $check_error = false)
{
    if ($check_error) {
        n3s_check_field_size($a);
    }
    $a['app_id'] = isset($a['app_id']) ? intval($a['app_id']) : 0;
    $a['title'] = empty($a['title']) ? '' : $a['title'];
    $a['author'] = empty($a['author']) ? '' : $a['author'];
    $a['email'] = isset($a['email']) ? $a['email'] : '';
    $a['url'] = isset($a['url']) ? $a['url'] : '';
    $a['nakotype'] = isset($a['nakotype']) ? $a['nakotype'] : 'wnako';
    $a['tag'] = isset($a['tag']) ? $a['tag'] : '';
    $a['memo'] = isset($a['memo']) ? $a['memo'] : '';
    $a['body'] = isset($a['body']) ? $a['body'] : '';
    $a['version'] = isset($a['version']) ? $a['version'] : NAKO_DEFAULT_VERSION;
    $a['ip'] = isset($a['ip']) ? $a['ip'] : '';
    $a['copyright'] = isset($a['copyright']) ? $a['copyright'] : '未指定';
    $a['is_private'] = isset($a['is_private']) ? intval($a['is_private']) : 0;
    $a['ref_id'] = isset($a['ref_id']) ? intval($a['ref_id']) : -1;
    $a['canvas_w'] = isset($a['canvas_w']) ? intval($a['canvas_w']) : 300;
    $a['canvas_h'] = isset($a['canvas_h']) ? intval($a['canvas_h']) : 300;
    $a['user_id'] = isset($a['user_id']) ? intval($a['user_id']) : 0;
    $a['access_key'] = isset($a['access_key']) ? $a['access_key'] : '';
    $a['custom_head'] = isset($a['custom_head']) ? $a['custom_head'] : '';
    $a['edit_token'] = isset($a['edit_token']) ? $a['edit_token'] : '';
    $a['fav'] = intval(isset($a['fav']) ? $a['fav'] : 0);
    $a['nakotype'] = isset($a['nakotype']) ? $a['nakotype'] : '';
    // check copyright
    global $copyright_list, $copyright_desc;
    $a['copyright_list'] = $copyright_list;
    $a['copyright_desc'] = $copyright_desc;
    if (!isset($copyright_list[$a['copyright']])) {
        $a['copyright'] = '未指定';
    }
    // calc version int
    $version = preg_replace("/[^0-9.]/", "", $a['version']);
    $ver_a = explode(".", $version.'.0.0');
    $major = intval($ver_a[0]); // 3_00_00
    $minor = intval($ver_a[1]); // 3_99_00
    $patch = intval($ver_a[2]); // 3_11_99
    $ver_i = $major * 10000 + $minor * 100 + $patch;
    if ($ver_i < 30119) { // 古すぎるバージョンは無視する #89
        $ver_i = 30119;
        $a['version'] = '3.1.19';
    }
    $a['version_int'] = $ver_i;
    $a['agree'] = empty($a['agree']) ? '' : 'checked';

    // check params
    if (!$check_error) {
        return;
    }
    if ($a['body'] == '') {
        throw new Exception('プログラムが空だと保存できません。');
    }
    if (strlen($a['body']) < 30) {
        throw new Exception('プログラムは30字以上にしてください。');
    }
    if (strlen($a['author']) < 2) {
        throw new Exception('作者名は2文字以上にしてください。');
    }
    if (strlen($a['author']) > 50) {
        throw new Exception('作者名が50文字以下にしてください。');
    }
}

// save
function n3s_action_save_data($data, $agent = 'web')
{
    global $n3s_config;
    // セキュリティ対策のためAPI経由での保存を禁止した(#51)
    if ($agent == 'api') {
        n3s_api_output(false, array("msg"=>"You could not save from API access."));
        return;
    }
    try {
        $app_id = n3s_action_save_data_raw($data, $agent);
        n3s_jump($app_id, 'show');
    } catch (Exception $e) {
        n3s_error(
            "保存に失敗",
            "<p>".$e->getMessage()."</p>".
      "<p><button onclick='window.history.back()'>戻る".
      "</button></p>",
            true
        );
    }
}

function n3s_action_save_data_raw($data, $agent)
{
    global $n3s_config;

    $is_admin = n3s_is_admin();
    $user = n3s_get_login_info();
    $db = n3s_get_db();
    $app_id = n3s_get_config('page', 0);
    $a = $data;
    $b = array();
    $a['app_id'] = $app_id;
    n3s_action_save_check_param($a, true);
    $a['ip'] = $_SERVER['REMOTE_ADDR'];

    // CSRF対策
    if (!n3s_checkEditToken()) {
        throw new Exception(
            '保存に失敗しました。別のページを開いていれば閉じてください。改めて保存ボタンをクリックしてください。'
        );
    }

    // 上書き保存か？
    if ($app_id > 0) {
        // check editkey
        $b = $db->query('SELECT * FROM apps WHERE app_id='.$app_id)->fetch();
        if (!$b) {
            throw new Exception('app_idが不正です。');
        }
        $b_user_id = $b['user_id'];
        // admin?
        if (!$is_admin) {
            if ($b_user_id > 0) {
                $user_id = n3s_get_user_id();
                if ($user_id != $b_user_id) {
                    throw new Exception('他人の投稿です。自分の投稿しか編集できません！');
                }
            } else {
                // user_id = 0、つまりログインしていないユーザー
                if ($b['editkey'] != $a['editkey']) {
                    throw new Exception('編集キーが間違っています。');
                }
            }
        }
    }
    $a['mtime'] = time();
    // 連続投稿を防ぐ
    $a['body'] = trim($a['body']);
    $hash = $a['prog_hash'] = hash('sha256', $a['body']);
    if (!$a['is_private']) {
        // 公開されている内容のプログラムと同じ内容の投稿は不可
        $r = db_get1('SELECT * FROM apps WHERE prog_hash=? AND is_private=0', [$hash]);
        if ($r) {
            n3s_error('投稿エラー', 'すみません。既に同じ投稿が公開されており保存できませんでした。');
            exit;
        }
    } else {
        // 非公開だが全く同じ投稿は拒否する
        $r = db_get1('SELECT * FROM apps WHERE prog_hash=? AND author=?', [$hash, $a['author']]);
        if ($r) {
            n3s_error('投稿エラー', 'すみません。あなたが既に同じ内容で投稿されているようです。そのため保存しません。');
            exit;
        }
    }

    // 新規投稿の場合
    if ($app_id == 0) {
        return n3s_saveNewProgram($a);
    }
    // 更新の場合
    return n3s_updateProgram($app_id, $a);
}

function n3s_action_save_delete($params)
{
    global $n3s_config;
    // トークンのチェック
    if (!n3s_checkEditToken()) {
        n3s_error('トークンが無効', '再度実行してください。');
    }
    $app_id = intval(empty($_GET['page']) ? 0 : $_GET['page']);
    if ($app_id <= 0) {
        n3s_error('IDの不正', 'IDのエラー');
        exit;
    }
    $yesno = empty($_POST['yesno']) ? 'no' : $_POST['yesno'];
    if ($yesno != 'yes') {
        n3s_error('戻ってやり直してください', 'チェックボックスにチェックを入れてください。。');
        return;
    }

    $db = n3s_get_db();
    $a = $db->query("SELECT * FROM apps WHERE app_id=$app_id")->fetch();
    if (!$a) {
        n3s_error('指定のIDのアプリがありません', 'IDのエラー');
        exit;
    }
    $user = n3s_get_login_info();
    $user_id = $user['user_id'];
    $a_user_id = $a['user_id'];
    if (n3s_is_admin()) {
        // ok
    } elseif ($user_id == $a_user_id && $user_id > 0) {
        // ok
    } else {
        if ($a_user_id == 0) {
            n3s_error('管理者に連絡してください', '削除するには管理者に連絡してください。');
        } else {
            n3s_error('自分の作品しか削除できません', '自分のIDでない作品を削除しようとしています。');
        }
        return;
    }
    // 削除
    $db->query("DELETE FROM apps WHERE app_id=$app_id");
    // 情報
    n3s_template_fw('basic.html', [
    'contents' => "{$app_id} を削除しました。",
  ]);
}

function n3s_action_save_reset_bad($params)
{
    global $n3s_config;
    // トークンのチェック
    if (!n3s_checkEditToken()) {
        n3s_error('トークンが無効', '再度実行してください。');
    }
    // check app id
    $app_id = intval(empty($_GET['page']) ? 0 : $_GET['page']);
    if ($app_id <= 0) {
        n3s_error('IDの不正', 'IDのエラー');
        exit;
    }
    // 値を何に変更するか
    $bad_value = intval(empty($_POST['bad_value']) ? '0' : $_POST['bad_value']);
    // check app_id exists
    $db = n3s_get_db();
    $a = $db->query("SELECT * FROM apps WHERE app_id=$app_id")->fetch();
    if (!$a) {
        n3s_error('指定のIDのアプリがありません', 'IDのエラー');
        exit;
    }
    // 管理者キーを確認する
    if (n3s_is_admin()) {
        // ok
    } else {
        n3s_error('通報更新失敗', '管理者のみが更新できます。');
        exit;
    }
    // 通報リセット
    $time = time();
    $db->query("UPDATE apps SET bad=$bad_value,mtime=$time WHERE app_id=$app_id");
    // 情報
    n3s_template_fw('basic.html', [
    'contents' => "{$app_id} の通報を {$bad_value}に変更しました。",
  ]);
}

function randomStr($length = 8)
{
    return substr(bin2hex(random_bytes($length)), 0, $length);
}

function n3s_action_save_verup_0_7()
{
    $apps = db_get('SELECT app_id, material_id FROM apps ORDER BY app_id', [], 'main');
    foreach ($apps as $app) {
        $app_id = $app['app_id'];
        $m_id = $app['material_id'];
        if ($app_id == $m_id) {
            continue;
        }
        $r = db_get1('SELECT * FROM materials WHERE material_id=?', [$m_id], 'material');
        if (!$r) {
            continue;
        }
        $body = $r['body'];
        $dbname = n3s_getMaterialDB($app_id);
        db_insert(
            'INSERT INTO materials (material_id, body)'.
      'VALUES(?,?)',
            [$app_id, $body],
            $dbname
        );
        db_exec('UPDATE apps SET material_id=? WHERE app_id=?', [$app_id,$app_id]);
        echo "($app_id: $m_id)\n";
    }
    echo "ok.\n";
    exit;
}
