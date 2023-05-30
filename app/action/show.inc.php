<?php
include_once dirname(__FILE__) . '/save.inc.php';

function n3s_web_show()
{
    $a = n3s_show_get('show', 'web', true, true);
    $page = empty($_GET['page']) ? '0' : $_GET['page'];
    $editkey = empty($_GET['editkey']) ? '' : $_GET['editkey'];
    $sandbox_url = n3s_get_config('sandbox_url', '');
    $a['sandbox_url'] = "{$sandbox_url}index.php?action=widget_frame&page={$page}&mute_title=1&editkey={$editkey}";
    n3s_set_config('page_title', $a['title']);
    n3s_template_fw('show.html', $a);
}

function n3s_api_show()
{
    $a = n3s_show_get('show', 'api');
    unset($a['access_key']);
    unset($a['editkey']);
    unset($a['material_id']);
    n3s_api_output($a['result'], $a);
}

// check private app
function n3s_check_private(&$a, $agent, $action)
{
    if (!$a) {
        return;
    }
    $a['result'] = isset($a['app_id']);
    // プライベートな作品であれば他人には見せない
    $user_id = $a['user_id'];
    $is_private = $a['is_private'];
    $my_user_id = n3s_get_user_id();
    $editkey = isset($_GET['editkey']) ? $_GET['editkey'] : '';
    if ($is_private == 1 || $is_private == 2) {
        // 管理者は見れる
        if (n3s_is_admin()) {
            return; // ok
        } 
        // ユーザー登録なしの場合
        if ($a['user_id'] == 0) {
            if ($a['editkey'] === $editkey) {
                return; // ok
            } else {
                // 入力画面
                n3s_template_fw('show_input_editkey.html', [
                    'app_id' => $a['app_id'],
                    'author' => $a['author'],
                    'run' => empty($_GET['run']) ? 0 : $_GET['run'],
                    'back' => $action,
                ]);
                exit;
            }
        }
        // 自分なら見れる
        if ($user_id == $my_user_id) {
            // ok
            return;
        }
        if ($is_private == 1) {
            n3s_error(
                '非公開の投稿',
                'この投稿は非公開です。'
            );
            exit;
        }
        // 限定公開の場合
        if ($is_private == 2) {
            if ($a['editkey'] === $editkey) {
                return; // ok
            }
            // 入力画面
            n3s_template_fw('show_input_editkey.html', [
                'app_id' => $a['app_id'],
                'author' => $a['author'],
                'run' => empty($_GET['run']) ? 0 : $_GET['run'],
                'back' => $action,
        ]);
            exit;
        }
        n3s_error(
            'この投稿は非公開です',
            'この投稿は非公開です。'
        );
        exit;
    }
}

/**
 * プログラムに関する情報を取得する (action/edit などからも使われる)
 */
