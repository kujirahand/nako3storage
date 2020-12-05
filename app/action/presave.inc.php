<?php
include_once dirname(__FILE__).'/save.inc.php';

function n3s_web_presave() {
  $a = array();
  if (isset($_POST['body'])) {
    $a = $_POST;
  }
  n3s_action_save_check_param($a);
  $a["rewrite"] = 'no';
  n3s_template_fw('save.html', $a);
}
