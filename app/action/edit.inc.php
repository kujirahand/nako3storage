<?php
include_once __DIR__ . '/save.inc.php';
include_once __DIR__ . '/show.inc.php';

function n3s_web_edit()
{
    $a = n3s_show_get('edit', 'web', true, false);
    $a['noindex'] = true;
    $a['editkey'] = empty($_GET['editkey']) ? '' : $_GET['editkey'];
    $api_token = $a['api_token'] = n3s_getAPIToken();
    $_SESSION["api_token::$api_token"] = $a["app_id"];
    n3s_template_fw('edit.html', $a);
}
