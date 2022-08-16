// file: nako3storage_edit.js
// =======================================
// エディタ＋表示ページで表示する際に使うファイル
// =======================================
// 各種設定オブジェクト
const config = {
  'editLayout': 'UD' // UD 上下 / LR 左右
}
// IE対策
var isIE = function() {
  var userAgent = window.navigator.userAgent.toUpperCase();
  var msie = false;
  if (userAgent.indexOf('MSIE') >= 0 || userAgent.indexOf('TRIDENT') >= 0) {
    msie = true
  }
  if (msie) {console.log("isIE")}
  return msie
}
// AceEditorを使う
let useAce = !isIE() 
let initAce = false
const qid = function (id) { return document.getElementById(id) }
// ライブラリ読み込みに失敗してもgetValueが使えるようにする
var setValue = null
var getValue = function () { return qid('nako3code').value } 

function setupEditor() {
  // loaded?
  if (navigator.nako3 === undefined) {
    setTimeout(function() { setupEditor() }, 100);
    return
  }
  // setup editor
  if (navigator.nako3.setupEditor && useAce) {
    setupAceEditor()
  } else {
    setupTextEditor()
  }
  setupEditorSize() 
}

function setupAceEditor() {
  // get textarea
  const nako3code = document.getElementById('nako3code')
  // textareaをdivで置換してからace editorとして使う。
  const div = document.createElement('div')
  div.id = 'nako3code'
  const parent = nako3code.parentElement
  parent.removeChild(nako3code)
  parent.appendChild(div)
  div.dataset.nako3Resizable = true
  div.textContent = nako3code.value

  editorObjects = navigator.nako3.setupEditor("nako3code");
  if (nako3code.readOnly) {
    editorObjects.editor.setReadOnly(true)
  }
  editorObjects.editor.on("change", function(e) {
    localStorage["n3s_save_body"] = editorObjects.editor.getValue()
  })
  setValue = function(text) { editorObjects.editor.setValue(text) }
  getValue = function() { return editorObjects.editor.getValue() }
  initAce = true
}

function setupTextEditor() {
  // textareaを使う場合
  var body = ''
  if (initAce) {
    // ace と textarea を置換して textarea として使う
    body = getValue()
    const ace = qid('nako3code')
    const txt = document.createElement('textarea')
    txt.id = 'nako3code'
    txt.value = body
    const parent = ace.parentElement
    parent.removeChild(ace)
    parent.appendChild(txt)
  }
  const nako3code = qid('nako3code')
  nako3code.addEventListener("change", function(e) {
    localStorage["n3s_save_body"] = nako3code.value
  })
  setValue = function(text) {
    nako3code.value = text 
  }
  getValue = function() {
    return nako3code.value
  }
  console.log('setupTextEditor')
}


function setupShortcut() {
  // setup shortcut key
  document.addEventListener("keydown", function(e) {
    if (e.isComposing === true) return; // 変換中なら何もしない
    switch (e.code) {
      case 'F9':
        e.preventDefault()
        const runButton = document.getElementById('runButton')
        runButton.click()
        break;
      case 'F10':
        e.preventDefault()
        const clearButton = document.getElementById('clearButton')
        clearButton.click()
        break;
    }
  });
  const recover_btn = document.querySelector('#recover_btn')
  if (recover_btn) {
    recover_btn.onclick = function () {
      if (!localStorage['nako3storage_temp']) {
        alert('直前に何も実行していません。')
        return
      }
      const b = confirm('本当に復元しますか？')
      if (!b) { return }
      setValue(localStorage['nako3storage_temp'])
    }
  }
}

