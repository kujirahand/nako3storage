<?php
// widget は外部から読み込まれるので SAMEORIGIN だと動かない
// for clickjacking
// header('X-Frame-Options: SAMEORIGIN');

// * <iframe>のsandboxを使う (#132)
// widget.inc.php => widget_frame.html [iframe.src=action=widget_frame]
// widget_frame.inc.php => widget.html

function n3s_web_widget()
{
    $a = [];
    // run mode?
    $run = $a['run'] = isset($_GET['run']) ? intval($_GET['run']) : 0;
    $mute_name = $a['mute_name'] = isset($_GET['mute_name']) ? intval($_GET['mute_name']) : 0;
    $page = $a['page'] = isset($_GET['page']) ? intval($_GET['page']) : 0;
    // sandbox
    $sandbox_url = n3s_get_config('sandbox_url', '');
    $a['iframe_url'] = "{$sandbox_url}index.php?action=widget_frame&page={$page}&run={$run}&mute_name={$mute_name}";
    n3s_template_fw('widget_frame.html', $a);
}
