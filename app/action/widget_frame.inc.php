<?php
// widget は外部から読み込まれるので SAMEORIGIN だと動かない
// for clickjacking
// header('X-Frame-Options: SAMEORIGIN');

include_once dirname(__FILE__) . '/save.inc.php';
include_once dirname(__FILE__) . '/show.inc.php';

function n3s_web_widget_frame()
{
    $a = n3s_show_get('widget', 'web', false);
    n3s_widgetd_check_private($a);
    // run mode?
    $a['run'] = isset($_GET['run']) ? intval($_GET['run']) : 0;
    $a['allow'] = isset($_GET['allow']) ? intval($_GET['allow']) : 0;
    $a['mute_title'] = isset($_GET['mute_title']) ? intval($_GET['mute_title']) : 0;
    $a['mute_name'] = isset($_GET['mute_name']) ? intval($_GET['mute_name']) : 0;
    $tags = isset($a['tag']) ? explode(',', $a['tag']) : [];
    for ($i = 0; $i < count($tags); $i++) { $tags[$i] = trim($tags[$i]); }
    $a['w_noname'] = in_array('w_noname', $tags);
    $a['api_token'] = isset($_GET['api_token']) ? $_GET['api_token'] : '';
    n3s_template_fw('widget.html', $a);
}

function n3s_api_widget()
{
    n3s_api_output(false, []);
}

// check private app
function n3s_widgetd_check_private(&$a)
{
    if (!$a) {
        return;
    }
    // プライベートな作品であれば他人には見せない
    $user_id = $a['user_id'];
    $is_private = $a['is_private'];
    $access_key = isset($_GET['access_key']) ? $_GET['access_key'] : '';
    // 公開
    if ($is_private == 0) {
        return true;
    }
    // 非公開
    if ($is_private == 1) {
        n3s_error(
            '非公開の投稿',
            'この投稿は非公開です。'
        );
        exit;
    }
    // 限定公開
    if ($is_private == 2) {
        if ($a['access_key'] == $access_key) {
            return;
        }
        n3s_error(
            '非公開の投稿',
            'この投稿は非公開です。'
        );
        exit;
    }
}
