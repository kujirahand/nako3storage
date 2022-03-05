// nako3storage_show.js
// charset=utf-8
// =======================================
// ウィジェットで表示する際に使うファイル
// =======================================

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
// IE対策
var isIE = function() {
  var userAgent = window.navigator.userAgent.toUpperCase();
  var msie = false;
  var msie = false;
  if (userAgent.indexOf('MSIE') >= 0 || userAgent.indexOf('TRIDENT') >= 0) {
    msie = true
  }
  if (msie) {console.log("isIE")}
  return msie
}

// なでしこ本体に登録する関数
function nako3_print(s, sys) {
  // check sys.__printPool
  if (typeof(sys.__printPool) === 'undefined') {
    sys.__printPool = ''
  }
  // clear sys.__printPool
  s = sys.__printPool + s
  sys.__printPool = ""
  var info = $q('#nako3_info')
  if (!info) {
    console.log(s)
    return
  }
  // textarea?
  if (info.tagName.toUpperCase() == 'TEXTAREA') {
    info.value += to_html(s, false) + '\n'
  } else {
    info.innerHTML += to_html(s, true) + '<br/>'
  }
  info.style.display = 'block'
}
function nako3_clear(s) {
  $q('#nako3_info', function (e) { 
    if (e.tagName.toUpperCase() == 'TEXTAREA') {
      e.value ='' 
    } else {
      e.innerHTML = ''
    }
  })
  $q('#nako3_error', function (e) {
    e.innerHTML =''
    e.style.display = 'none'
  })
  $q('#nako3_output', function (e) {
    e.innerHTML = '' 
    e.style.display = 'none'
  })
  $q('#nako3_canvas', function (canvas) {
    const ctx = canvas.getContext('2d')
    ctx.clearRect(0, 0, canvas.width, canvas.height)
  })
  $q('#nako3_div', function (e) {
    e.innerHTML =''
  })
  // プラグインのクリア処理
  if (navigator.nako3 && navigator.nako3.clearPlugins) {
    navigator.nako3.clearPlugins()
  }
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
    navigator.nako3.setFunc("表示", [['の', 'を', 'と']], nako3_print, true)
    navigator.nako3.setFunc("表示ログクリア", [], nako3_clear, true)
  }
  if (verInt >= 3021) {
    navigator.nako3.setFunc("表示", [['の', 'を', 'と']], nako3_print)
    navigator.nako3.setFunc("表示ログクリア", [], nako3_clear)
  } else {
    navigator.nako3.setFunc("表示", nako3_print)
    navigator.nako3.setFunc("表示ログクリア", nako3_clear)
  }
  // コードを取得する
  var code = getValue()
  // 空なら実行しない
  if (code == '') {return}
  // 万が一のためにコードをlocalStorageに保存
  localStorage['nako3storage_temp'] = code
  
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
    "カメ全消去;\n"
  // プログラムを実行
  try {
    runbox.style.display = 'block'
    nako3_clear();

    // ページ内にエディタが存在してかつバージョンが3.1.19以上ならeditor.runを使える
    // 但しmsieであればeditor.runを使わない (#61)
    if (editorObjects && verInt >= 3119 && useAce) {
      const logger = editorObjects.run({ 
        'preCode': preCode, 
        'outputContainer': document.getElementById('nako3_output') || undefined
      }).logger
      logger.addListener('error', function (data) { if (data.level === 'error') { runCount = 0 } }) // エラーが飛んだらrunCountを0に戻す
      runCount++ // 正しく実行した回数をチェック
    } else {
      document.getElementById('nako3_output').style.display = 'none'
      const nako3 = navigator.nako3
      if (isIE() || !nako3.loadDependencies) {
        if (nako3.runReset) {
          nako3.runReset(preCode + code, 'main', preCode);
        } else {
          navigator.nako3.run(preCode + code);
        }
      } else {
        const logger = nako3.replaceLogger()
        logger.addListener('info', (html) => { nako3_print(html) })
        const promise = nako3.loadDependencies(preCode + code, 'main', preCode)
          .then(()=>{
            nako3.runReset(preCode + code, 'main', preCode)
          })
      }
      runCount++ // 正しく実行した回数をチェック
    }
  } catch (e) {
    showError(e.message)
    console.log(e);
  }
}
function showError(msg) {
  const div = $q('#nako3_error')
  if (div) {
    div.style.display = 'block'
    div.innerHTML = to_html(msg, true)
  } else {
    console.error(msg)
  }
}

//--------------------------
// run and clear
const runButton = document.getElementById("runButton")
const clearButton = document.getElementById("clearButton")
const runbox = document.getElementById('runbox')
runButton.onclick = runButtonOnClick
clearButton.onclick = nako3_clear


