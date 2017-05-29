<?php
define("NAKO_DEFAULT_VERSION", "0.0.6");

function n3s_action_save() {
  // agent?
  $agent = isset($_GET['agent']) ? $_GET['agent'] : 'browser';
  // save?
  if (isset($_POST['body'])) {
    n3s_action_save_data($_POST, $agent);
    return;
  }
  // show form
  $app_id = intval($_GET['page']);
  $a = array();
  if ($app_id > 0) {
    $db = n3s_get_db();
    $a = $db->query("SELECT * FROM apps WHERE app_id=$app_id")->fetch();
    if (!$a) {
      n3s_jump(0, 'save');
      exit;
    }
    $postkey = isset($_POST['editkey']) ? $_POST['editkey'] : '';
    $postkey_hash = n3s_hash_editkey($postkey);
    if ($a['editkey'] !== $postkey_hash) {
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
  if (!$a) {
    n3s_action_save_check_param($a);
  }
  $a['rewrite'] = empty($_GET['rewrite']) ? 'no' : 'yes';
  n3s_template('save', $a);
}

function n3s_action_save_post_by_web() {
  n3s_action_save_data($_POST);
}

function n3s_action_save_check_param(&$a) {
  $a['app_id'] = isset($a['app_id']) ? intval($a['app_id']) : 0;
  $a['title'] = empty($a['title']) ? '(無題)' : $a['title'];
  $a['author'] = empty($a['author']) ? '(匿名)' : $a['author'];
  $a['email'] = isset($a['email']) ? $a['email'] : '';
  $a['url'] = isset($a['url']) ? $a['url'] : '';
  $a['nakotype'] = isset($a['nakotype']) ? $a['nakotype'] : 'wnako';
  $a['tag'] = isset($a['tag']) ? $a['tag'] : '';
  $a['memo'] = isset($a['memo']) ? $a['memo'] : '';
  $a['body'] = isset($a['body']) ? $a['body'] : '';
  $a['version'] = isset($a['version']) ? $a['version'] : NAKO_DEFAULT_VERSION;
  $a['editkey'] = isset($a['editkey']) ? $a['editkey'] : '';
  $a['is_private'] = isset($a['is_private']) ? intval($a['is_private']) : 0;
  $a['ref_id'] = isset($a['ref_id']) ? intval($a['ref_id']) : -1;
}

// save (agent=brower/api)
function n3s_action_save_data($data, $agent = 'browser') {
  global $n3s_config;
  try {
    $app_id = n3s_action_save_data_raw($data, $agent);
    if ($browser === 'api') {
      echo json_encode(array(
        "result" => true
      ));
    } else {
      $url = $n3s_config['baseurl']."/index.php?{$app_id}&show";
      header("location: $url");
    }
  } catch(Exception $e) {
    throw $e;
    echo $e->getMessage();
  }
}

function n3s_action_save_data_raw($data, $agent) {
  $db = n3s_get_db();
  $app_id = intval($_GET['page']);
  $a = $data;
  $a['app_id'] = $app_id;
  n3s_action_save_check_param($a);
  $a['ip'] = $_SERVER['REMOTE_ADDR'];
  if ($app_id > 0) {
    // check editkey
    $b = $db->query('SELECT * FROM apps WHERE app_id='.$app_id)->fetch();
    if (!$b) throw new Exception('app_idが不正です。');
    $a_editkey = n3s_hash_editkey($a['editkey']);
    $b_editkey = $b['editkey'];
    if ($a_editkey !== $b_editkey) {
      var_dump($a);
      var_dump($b);
      throw new Exception('編集キーが違います。');
    }
  }
  $a['editkey'] = n3s_hash_editkey($a['editkey']);
  $a['mtime'] = time();
  $ph = null;
  if ($app_id == 0) {
    $a['ctime'] = time();
    $sql = <<< EOS
INSERT INTO apps (
  title, author, email, url, memo,
  body, version, nakotype, tag,
  editkey, is_private,
  ref_id, ip, ctime, mtime
) VALUES (
  :title, :author, :email, :url, :memo,
  :body, :version, :nakotype, :tag,
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
      ":body"       => $a['body'],
      ":version"    => $a['version'],
      ":body"       => $a['body'],
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
  } else {
    $sql = <<< EOS
UPDATE apps SET
  title=:title, author=:author, email=:email, url=:url, memo=:memo,
  body=:body, version=:version, editkey=:editkey, is_private=:is_private,
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
      ":body"       => $a['body'],
      ":version"    => $a['version'],
      ":editkey"    => $a['editkey'],
      ":is_private" => $a['is_private'],
      ":ref_id"     => $a['ref_id'],
      ":ip"         => $a['ip'],
      ":mtime"      => $a['mtime'],
      ":app_id"     => $a['app_id']
    ));
  }
  // saved
  return $app_id;
}
