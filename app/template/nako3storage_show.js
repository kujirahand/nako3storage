// nako3storage_show.js
// charset=utf-8

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
          .replace(/(\r\n|\n|\r)/g, '<br>')
}
function nako3_run() {
  var va = nako_version.split(".")
  var verInt = (va[0] * 1000) + (va[1] * 100) + (va[2] * 1)
  console.log('nako.version=' + verInt)
  if (verInt >= 3021) {
    navigator.nako3.setFunc("表示", [['の', 'を', 'と']], nako3_print)
    navigator.nako3.setFunc("表示ログクリア", [], nako3_clear)
  } else {
    navigator.nako3.setFunc("表示", nako3_print)
    navigator.nako3.setFunc("表示ログクリア", nako3_clear)
  }
  var code = ""
  var code_e = document.getElementById("nako3code")
  if (!code_e) {return}
  if (typeof(editor) == 'string') {
    code = code_e.value
  } else {
    code = editor.getValue()
  }
  var div_name = '#nako3_div'
  const head =
    "F=JS実行(\"(typeof(sys)=='undefined')?'null':typeof sys.__v0['DOM親要素設定']\");" +
    "もし、F=「function」ならば;" + 
    "  『「" + div_name + "」へDOM親要素設定;" +
    "    「" + div_name + "」に「」をHTML設定;』をナデシコ続;" +
    "ここまで。;" + 
    "「#nako3_canvas」へ描画開始;" +
    "カメ描画先=「nako3_canvas」;" +
    "カメ全消去;" +
    "カメ画像URL=「" + baseurl + "/demo/turtle.png」;"
  if (verInt >= 3108) {
    code = head + "‰\n" + code
  } else {
    code = head + ";" + code
  }
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

//--------------------------
// canvas_w * canvas_h
const canvas_w_txt = document.getElementById("canvas_w")
const canvas_h_txt = document.getElementById("canvas_h")
if (canvas_w_txt) {
  canvas_w_txt.onchange = function () { canvas_size_change() }
  canvas_h_txt.onchange = function () { canvas_size_change() }
}
function canvas_size_change() {
  const w = parseInt(canvas_w_txt.value)
  const h = parseInt(canvas_h_txt.value)
  if (w > 0 && h > 0) {
    const cv = nako3_get_canvas()
    cv.width = w
    cv.height = h
  }
}

//--------------------------
// save button
function saveClick() {
  const code_e = document.getElementById("nako3code");
  localStorage["n3s_save_id"] = app_id
  localStorage["n3s_save_body"] = editor.getValue()
  localStorage["n3s_action_time"] = (new Date()).getTime()
  localStorage["n3s_canvas_w"] = canvas_w_txt.value
  localStorage["n3s_canvas_h"] = canvas_h_txt.value
  location.href = editlink
}

//--------------------------
// fav
const fav_button = document.getElementById('fav_button')
if (fav_button) { // fav_button が非表示になることがある
  const fav = document.getElementById('fav')
  fav_button.onclick = function () {
    fav_button.disabled = true
    ajax(`api.php?page=${app_id}&action=fav&q=up`, function(txt, r){
      fav.innerHTML = txt
    })
  
  }
  // favの値を取得する --- 現在不使用
  function getFavCount(){
    ajax(`api.php?page=${app_id}&action=fav`, function(txt, r){
      fav.innerHTML = txt
    })
  }
}

//--------------------------
// 通報(bad)
const bad_button = document.getElementById('bad_button')
const bad = document.getElementById('bad')
if (bad_button) { //  非表示になることがあるので
  bad_button.onclick = function () {
    bad_button.disabled = true
    ajax(`api.php?page=${app_id}&action=bad&q=up`, function(txt, r){
      bad.innerHTML = txt
    })
  
  }
  setTimeout(function(){
    ajax(`api.php?page=${app_id}&action=bad`, function(txt, r){
      bad.innerHTML = txt
    })
  }, 2000)
}

function ajax(url, callback) {
  const req = new XMLHttpRequest();
  req.onreadystatechange = function() {
    if (req.readyState == 4) { // 通信の完了時
      if (req.status == 200) { // 通信の成功時
        callback(req.responseText, req)
      }
    }
  }
  req.open('GET', url)
  req.send()
}
