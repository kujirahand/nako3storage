<?php
include_once dirname(__FILE__) . '/save.inc.php';

function n3s_web_show()
{
    $a = n3s_show_get();
    n3s_template('show', $a);
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
    n3s_action_save_check_param($a);
    n3s_action_save_load_body($a);
    return $a;
}
