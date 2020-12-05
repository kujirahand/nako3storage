<?php
include_once dirname(__FILE__) . '/save.inc.php';

function n3s_web_show()
{
    $a = n3s_show_get();
    n3s_template_fw('show.html', $a);
}

function n3s_api_show()
{
    $a = n3s_show_get();
    unset($a['access_key']);
    unset($a['editkey']);
    unset($a['material_id']);
    n3s_api_output($a['result'], $a);
}

function n3s_show_get()
{
    global $n3s_config;
    if (empty($n3s_config['app_id'])) {
      $app_id = 0;
    } else {
      $app_id = intval($n3s_config['app_id']);
    }
    $db = n3s_get_db();
    if ($app_id > 0) {
        $sql = "SELECT * FROM apps WHERE app_id=$app_id";
        $a = array();
        $a = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            $a = array('result' => false);
        } else {
            $a['result'] = true;
        }
    } else {
        $a = array('result' => false);
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
    // edit link
    $a['editlink'] = n3s_getURL($app_id, 'save', array("rewrite"=>"yes"));
    $a['mtime_nako3storage_show'] = filemtime($n3s_config['dir_template']."/nako3storage_show.js");

    // params
    n3s_action_save_check_param($a);
    n3s_action_save_load_body($a);
    return $a;
}
