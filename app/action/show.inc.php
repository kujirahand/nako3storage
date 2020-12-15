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
function n3s_check_private(&$a)
{
    if (!$a) { return; }
    $a['result'] = isset($a['app_id']);
    // プライベートな作品であれば他人には見せない
    $user_id = $a['user_id'];
    $is_private = $a['is_private'];
    if ($is_private) {
        // 管理者は見れる
        if (n3s_is_admin()) {
            // ok
        } else {
            // 自分なら見れる
            if ($user_id === $my_user_id) {
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

function n3s_show_get($agent)
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
        n3s_check_private($a);
    }

    // check include url
    $a['baseurl'] = '';
    $nakotype = empty($a['nakotype']) ? 'wnako' : $a['nakotype'];
    $version = empty($a['version']) ? NAKO_DEFAULT_VERSION : $a['version'];
    $a['import_nako'] = '';
    if ($nakotype === "wnako") {
        $version = preg_replace("/[^0-9.]/", "", $version);
        $baseurl = "https://nadesi.com/v3/cdn.php?v=$version&f=";
        $a['baseurl'] = $baseurl;
        // plugins
        $js_a = [];
        // add wanko3.js
        $wnako = "$baseurl/release/wnako3.js";
        $js_a[] = "<script defer src=\"$wnako\"></script>";
        // add other plugins
        $pname_list = ['plugin_csv', 'plugin_datetime', 'plugin_markup', 'plugin_kansuji', 'plugin_turtle'];
        foreach ($pname_list as $p) {
            $src = "{$baseurl}release/{$p}.js";
            $js_a[] = "<script defer src=\"$src\"></script>";
        }
        // add Chart.js
        $js_a[] = "<script defer src='https://cdn.jsdelivr.net/npm/chart.js@2.9.3/dist/Chart.min.js'></script>";
        // add
        $a['import_nako'] = implode("\n", $js_a);
    }
    // check author
    $url = empty($a['url']) ? '' : $a['url'];
    if (!preg_match('/^https?:\/\//', $url)) $url = '';
    $a['url'] = $url;
    // get link url
    $a['editlink'] = n3s_getURL($app_id, 'save', array("rewrite"=>"yes"));
    $a['badlink'] = n3s_getURL('about', 'bad');
    $a['mtime_nako3storage_show'] = filemtime($n3s_config['dir_template']."/nako3storage_show.js");
    if (!isset($a['user_id'])) { $a['user_id'] = 0; }
    if (!isset($a['ctime'])) { $a['ctime'] = 0; }
    if (!isset($a['mtime'])) { $a['mtime'] = 0; }
    if ($a['user_id'] > 0) {
        // ユーザー情報を取得
        $user = db_get1("SELECT * FROM users WHERE user_id=?", [$a['user_id']]);
        $a['profile_url'] = $user['profile_url'];
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
    $w += 32;
    $h += 120; // margin
    $a['widget_tag'] = <<< EOS
<iframe width="$w" height="$h" src="$n3s_url/widget.php?$app_id"></iframe>
EOS;
    // params
    n3s_action_save_check_param($a);
    n3s_action_save_load_body($a);
    return $a;
}
