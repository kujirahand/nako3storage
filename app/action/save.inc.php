<?php

function n3s_api_save() {
  n3s_api_output(false, ["msg" => 'API経由のアクセスは停止中です。']);
}

function n3s_web_save() {
  // check mode
  $mode = empty($_GET['mode']) ? '' : $_GET['mode'];
  switch ($mode) {
    case 'edit':
      n3s_action_save_data($_POST, 'web');
      return;
    case 'delete':
      n3s_action_save_delete($_POST, 'web');
      return;
    case 'reset_bad':
      n3s_action_save_reset_bad($_POST, 'web');
      return;
  }
  // show form
  $app_id = intval($_GET['page']);
  $a = array();
  if ($app_id > 0) {
    n3s_web_save_check($app_id, $a);
  }
  n3s_action_save_check_param($a, FALSE);
  // rewrite ... show.html ページで実行したプログラムを保存するか？
  $a['rewrite'] = empty($_GET['rewrite']) ? 'no' : 'yes';
  // load material
  if ($a['rewrite'] === 'no') {
    n3s_action_save_load_body($a);
  }
  // ログイン情報を反映させる
  if ($app_id == 0 && n3s_is_login()) {
    $user = n3s_get_login_info();
    $a['user_id'] = $user['user_id'];
    $a['author'] = $user['name'];
  }
  $a['presave'] = 'no';
  $a['edit_token'] = n3s_getEditToken();
  n3s_template_fw('save.html', $a);
}