function setupEditorSize() {
  // sizeSwitch
  const sizeSwitch = document.querySelector('#sizeSwitch');
  const nako3code = document.querySelector('#nako3code');
  const full_h = '25em';
  const mini_h = '10em';
  nako3code.style.height = mini_h
  sizeSwitch.onclick = function () {
    if (nako3code.style.height == full_h) {
      nako3code.style.height = mini_h;
      sizeSwitch.innerHTML = '(→大)';
    } else {
      nako3code.style.height = full_h;
      sizeSwitch.innerHTML = '(→小)';
    }
  };
  
  // editSwitch
  const editSwitch = document.querySelector('#editSwitch');
  if (editSwitch) {
    editSwitch.onclick = function () {
      useAce = !useAce
      editSwitch.innerHTML = useAce ? '(→textarea)' : '(→AceEditor)'
      setupEditor()
    };
  }

  // edit-layout-lr
  const editLayoutButton = document.querySelector('#editLayoutButton');
  if (editLayoutButton) {
    const nako3code = qid('nako3code')
    const runbox = qid('runbox')
    editLayoutButton.onclick = function () {
      if (config.editLayout == 'UD') {
        config.editLayout = 'LR'
        editLayoutButton.innerHTML = '(→上下に配置)'
        qid('edit-layout-l').appendChild(nako3code)
        qid('edit-layout-r').appendChild(runbox)
      } else {
        config.editLayout = 'UD'
        editLayoutButton.innerHTML = '(→左右に配置)'
        qid('edit-layout-u').appendChild(nako3code)
        qid('edit-layout-d').appendChild(runbox)
      }
    };
  }


  // isIE
  if (isIE()) {
    sizeSwitch.style.display = 'none';
    editSwitch.style.display = 'none';
  }
}

//--------------------------
// fav
const fav_button = document.getElementById('fav_button')
if (fav_button) { // fav_button が非表示になることがある
  const fav = document.getElementById('fav')
  fav_button.onclick = function () {
    fav_button.disabled = true
    ajax('api.php?page=' + app_id + '&action=fav&q=up', function(txt, r){
      fav_button.disabled = false
      fav.innerHTML = txt
      // プロパティを変更
      let b = fav_button.getAttribute('data-bookmark')
      console.log('bookmark=', b)
      if (b == 1) {
        fav_button.innerHTML = '🌟 気に入った'
        fav_button.setAttribute('data-bookmark', 0)
      } else {
        fav_button.innerHTML = '🌟 解除'
        fav_button.setAttribute('data-bookmark', 1)
      }
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
    var b = confirm('本当に通報しますか？')
    if (!b) {return}
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

// check modified
document.addEventListener("DOMContentLoaded", function() {
  setupEditor();
  setupShortcut();
});


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
    const cv = qid('nako3_canvas')
    cv.width = w
    cv.height = h
  }
}

//--------------------------
// save button
function saveClick(checkLength) {
  if (runCount == 0 && checkLength) {
    var b = confirm(
      "エラーがないか確認してから保存することを推奨しています。\n" +
      "強制的に保存しますか？")
    if (!b) { return }
  }
  if (saveAppData(checkLength)) {
    const cols = ['canvas_w', 'canvas_h', 'version', 'body', 'action_time']
    cols.forEach(key => {
      qid('save_form_' + key).value = localStorage['n3s_' + key]
    });
    qid('n3s_save_form').submit()
  }
}

function saveAppData(checkLength) {
  // 本文データを取得
  let body = ''
  try {
    body = getValue()
    body = body.replace(/^\s+/, '') // trim first
    if (checkLength && body.length < 30) {
      alert('本文が短すぎると保存できません。30文字以上にしてください。')
      return false
    }
  } catch (e) {
    console.log(e)
  }
  // save to localStorage
  localStorage["n3s_action_time"] = (new Date()).getTime()
  localStorage["n3s_save_id"] = app_id
  localStorage["n3s_body"] = body
  localStorage["n3s_canvas_w"] = canvas_w_txt.value
  localStorage["n3s_canvas_h"] = canvas_h_txt.value
  localStorage["n3s_version"] = document.querySelector('#forceNakoVer').value
  return true
}

function changeNakoVersion() {
  if (saveAppData(false)) {
    const ver = document.querySelector('#forceNakoVer').value
    location.href = `index.php?action=edit&page=${app_id}&forceNakoVer=${ver}`;
  }
}

function changeNakoNewVersion() {
  if (saveAppData(false)) {
    location.href = `index.php?action=edit&page=${app_id}&forceNakoVer=${newNakoVersion}`;
  }
}
