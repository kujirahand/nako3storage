// file: nako3storage_edit.js

function setupEditor() {
    if (navigator.nako3 === undefined) {
        setTimeout(function() { setupEditor() }, 100);
        return
    }
    const nako3code = document.getElementById("nako3code")
    if (navigator.nako3.setupEditor) {
      // 3.1.17以上ならsetupEditor関数が存在する。
      // textareaをdivで置換してからace editorとして使う。
      const div = document.createElement("div")
      div.id = "nako3code"
      const parent = nako3code.parentElement
      parent.removeChild(nako3code)
      parent.appendChild(div)
      div.dataset.nako3Resizable = "true"
      div.textContent = nako3code.value

      editorObjects = navigator.nako3.setupEditor("nako3code");
      editorObjects.editor.on("change", function(e) {
        localStorage["n3s_save_body"] = editorObjects.editor.getValue()
      })
      setValue = function(text) { editorObjects.editor.setValue(text) }
      getValue = function() { return editorObjects.editor.getValue() }
    } else {
      // 3.1.17未満のときはtextareaで表示する。
      nako3code.addEventListener("change", function(e) {
        localStorage["n3s_save_body"] = nako3code.value
      })
      setValue = function(text) { nako3code.value = text }
      getValue = function(text) { return nako3code.value }
      return
    }
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
}

function setupEditorSize() {
  const sizeSwitch = document.querySelector('#sizeSwitch');
  const nako3code = document.querySelector('#nako3code');
  const full_h = nako3code.style.height;
  const mini_h = '7em';
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

// check modified
  document.addEventListener("DOMContentLoaded", function() {
    setupEditor();
    setupShortcut();
    setupEditorSize();
  });