function n3s_web_save_check($app_id, &$a) {
  global $n3s_config;
  $db = n3s_get_db();
  $msg = '';
  $a = $db->query("SELECT * FROM apps WHERE app_id=$app_id")->fetch();
  if (!$a) {
    n3s_jump(0, 'save'); // 新規保存のページを表示
    exit;
  }
  if (!n3s_is_login()) {
    n3s_error('ログインが必要', '編集するにはログインしてください。');
    exit;
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

function n3s_action_save_post_by_web() {
  n3s_action_save_data($_POST);
}

function n3s_action_save_load_body(&$a) {
  $material_id = intval(isset($a['material_id']) ? $a['material_id'] : 0);
  if ($material_id > 0) {
    $material_db = n3s_get_db('material');
    $m = $material_db->query("SELECT * FROM materials WHERE material_id=$material_id")->fetch();
    $a['body'] = $m['body'];
  } else {
    $a['body'] = '';
  }
}

function n3s_check_field_size(&$a) {
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


function n3s_action_save_check_param(&$a, $check_error = FALSE) {
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
  $a['is_private'] = isset($a['is_private']) ? intval($a['is_private']) : 0;
  $a['ref_id'] = isset($a['ref_id']) ? intval($a['ref_id']) : -1;
  $a['canvas_w'] = isset($a['canvas_w']) ? intval($a['canvas_w']) : 300;
  $a['canvas_h'] = isset($a['canvas_h']) ? intval($a['canvas_h']) : 300;
  $a['user_id'] = isset($a['user_id']) ? intval($a['user_id']) : 0;
  $a['access_key'] = isset($a['access_key']) ? $a['access_key'] : '';
  $a['custom_head'] = isset($a['custom_head']) ? $a['custom_head'] : '';
  $a['edit_token'] = isset($a['edit_token']) ? $a['edit_token'] : '';
  // check params
  if (!$check_error) { return; }
  if ($a['body'] == '') {
      throw new Exception('プログラムが空です。');
  }
  if (strlen($a['body']) < 30) {
      throw new Exception('プログラムが30文字以下です');
  }
}

// save
function n3s_action_save_data($data, $agent = 'web') {
  global $n3s_config;
  // セキュリティ対策のためAPI経由での保存を禁止した(#51)
  if ($agent == 'api') {
    n3s_api_output(false, array("msg"=>"You could not save from API access."));
    return;
  }
  try {
    $app_id = n3s_action_save_data_raw($data, $agent);
    if ($agent === 'api') {
      n3s_api_output(true, array("msg"=>"ok", "app_id"=>$app_id));
      return;
    } else {
      $url = $n3s_config['baseurl']."/id.php?{$app_id}";
      header("location: $url");
    }
  } catch(Exception $e) {
    n3s_error("保存に失敗", $e->getMessage());
    return;
  }
}

function n3s_action_save_data_raw($data, $agent) {
  global $n3s_config;

  $is_admin = n3s_is_admin();
  $user = n3s_get_login_info();
  $db = n3s_get_db();
  $app_id = n3s_get_config('page', 0);
  $a = $data;
  $b = array();
  $a['app_id'] = $app_id;
  n3s_action_save_check_param($a, TRUE);
  $a['ip'] = $_SERVER['REMOTE_ADDR'];

  // CSRF対策
  if (!n3s_checkEditToken()) {
    throw new Exception(
      '保存に失敗しました。別のページを開いていれば閉じてください。改めて保存ボタンをクリックしてください。');
  }

  // カスタムヘッダのチェック
  if (!check_custom_head($a, $err)) {
    throw new Exception("カスタムヘッダに指定したJavaScriptが許可されていません。".$err);
  }

  if ($app_id > 0) {
    // check editkey
    $b = $db->query('SELECT * FROM apps WHERE app_id='.$app_id)->fetch();
    if (!$b) throw new Exception('app_idが不正です。');
    $b_user_id = $b['user_id'];
    // admin?
    if (!$is_admin) {
      if ($b_user_id > 0) {
        $user_id = n3s_get_user_id();
        if ($user_id != $b_user_id) {
          throw new Exception('他人の投稿です。自分の投稿しか編集できません！');
        }
      }
    }
  }
  $a['mtime'] = time();
  $ph = null;
  // 新規投稿の場合
  if ($app_id == 0) {
    $a['ctime'] = time();
    // ログインしていれば強制的にuser_idを書き換える
    if (n3s_is_login()) {
      $a['user_id'] = n3s_get_user_id();
      $a['author'] = $user['name'];
    }
    // save material
    $db_material = n3s_get_db('material');
    $mp = $db_material->prepare(
      'INSERT INTO materials (body) VALUES (?)');
    $mp->execute(array($a['body']));
    $material_id = $db_material->lastInsertId();
    $sql = <<< EOS
INSERT INTO apps (
  title, author, email, url, memo,
  canvas_w, canvas_h,
  material_id, version, nakotype, tag,
  is_private,
  user_id, access_key, custom_head,
  ref_id, ip, ctime, mtime
) VALUES (
  :title, :author, :email, :url, :memo,
  :canvas_w, :canvas_h,
  :material_id, :version, :nakotype, :tag,
  :is_private,
  :user_id, :access_key, :custom_head,
  :ref_id, :ip, :ctime, :mtime
)
EOS;
    $ph = $db->prepare($sql);
    $ph->execute(array(
      ":title"      => $a['title'],
      ":author"     => $a['author'],
      ":url"        => $a['url'],
      ":email"      => $a['email'],
      ":memo"       => $a['memo'],
      ":material_id" => $material_id,
      ":version"    => $a['version'],
      ":nakotype"   => $a['nakotype'],
      ":tag"        => $a['tag'],
      ":is_private" => $a['is_private'],
      ":user_id"    => $a['user_id'],
      ":access_key" => $a['access_key'],
      ":custom_head"=> $a['custom_head'],
      ":ref_id"     => $a['ref_id'],
      ":canvas_w"   => $a['canvas_w'],
      ":canvas_h"   => $a['canvas_h'],
      ":ip"         => $a['ip'],
      ":ctime"      => $a['ctime'],
      ":mtime"      => $a['mtime'],
    ));
    $app_id = $db->lastInsertId();
    // update material_id
    $mp = $db_material->prepare(
      'UPDATE materials SET  app_id=? WHERE material_id=?');
    $mp->execute(array($a['body'], $material_id));
  } else {
    $sql = <<< EOS
UPDATE apps SET
  title=:title, author=:author, email=:email, url=:url, memo=:memo,
  canvas_w=:canvas_w, canvas_h=:canvas_h, access_key=:access_key,
  version=:version, is_private=:is_private, custom_head=:custom_head,
  ref_id=:ref_id, ip=:ip, mtime=:mtime
WHERE app_id=:app_id;
EOS;
    $ph = $db->prepare($sql);
    $ph->execute(array(
      ":title"      => $a['title'],
      ":author"     => $a['author'],
      ":url"        => $a['url'],
      ":email"      => $a['email'],
      ":memo"       => $a['memo'],
      ":canvas_w"   => $a['canvas_w'],
      ":canvas_h"   => $a['canvas_h'],
      ":version"    => $a['version'],
      ":is_private" => $a['is_private'],
      ":ref_id"     => $a['ref_id'],
      ":canvas_w"   => $a['canvas_w'],
      ":canvas_h"   => $a['canvas_h'],
      ":ip"         => $a['ip'],
      ":mtime"      => $a['mtime'],
      ":app_id"     => $a['app_id'],
      ":access_key" => $a['access_key'],
      ":custom_head"=> $a['custom_head'],
      // editkey は更新しない
    ));
    // update body
    $db_material = n3s_get_db('material');
    $mp = $db_material->prepare(
      'UPDATE materials SET body=? WHERE material_id=?');
    $mp->execute(array($a['body'], $b['material_id']));
  }
  // saved
  return $app_id;
}

function n3s_action_save_delete($params) {
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
  } else if ($user_id == $a_user_id && $user_id > 0) {
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

function n3s_action_save_reset_bad($params) {
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

function randomStr($length = 8) {
    return substr(bin2hex(random_bytes($length)), 0, $length);
}

// カスタムヘッダ
function check_custom_head(&$a, &$err) {
  // (ex) $a['custom_head'] = '<script src="a.js" integrity="abcd" crossorigin="anonymous"></script>'."\n".'<script src="b.js"></script>';
  $err = '';
  $head = $a['custom_head'];
  if ($head === '') {
    return TRUE;
  }
  // コメント一覧を得る
  $comment = "";
  $i = preg_match_all('|<!--(.*?)-->|', $head, $m);
  if ($i) {
    $comment = trim(implode("\n", $m[0]))."\n";
  }
  // スクリプト一覧を得る
  $i = preg_match_all('|<script [^>]+>|', $head, $m);
  if (!$i) {
    $err = '利用できるのは<script>タグのみです。';
    return FALSE;
  }
  // スクリプトタグを解析する
  $scr_res = [];
  $scr_list = $m[0];
  foreach ($scr_list as $script) {
    $script = str_replace("'", '"', $script);
    $i = preg_match_all('|([a-zA-Z]+)\="([^"]+)"|', $script, $m);
    if ($i) {
      $res = [];
      foreach ($m[1] as $n => $key) {
        $res[$key] = $m[2][$n];
      }
      $src = isset($res["src"]) ? $res["src"] : '';
      $integrity = isset($res["integrity"]) ? $res["integrity"] : '';
      $crossorigin = isset($res["crossorigin"]) ? $res["crossorigin"] : '';
      if (!$src) {
        $err = "scriptタグにはsrc属性が必要です。外部スクリプトの取り込みのみ許可されます。";
        return FALSE;
      }
      // srcに指定するURLのチェック (#51)
      if (!preg_match('|^https://nadesi.com/v3/cdn.php\?|', $src)) {
        if (!preg_match('|^https://cdn.jsdelivr.net/npm/chart.js@|', $src)) {
          $err = "カスタムヘッダに指定できるスクリプトのsrcはなでしこのCDNに制限されています。";
          return FALSE;
        }
      }
      $rr = [
        "src" => $src, 
        "integrity" => $integrity, 
        "crossorigin" => $crossorigin
      ];
      if (strpos($script, "defer") !== FALSE) {
        $rr["_sync"] = "defer";
      } else if (strpos($script, "async") !== FALSE) {
        $rr["_sync"] = "async";
      } else {
        $rr["_sync"] = "";
      }
      $scr_res[] = $rr;
    }
  }
  // タグを再構築
  $script = "";
  foreach ($scr_res as $r) {
    $src = $r['src'];
    $sync = $r['_sync'];
    $integrity = $r['integrity'];
    $crossorigin = $r['crossorigin'];

    $script .= "<script {$sync} src=\"{$src}\"";
    if ($integrity != '') {
      $script .= " integrity=\"{$integrity}\"";
    }
    if ($crossorigin != '') {
      $script .= " crossorigin=\"{$crossorigin}\"";
    }
    $script .= "></script>\n";
  }
  // 結果を指定
  $a['custom_head'] = $comment.$script;
  return TRUE;
}
