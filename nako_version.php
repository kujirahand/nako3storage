<?php
// ナデシコのバージョン情報を得る
require_once __DIR__.'/nako_version.inc.php';
$ver = NAKO_DEFAULT_VERSION;
list($major, $minor, $patch) = explode('.', $ver.".0.0");
echo <<<EOS
{
  "version": "$ver",
  "major": $major,
  "minor": $minor,
  "patch": $patch
}

EOS;


