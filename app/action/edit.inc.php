<?php
include_once dirname(__FILE__) . '/save.inc.php';
include_once dirname(__FILE__) . '/show.inc.php';

function n3s_web_edit()
{
    $a = n3s_show_get('web', TRUE, FALSE);
    n3s_template_fw('edit.html', $a);
}
