<?php

function n3s_api_save() {
  // post?
  if (!isset($_POST['body'])) {
    n3s_api_output(false, array(
      "msg" => 'データがありません。POSTメソッドで送信してください。')
    );
    exit;
  }
  n3s_action_save_data($_POST, 'api');
  return;
}

function n3s_web_save() {
  // save?
  if (isset($_POST['body'])) {
    n3s_action_save_data($_POST, 'web');
    return;
  }

  // show form
  $app_id = intval($_GET['page']);
  $a = array();
  if ($app_id > 0) {
    n3s_web_save_check($app_id, $a);
  }
  n3s_action_save_check_param($a);
  $a['rewrite'] = empty($_GET['rewrite']) ? 'no' : 'yes';
  // load material
  if ($a['rewrite'] === 'no') {
    n3s_action_save_load_body($a);
  }
  //
  n3s_template_fw('save.html', $a);
}

function n3s_web_save_check($app_id, &$a) {
  global $n3s_config;
  $db = n3s_get_db();
  $a = $db->query("SELECT * FROM apps WHERE app_id=$app_id")->fetch();
  if (!$a) {
    n3s_jump(0, 'save');
    exit;
  }
  $postkey = isset($_POST['editkey']) ? $_POST['editkey'] : '';
  $postkey_hash = n3s_hash_editkey($postkey);
  if ($a['editkey'] === $postkey_hash || $postkey === $n3s_config['admin_password']) {
    // ok
  } else {
    $opt = array();
    if (isset($_GET['rewrite'])) {
      $opt['rewrite'] = $_GET['rewrite'];
    }
    $url = n3s_getURL($app_id, 'save', $opt);
    $msg = ($postkey !== '') ? '<span class="error">キーが違います。</span>' : '';
    $inputkey = <<< EOS
      <p>編集キーを入力してください。</p>
      <form action='$url' method='post'>
        <input type='password' name='editkey' id="editkey" />
        <input type='submit' value='編集' />
        <p>{$msg}</p>
      </form>
      <script>
        const key = 'n3s_save_editkey'
        if (localStorage[key]) {
          document.getElementById('editkey').value = localStorage[key]
        }
      </script>
EOS;
    n3s_template('basic', array('contents' => $inputkey));
    exit;
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

function n3s_action_save_check_param(&$a) {
  global $n3s_config;
  // check size & trim data
  foreach ($a as $k => &$v) {
    if (isset($v) && is_string($v)) {
      $v = trim($v);
      if ($k == 'body' && strlen($v) > $n3s_config['size_source_max']) {
        throw new Exception('プログラムが最大文字数を超えています。');
      }
      else if (strlen($v) > $n3s_config['size_field_max']) {
        throw new Exception('フィールドが最大文字数を超えています。');
      }
    }
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
  $a['editkey'] = isset($a['editkey']) ? $a['editkey'] : '';
  $a['ip'] = isset($a['ip']) ? $a['ip'] : '';
  $a['is_private'] = isset($a['is_private']) ? intval($a['is_private']) : 0;
  $a['ref_id'] = isset($a['ref_id']) ? intval($a['ref_id']) : -1;
}

// save
function n3s_action_save_data($data, $agent = 'web') {
  global $n3s_config;
  try {
    $app_id = n3s_action_save_data_raw($data, $agent);
    if ($agent === 'api') {
      n3s_api_output(true, array("msg"=>"ok", "app_id"=>$app_id));
      return;
    } else {
      $url = $n3s_config['baseurl']."/show.php?app_id={$app_id}";
      header("location: $url");
    }
  } catch(Exception $e) {
    if ($agent == "api") {
      n3s_api_output(false, array("msg"=>$e->getMessage()));
      return;
    }
    // throw $e;
    n3s_error("保存に失敗", $e->getMessage());
    return;
  }
}

function n3s_action_save_data_raw($data, $agent) {
  global $n3s_config;

  $db = n3s_get_db();
  $app_id = intval($_GET['page']);
  $a = $data;
  $b = array();
  $a['app_id'] = $app_id;
  n3s_action_save_check_param($a);
  $a['ip'] = $_SERVER['REMOTE_ADDR'];
  if ($app_id > 0) {
    // check editkey
    $b = $db->query('SELECT * FROM apps WHERE app_id='.$app_id)->fetch();
    if (!$b) throw new Exception('app_idが不正です。');
    $a_editkey = n3s_hash_editkey($a['editkey']);
    $b_editkey = $b['editkey'];
    // admin?
    if ($n3s_config['admin_password'] === $a['editkey']) {
      // ok
    }
    else if ($a_editkey === $b_editkey) {
      // ok
    } else {
      throw new Exception('編集キーが違います。');
    }
  }
  $a['editkey'] = n3s_hash_editkey($a['editkey']);
  $a['mtime'] = time();
  $ph = null;
  if ($app_id == 0) {
    $a['ctime'] = time();
    // save material
    $db_material = n3s_get_db('material');
    $mp = $db_material->prepare(
      'INSERT INTO materials (body) VALUES (?)');
    $mp->execute(array($a['body']));
    $material_id = $db_material->lastInsertId();
    $sql = <<< EOS
INSERT INTO apps (
  title, author, email, url, memo,
  material_id, version, nakotype, tag,
  editkey, is_private,
  ref_id, ip, ctime, mtime
) VALUES (
  :title, :author, :email, :url, :memo,
  :material_id, :version, :nakotype, :tag,
  :editkey, :is_private,
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
      ":editkey"    => $a['editkey'],
      ":is_private" => $a['is_private'],
      ":ref_id"     => $a['ref_id'],
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
  version=:version, is_private=:is_private,
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
      ":version"    => $a['version'],
      ":is_private" => $a['is_private'],
      ":ref_id"     => $a['ref_id'],
      ":ip"         => $a['ip'],
      ":mtime"      => $a['mtime'],
      ":app_id"     => $a['app_id']
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
