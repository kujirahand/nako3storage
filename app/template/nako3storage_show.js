// nako3storage_show.js
// charset=utf-8

// 正しく実行した回数を表す
var runCount = 0;
// querySelectorのショートカット
function $q(query, callback) {
  const el = document.querySelector(query)
  if (!el) {return undefined}
  if (typeof(callback) == 'function') {
    callback(el)
  }
  return el
}
// なでしこ本体に登録する関数
function nako3_print(s) {
  var info = $q('#nako3_info')
  if (!info) {
    console.log(s)
    return
  }
  info.value += to_html(s, false) + '\n'
  info.style.display = 'block'
}
function nako3_clear(s) {
  $q('#nako3_info', function (e) { e.value ='' })
  $q('#nako3_error', function (e) {
    e.innerHTML =''
    e.style.display = 'none'
  })
  $q('#nako3_output', function (e) { e.innerHTML = '' })
  $q('#nako3_canvas', function (canvas) {
    const ctx = canvas.getContext('2d')
    ctx.clearRect(0, 0, canvas.width, canvas.height)
  })
}
function to_html(s, br) {
  s = '' + s
  s = s.replace(/\&/g, '&amp;')
          .replace(/\</g, '&lt;')
          .replace(/\>/g, '&gt;')
  if (br) {
    s = s.replace(/(\r\n|\n|\r)/g, '<br>')
  }
  return s
}
// エディタのUI操作
function runButtonOnClick() { // 実行ボタンを押した時
  // なでしこのバージョンチェック
  var va = nako_version.split(".")
  var verInt = (va[0] * 1000) + (va[1] * 100) + (va[2] * 1)
  console.log('nako.version=' + verInt)
  if (verInt >= 3119) {
    navigator.nako3.setFunc("表示ログクリア", [], nako3_clear, true)
  } else if (verInt >= 3021) {
    navigator.nako3.setFunc("表示", [['の', 'を', 'と']], nako3_print, true)
    navigator.nako3.setFunc("表示ログクリア", [], nako3_clear, true)
  } else {
    navigator.nako3.setFunc("表示", nako3_print)
    navigator.nako3.setFunc("表示ログクリア", nako3_clear)
  }
  // コードを取得する
  var code = getValue()
  // 空なら実行しない
  if (code == '') {return}
  
  // デフォルトコードを追加する
  var div_name = '#nako3_div'
  let preCode =
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
    preCode += "‰\n"
  } else {
    preCode += ";\n"
  }
  // プログラムを実行
  try {
    runbox.style.display = 'block'
    nako3_clear();

    // ページ内にエディタが存在してかつバージョンが3.1.19以上ならeditor.runを使える。
    if (editorObjects && verInt >= 3119) {
      document.getElementById('nako3_output').style.display = 'block'
      const logger = editorObjects.run({ preCode, outputContainer: document.getElementById('nako3_output') || undefined }).logger
      logger.addListener('error', (data) => { if (data.level === 'error') { runCount = 0 } }) // エラーが飛んだらrunCountを0に戻す
      runCount++ // 正しく実行した回数をチェック
    } else {
      document.getElementById('nako3_output').style.display = 'none'
      navigator.nako3.run(preCode + code);
      runCount++ // 正しく実行した回数をチェック
    }
  } catch (e) {
    showError(e.message)
    console.log(e);
  }
}
function showError(msg) {
  const div = $q('#nako3_error')
  div.style.display = 'block'
  div.innerHTML = to_html(msg, true)
}

//--------------------------
// run and clear
const runButton = document.getElementById("runButton")
const clearButton = document.getElementById("clearButton")
const runbox = document.getElementById('runbox')
runButton.onclick = runButtonOnClick
clearButton.onclick = nako3_clear

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
  if (w >= 0 && h >= 0) {
    const cv = $q('#nako3_canvas')
    cv.width = w
    cv.height = h
  }
}

//--------------------------
// save button
function saveClick() {
  if (runCount == 0) {
    alert('一度エラーなしで実行しないと保存できません')
    return
  }
  localStorage["n3s_save_id"] = app_id
  localStorage["n3s_save_body"] = getValue()
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
    if (runCount == 0) {
      alert('最初に実行してください')
      return
    }
    fav_button.disabled = true
    ajax('api.php?page=' + app_id + '&action=fav&q=up', function(txt, r){
      fav.innerHTML = txt
    })
  
  }
  // favの値を取得する --- 現在不使用
  function getFavCount(){
    ajax('api.php?page=' + app_id + '&action=fav', function(txt, r){
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
    ajax('api.php?page=' + app_id + '&action=bad&q=up', function(txt, r){
      bad.innerHTML = txt
    })
  
  }
  setTimeout(function(){
    ajax('api.php?page=' + app_id + '&action=bad', function(txt, r){
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
