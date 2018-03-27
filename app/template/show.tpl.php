<?php
// header
include dirname(__FILE__).'/parts_html_header.tpl.php';
?>

<?php
// 空白をチェック
if (!$title) $title = '(無題)';
if (!$author) $author = '(匿名)';
if (!isset($app_id)) $app_id = 0;
// HTML化
$title = htmlentities($title);
$author = htmlentities($author);
if ($url == '') $url = '';
if (!preg_match('/^https?:\/\//', $url)) $url = '';
if ($url !== '') {
  $author = "<a href='{$url}'>{$author}</a>";
}
$memo = htmlentities($memo);
$body = htmlentities($body);
$editlink = n3s_getURL($app_id, 'save', array("rewrite"=>"yes"));
$import_nako = "";
if ($nakotype === "wnako") {
  $version = preg_replace("/[^0-9.]/", "", $version);
  $baseurl = "https://nadesi.com/v3/$version";
  $src = "$baseurl/release/wnako3.js";
  $src_turtle = "$baseurl/release/plugin_turtle.js";
  $import_nako =
    "<script type='text/javascript' src='$src'></script>".
    "<script type='text/javascript' src='$src_turtle'></script>";
}
$form = <<< EOS
<div class="showblock">
  <p>
    <h3>{$title}</h3>
    <p class="memo">{$memo}</p>
  </p>
  <p>
    <label>プログラム:<br />
    <textarea id="nako3code" name="body">{$body}</textarea>
    </label><br />
    <button id="runButton">実　行</button>
    <button id="clearButton">クリア</button>
    <div id="runbox">
      <div class="nako3row nako3info" id="nako3_info"></div>
      <canvas id='nako3_canvas' width='300' height='300'></canvas>
    </div>
  </p>
  <ul class="showinfo">
    <li>制作者: <span>{$author}</span></li>
    <li>利用バージョン: {$version}</li>
  </ul>
</div>
<div>
  <p><button onclick="saveClick()">保　存</button></p>
</div>
EOS;

$js = <<< EOS
<script type="text/javascript">
const baseurl = "$baseurl"
const app_id = $app_id
//--------------------------
// for nako3
var nako3_get_info = function () {
  return document.getElementById("nako3_info")
}
var nako3_get_canvas = function () {
  return document.getElementById("nako3_canvas")
}
var nako3_print = function (s) {
  var info = nako3_get_info();
  if (!info) {
    console.log(s)
    return
  }
  s = "" + s; // 文字列に変換
  if (s.substr(0, 5) == "[err]") {
    s = s.substr(5)
    s = "<span style='color:red'>" + to_html(s) + "</span>"
    info.innerHTML = s
  } else {
    info.innerHTML += to_html(s) + "<br>"
  }
}
var nako3_clear = function (s) {
  var info = nako3_get_info()
  if (!info) return
  info.innerHTML = ''
  var canvas = nako3_get_canvas()
  if (!canvas) return
  var ctx = canvas.getContext('2d')
  ctx.clearRect(0, 0, canvas.width, canvas.height)
}
function to_html(s) {
  s = '' + s
  return s.replace(/\&/g, '&amp;')
          .replace(/\</g, '&lt;')
          .replace(/\>/g, '&gt;')
          .replace(/\\n/g, '<br>')
}
function nako3_run() {
  var ver = "$version" + ".0.0.0"
  var va = ver.split(".")
  var verInt = (va[0] * 1000) + (va[1] * 100) + (va[2] * 1)
  console.log('nako.version=' + verInt)
  if (verInt >= 3021) {
    navigator.nako3.setFunc("表示", [['の', 'を', 'と']], nako3_print)
    navigator.nako3.setFunc("表示ログクリア", [], nako3_clear)
  } else {
    navigator.nako3.setFunc("表示", nako3_print)
    navigator.nako3.setFunc("表示ログクリア", nako3_clear)
  }
  var code_e = document.getElementById("nako3code");
  if (!code_e) return;
  var code = code_e.value;
  code =
    "「#nako3_canvas」へ描画開始;" +
    "カメ描画先=「#nako3_canvas」;" +
    "カメ全消去;" +
    "カメ画像URL=「" + baseurl + "/demo/turtle.png」;" + code;
  try {
    runbox.style.display = 'block'
    nako3_clear();
    navigator.nako3.run(code);
  } catch (e) {
    nako3_print("[err]" + e.message + "");
    console.log(e);
  }
}
//--------------------------
// run and clear
const runButton = document.getElementById("runButton")
const clearButton = document.getElementById("clearButton")
const runbox = document.getElementById('runbox')
runButton.onclick = nako3_run;
clearButton.onclick = nako3_clear;
runbox.style.display = 'none'
//--------------------------
// save button
function saveClick() {
  const code_e = document.getElementById("nako3code");
  localStorage["n3s_save_id"] = app_id
  localStorage["n3s_save_body"] = code_e.value
  localStorage["n3s_action_time"] = (new Date()).getTime()
  location.href = "$editlink"
}
</script>
{$import_nako}
EOS;

echo $form . "\n" . $js;
?>

<?php
// footer
include dirname(__FILE__).'/parts_html_footer.tpl.php';
