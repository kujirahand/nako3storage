<?php
include_once __DIR__ . '/widget_frame.inc.php';

// * <iframe>のsandboxを使う (#132)
// widget.inc.php => widget_frame.html [iframe.src=action=widget_frame]
// widget_frame.inc.php => widget.html

function n3s_web_widget()
{
    // get from database
    $a = n3s_show_get('widget', 'web', false);
    n3s_widgetd_check_private($a);
    // run mode?
    $run = $a['run'] = isset($_GET['run']) ? intval($_GET['run']) : 0;
    $mute_name = $a['mute_name'] = isset($_GET['mute_name']) ? intval($_GET['mute_name']) : 0;
    $page = $a['page'] = isset($_GET['page']) ? intval($_GET['page']) : 0;
    $editkey = isset($_GET['editkey']) ? $_GET['editkey'] : '';
    // sandbox
    $sandbox_url = n3s_get_config('sandbox_url', '');
    $a['iframe_url'] = "{$sandbox_url}index.php?action=widget_frame&page={$page}&run={$run}&mute_name={$mute_name}&editkey={$editkey}";
    // (互換性のために) 特別扱いする投稿 --- https://bit.ly/3Vpk1RI
    if ($page == 991) {
        $url = $a['iframe_url'];
        header('location:'.$url);
        echo "<html><body><a href='$url'>$url</a>";
        exit;
    }
    // ここまで
    n3s_template_fw('widget_frame.html', $a);
}
