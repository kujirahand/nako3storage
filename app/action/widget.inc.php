<?php
include_once dirname(__FILE__) . '/save.inc.php';
include_once dirname(__FILE__) . '/show.inc.php';

function n3s_web_widget()
{
    $a = n3s_show_get('web');
    n3s_widgetd_check_private($a);
    n3s_template_fw('widget.html', $a);
}

function n3s_api_widget()
{
    n3s_api_output(FALSE, []);
}

// check private app
function n3s_widgetd_check_private(&$a)
{
    if (!$a) { return; }
    // プライベートな作品であれば他人には見せない
    $user_id = $a['user_id'];
    $is_private = $a['is_private'];
    if ($is_private) {
        n3s_error(
            '非公開の投稿',
            'この投稿は非公開です。');
        exit;
    }
}
