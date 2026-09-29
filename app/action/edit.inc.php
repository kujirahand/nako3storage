<?php
include_once __DIR__ . '/save.inc.php';
include_once __DIR__ . '/show.inc.php';

function n3s_web_edit()
{
    $a = n3s_show_get('edit', 'web', true, false);
    $a['noindex'] = true;
    $a['editkey'] = empty($_GET['editkey']) ? '' : $_GET['editkey'];
    // 貯蔵庫API用の署名付きトークン (#194, docs/api.md)。未保存(app_id=0)なら空になる
    $a['api_token'] = n3s_astorage_token_create(intval($a['app_id']), n3s_get_user_id());
    n3s_template_fw('edit.html', $a);
}
