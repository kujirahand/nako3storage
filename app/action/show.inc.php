<?php
include_once dirname(__FILE__) . '/save.inc.php';

function n3s_web_show()
{
    $a = n3s_show_get('web');
    n3s_template_fw('show.html', $a);
}

function n3s_api_show()
{
    $a = n3s_show_get('api');
    unset($a['access_key']);
    unset($a['editkey']);
    unset($a['material_id']);
    n3s_api_output($a['result'], $a);
}

// check private app
function n3s_check_private(&$a, $agent)
{
    if (!$a) { return; }
    $a['result'] = isset($a['app_id']);
    // プライベートな作品であれば他人には見せない
    $user_id = $a['user_id'];
    $is_private = $a['is_private'];
    $my_user_id = n3s_get_user_id();
    if ($is_private) {
        // 管理者は見れる
        if (n3s_is_admin()) {
            // ok
        } else {
            // 自分なら見れる
            if ($user_id == $my_user_id) {
                // ok
            } else {
                $a = [
                    "result" => false,
                    "msg" => '非公開の投稿です。',
                ];
                if ($agent == 'web') {
                    n3s_error(
                        '非公開の投稿',
                        'この投稿は非公開です。');
                    exit;
                }
            }
        }
    }
}

function n3s_show_get($agent, $useEditor = TRUE)
{
    global $n3s_config;

    // check param app_id and page
    $page = empty($_GET['page']) ? 'new' : $_GET['page'];
    $app_id = $_GET['app_id'] = intval(empty($_GET['app_id']) ? $page : 0);
    $n3s_config['app_id'] = $app_id;
    
    $db = n3s_get_db();
    $a = ['result' => false];
    $my_user_id = n3s_get_user_id();
    if ($app_id > 0) {
        $sql = "SELECT * FROM apps WHERE app_id=$app_id";
        $a = db_get1($sql);
        n3s_check_private($a, $agent);
    }

    // check include url
    $a['baseurl'] = '';
    $nakotype = empty($a['nakotype']) ? 'wnako' : $a['nakotype'];
    $version = empty($a['version']) ? NAKO_DEFAULT_VERSION : $a['version'];
    $a['import_nako'] = '';
    if ($nakotype === "wnako") {
        $version = preg_replace("/[^0-9.]/", "", $version);
        $ver_a = explode(".", $version.'.0.0');
        $v1 = intval($ver_a[0]); // 3_00_00
        $v2 = intval($ver_a[1]); // 3_99_00
        $v3 = intval($ver_a[2]); // 3_11_99
        $ver = $v1 * 10000 + $v2 * 100 + $v3;
        $baseurl = "https://nadesi.com/v3/cdn.php?v=$version&f=";
        $a['baseurl'] = $baseurl;
        // plugins
        $js_a = []; // for nako3
        $js_e = []; // for ace editor
        // add wanko3.js
        $js_a[] =
            "<script defer src=\"$baseurl/release/wnako3.js\"></script>";

        // 3.1.17以上ならace editor用のHTMLタグを追加する。
        if ($ver >= 30117 && $useEditor) {
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
            'plugin_datetime',
            'plugin_markup', 
            'plugin_kansuji', 
            'plugin_caniuse',
            'plugin_webworker'
        ];
        if (30105 > $ver) {
            $pname_list = array_slice($pname_list, 0, 1);
        } else if (30109 > $ver) {
            $pname_list = array_slice($pname_list, 0, 4);
        }
        foreach ($pname_list as $p) {
            $src = "{$baseurl}release/{$p}.js";
            $js_a[] = "<script defer src=\"$src\"></script>";
        }
        // add Chart.js and Mocha.js
        $js_a[] = "<script defer src='https://cdn.jsdelivr.net/npm/chart.js@2.9.3/dist/Chart.min.js'></script>";
        $js_e[] = '<script defer src="https://cdnjs.cloudflare.com/ajax/libs/mocha/8.3.0/mocha.min.js" integrity="sha512-LA/TpBXau/JNubKzHQhdi5vGkRLyQjs1vpuk2W1nc8WNgf/pCqBplD8MzlzeKJQTZPvkEZi0HqBDfRC2EyLMXw==" crossorigin="anonymous"></script>';
        // add
        $a['import_nako'] = implode("\n", $js_a);
        $a['import_editor'] = implode("\n", $js_e);
    }
    // check author
    $url = empty($a['url']) ? '' : $a['url'];
    if (!preg_match('/^https?:\/\//', $url)) $url = '';
    $a['url'] = $url;
    // get link url
    $a['editlink'] = n3s_getURL($app_id, 'save', array("rewrite"=>"yes"));
    $a['badlink'] = n3s_getURL('about', 'bad');
    $a['mtime_nako3storage_show'] = filemtime($n3s_config['dir_template']."/nako3storage_show.js");
    $a['mtime_nako3storage_edit'] = filemtime($n3s_config['dir_template']."/nako3storage_edit.js");
    if (!isset($a['user_id'])) { $a['user_id'] = 0; }
    if (!isset($a['ctime'])) { $a['ctime'] = 0; }
    if (!isset($a['mtime'])) { $a['mtime'] = 0; }
    if ($a['user_id'] > 0) {
        // ユーザー情報を取得
        $user = db_get1("SELECT * FROM users WHERE user_id=?", [$a['user_id']]);
        $a['profile_url'] = $user['profile_url'];
        $a['screen_name'] = $user['screen_name'];
    } else {
        $a['profile_url'] = 'skin/def/user-icon.png';
    }
    $a['my_user_id'] = $my_user_id;
    $n3s_url = $a['n3s_baseurl'] = $n3s_config['baseurl'];
    // widget コード
    $w = isset($a['canvas_w']) ? $a['canvas_w'] : 400;
    $h = isset($a['canvas_h']) ? $a['canvas_h'] : 400;
    if ($w < 50) { $w = 400; }
    if ($h < 50) { $w = 400; }
    // for widget
    $w += 32;
    $h += 120; // margin
    $wurl = "$n3s_url/widget.php?$app_id";
    $wurl_run = "$n3s_url/widget.php?$app_id&run=1";
    $a['is_private'] = isset($a['is_private']) ? $a['is_private'] : FALSE;
    if ($a['is_private']) {
        $wurl .= "&access_key=".$a['access_key'];
    }
    $a['widget_url'] = $wurl;
    $a['widget_tag'] = "<iframe width=\"$w\" height=\"$h\" src=\"$wurl\"></iframe>";
    $a['widget_url_run'] = $wurl_run;
    $a['root_url'] = n3s_get_config('baseurl', '');
    $a['url_images'] = n3s_get_config('url_images', ''); 
    // params
    n3s_action_save_check_param($a);
    n3s_action_save_load_body($a);
    return $a;
}