function n3s_show_get($action, $agent, $useEditor = true, $readonly = true)
{
    global $n3s_config;

    // check param app_id and page
    $page = empty($_GET['page']) ? '' : $_GET['page'];
    $app_id = 0; // new post
    if (preg_match('/^[0-9]+$/', $page) || $page == '' || $page == 'new') {
        // number
        $app_id = $_GET['app_id'] = intval($page);    
    } else {
        // app_name
        $r = db_get1('SELECT app_id FROM apps WHERE app_name=? LIMIT 1', [$page]);
        if ($r) {
            $app_id = $r['app_id'];
            $app_name = $page;
            $_GET['page'] = $app_id; // 差し替える
        } 
    }
    $n3s_config['app_id'] = $app_id;
    // IE対策のためmsieパラメータをセット
    $msie = false;
    $useragent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $agent = strtolower($useragent);
    if (strstr($agent, 'trident') || strstr($agent, 'msie')) {
        $msie = true;
    }
    // 強制的になでしこのバージョンを変更するか(#101)
    $forceNakoVer = empty($_GET['forceNakoVer']) ? '' : trim($_GET['forceNakoVer']);
    if ($forceNakoVer) {
        // check version format '*.*.*'
        if (! preg_match('#^[0-9]+\.[0-9]+\.[0-9]+$#', $forceNakoVer)) {
            $forceNakoVer = '';
        }
    }
    
    // get from database
    $a = ['result' => false];
    $fav = false;
    $my_user_id = n3s_get_user_id();
    if ($app_id > 0) {
        $sql = "SELECT * FROM apps WHERE app_id=$app_id";
        $a = db_get1($sql);
        if (!$a) {
            header("HTTP/1.1 404 Not Found");
            n3s_error('作品が見当たりません。', "id={$app_id}の作品がありません。");
            exit;
        }
        n3s_check_private($a, $agent, $action);
        // bookmarks
        $fav = db_get1('SELECT * FROM bookmarks WHERE app_id=? AND user_id=? LIMIT 1', [$app_id, $my_user_id]);
    }
    $a['bookmark'] = ($fav) ? true : false;
    if ($forceNakoVer) {
        $a['version'] = $forceNakoVer; // 強制的にバージョンを変更(#101)
    }

    // check include url
    $a['baseurl'] = '';
    $nakotype = empty($a['nakotype']) ? 'wnako' : $a['nakotype'];
    $version = empty($a['version']) ? NAKO_DEFAULT_VERSION : $a['version'];
    $a['import_nako'] = '';
    
    // 必ず wnako としてエディタなど取り込む
    $version = preg_replace("/[^0-9.]/", "", $version);
    $ver_a = explode(".", $version.'.0.0');
    $v1 = intval($ver_a[0]); // 3_00_00
    $v2 = intval($ver_a[1]); // 3_99_00
    $v3 = intval($ver_a[2]); // 3_11_99
    $ver = $v1 * 10000 + $v2 * 100 + $v3;
    if ($ver < 30119) { # バージョンが低すぎる場合、v3.1.19にする #89
        $ver = 30119;
        $a['version'] = $version = '3.1.19';
    }
    // WebWorker対応のため必ずローカルのcdn.phpを使う
    $baseurl = "cdn.php?v={$version}&f=";
    $a['baseurl'] = $baseurl;
    // plugins
    $js_a = []; // for nako3
    $js_e = []; // for ace editor
    // add wanko3.js
    $js_a[] = "<script defer src=\"$baseurl/release/wnako3.js\"></script>";

    // 3.1.17以上ならace editor用のHTMLタグを追加する。
    if ($ver >= 30117 && $useEditor && (!$msie)) {
        if (!isset($a['extra_header_html'])) {
            $a['extra_header_html'] = '';
        }
        $a['extra_header_html'] .= "<link rel=\"stylesheet\" href=\"$baseurl/src/wnako3_editor.css\">";
        $js_e[] = '<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ace.js" integrity="sha512-GZ1RIgZaSc8rnco/8CXfRdCpDxRCphenIiZ2ztLy3XQfCbQUSCuk8IudvNHxkRA3oUg6q0qejgN/qqyG1duv5Q==" crossorigin="anonymous"></script>';
        $js_e[] = '<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ext-language_tools.min.js" integrity="sha512-8qx1DL/2Wsrrij2TWX5UzvEaYOFVndR7BogdpOyF4ocMfnfkw28qt8ULkXD9Tef0bLvh3TpnSAljDC7uyniEuQ==" crossorigin="anonymous"></script>';
        $js_e[] = '<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ext-options.min.js" integrity="sha512-oHR+WVzBiVZ6njlMVlDDLUIOLRDfUUfRQ55PfkZvgjwuvGqL4ohCTxaahJIxTmtya4jgyk0zmOxDMuLzbfqQDA==" crossorigin="anonymous"></script>';
        $js_e[] = '<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ext-code_lens.min.js" integrity="sha512-gsDyyKTnOmSWRDzUbpYcPjzVsEyFGSWeWefzVKvbMULPR2ElIlKKsOtU3ycfybN9kncZXKLFSsUiG3cgJUbc/g==" crossorigin="anonymous"></script>';
    }

    // add other plugins
    $pname_list = [
        'plugin_turtle',
        'plugin_csv',
        'plugin_markup',
        'plugin_kansuji',
        'plugin_caniuse',
        'plugin_webworker'
    ];
    if ($ver < 30231) { // 3.2.31未満であれば必要
        $pname_list[] = 'plugin_datetime';
    }
    if (30105 > $ver) {
        $pname_list = array_slice($pname_list, 0, 1);
    } elseif (30109 > $ver) {
        $pname_list = array_slice($pname_list, 0, 4);
    }
    if (30220 <= $ver) {
        $pname_list[] = 'nako_gen_async';
    }
    foreach ($pname_list as $p) {
        $src = "{$baseurl}release/{$p}.js";
        $js_a[] = "<script defer src=\"$src\"></script>";
    }
    // add Chart.js
    // $js_a[] = "<script defer src=\"${baseurl}demo/js/chart.js@3.2.1/chart.min.js\" integrity=\"sha256-uVEHWRIr846/vAdLJeybWxjPNStREzOlqLMXjW/Saeo=\" crossorigin=\"anonymous\"></script>";
    // 古いバージョンだとJSを含んでいないので...
    $js_a[] = "<script defer src=\"https://cdn.jsdelivr.net/npm/chart.js@3.2.1/dist/chart.min.js\" integrity=\"sha256-uVEHWRIr846/vAdLJeybWxjPNStREzOlqLMXjW/Saeo=\" crossorigin=\"anonymous\"></script>";
    // add
    $a['import_nako'] = implode("\n", $js_a);
    $a['import_editor'] = implode("\n", $js_e);
    
    // check author
    $url = empty($a['url']) ? '' : $a['url'];
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = '';
    }
    $a['url'] = $url;
    if (empty($a['bad'])) { $a['bad'] = 0; }
    // get link url
    $a['newNakoVersion'] = NAKO_DEFAULT_VERSION;
    $a['badlink'] = n3s_getURL('about', 'bad');
    $a['mtime_nako3storage_show'] = filemtime($n3s_config['dir_template']."/nako3storage_show.js");
    $a['mtime_nako3storage_edit'] = filemtime($n3s_config['dir_template']."/nako3storage_edit.js");
    if (!isset($a['user_id'])) {
        $a['user_id'] = 0;
    }
    if (!isset($a['ctime'])) {
        $a['ctime'] = 0;
    }
    if (!isset($a['mtime'])) {
        $a['mtime'] = 0;
    }
    if ($a['user_id'] > 0) {
        // ユーザー情報を取得
        $user = db_get1("SELECT * FROM users WHERE user_id=?", [$a['user_id']]);
        $a['profile_url'] = $user['profile_url'];
        $a['screen_name'] = $user['screen_name'];
    } else {
        $a['profile_url'] = 'skin/def/user-icon.png';
        $a['screen_name'] = '';
    }
    $a['my_user_id'] = $my_user_id;
    $n3s_url = $a['n3s_baseurl'] = $n3s_config['baseurl'];
    // widget コード
    $w = isset($a['canvas_w']) ? $a['canvas_w'] : 400;
    $h = isset($a['canvas_h']) ? $a['canvas_h'] : 400;
    if ($w < 50) {
        $w = 400;
    }
    if ($h < 50) {
        $h = 400;
    }
    // for widget
    $w += 32;
    $h += 120; // margin
    $wurl = "$n3s_url/widget.php?$app_id";
    $wurl_run = "$n3s_url/widget.php?$app_id&run=1";
    $sandbox_url = n3s_get_config('sandbox_url', $n3s_url);
    $wurl_run_allow = "$sandbox_url/widget.php?$app_id&run=1&allow=1";
    $a['is_private'] = isset($a['is_private']) ? intval($a['is_private']) : 0;
    $a['widget_url'] = $wurl;
    $a['widget_tag'] = "<iframe width=\"$w\" height=\"$h\" src=\"$wurl\"></iframe>";
    $a['widget_url_run'] = $wurl_run;
    $a['widget_url_run_allow'] = $wurl_run_allow;
    $a['root_url'] = n3s_get_config('baseurl', '');
    $a['url_images'] = n3s_get_config('url_images', '');
    $a['readonly'] = $readonly;
    // for share
    $a['app_id'] = empty($a['app_id']) ? 0 : $a['app_id'];
    $app_name = isset($a['app_name']) ? $a['app_name'] : '';    
    $a['app_name_or_id'] = ($app_name != '') ? $app_name : $a['app_id'];
    $nakotype = isset($a['nakotype']) ? $a['nakotype'] : 'wnako';
    $a['nakotype'] = $nakotype;
    switch ($a['nakotype']) {
        case 'wnako':
        case 'cnako':
            $a['ext'] = '.nako3';
            break;
        case 'text':
            $a['ext'] = '.txt';
            break;
        default:
            $a['ext'] = '.'.$a['nakotype'];
            break;
    }
    // tag
    $a['tag'] = empty($a['tag']) ? '' : $a['tag'];
    $a['tag_link'] = n3s_makeTagLink($a['tag']);
    // params
    n3s_action_save_check_param($a);
    n3s_action_save_load_body($a);
    return $a;
}
