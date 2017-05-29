<?php
// header
include dirname(__FILE__).'/parts_html_header.tpl.php';
?>

<?php
// saveform
$msg = '';
$is_private_chk = $is_private ? 'checked="checked"' : '';
if ($rewrite === 'yes') {
  $msg = '<p class="info">必要ならタイトルなど補足情報を入力して画面最下部にある「保存」ボタンを押してください。</p>';
}
echo <<< EOS
<div class="saveform">
  {$msg}
  <form method="POST" action="index.php?{$app_id}&save">
    <p>
      <label>プログラム本体:<br />
      <textarea id="body" name="body">{$body}</textarea>
      </label>
    </p>
    <p>
      <label>タイトル(任意):<br />
      <input name="title" value="{$title}" placeholder="タイトル" />
      </label>
    </p>
    <p>
      <label>制作者名(任意):<br />
      <input id="author" name="author" value="{$author}" placeholder="制作者の名前" />
      </label>
    </p>
    <p>
      <label>連絡先Eメール(任意):<br />
      <input id="email" name="email" value="{$email}" placeholder="メール" />
      </label>
    </p>
    <p>
      <label>URL(任意):<br />
      <input id="url" name="url" value="{$url}" placeholder="関連URL" />
      </label>
    </p>
    <p>
      <label>プログラムの説明(任意):<br />
      <input name="memo" value="{$memo}" placeholder="説明" />
      </label>
    </p>
    <p>
      <label>編集キー(閲覧キー):<br />
      <input id="editkey" name="editkey" type="password" />
      </label>
    </p>
    <p>
      <label>プライベート:<br />
      <input name="is_private" type="checkbox" value="1" $is_private_chk />
      非公開にする
      </label>
    </p>
    <p>
      <label>利用中しているなでしこバージョン:<br />
        <input name="version" type="text" value="{$version}" />
      </label>
    </p>
    <p>
      <input name="ref_id" type="hidden" value="{$ref_id}" />
      <input type="submit" value="保存" />
    </p>
  </form>
</div>
<script type="text/javascript">

// save field
const savekeys = ['author', 'url', 'email', 'editkey']
const elem = {}
for (k of savekeys) {
  elem[k] = document.getElementById(k)
  elem[k].onchange = function(e) {
    const id = e.target.id
    localStorage['n3s_save_' + id] = e.target.value
  }
}

// load field
setTimeout(() => {
  for (k of savekeys) {
    if (elem[k].value !== '') continue
    const v = localStorage['n3s_save_' + k]
    if (v) elem[k].value = v
  }
  // rewrite
  const rewriteMode = '$rewrite'
  if (rewriteMode === 'yes') {
    const body = document.getElementById('body')
    body.value = localStorage['n3s_save_body']
  }
}, 1)

</script>
EOS;
?>

<?php
// footer
include dirname(__FILE__).'/parts_html_footer.tpl.php';
