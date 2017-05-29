<?php
if (empty($contents)) $contents = 'no contents';

// header
include dirname(__FILE__).'/parts_html_header.tpl.php';
?>

<?php echo $contents ?>

<?php
// footer
include dirname(__FILE__).'/parts_html_footer.tpl.php';
