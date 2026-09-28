#!/bin/bash
SCR_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP=/usr/local/php/8.5/bin/php

cd $SCR_PATH
# counter
$PHP $SCR_PATH/scripts/image_count.php
$PHP $SCR_PATH/scripts/app_count.php
# gemini api
$PHP $SCR_PATH/scripts/comment_audit.php

# cdn download counter
echo "--- CDN DOWNLOAD COUNT ---"
$PHP $SCR_PATH/scripts/cdn_download_count.php

# update nadesiko3hub (毎朝2時台のみ実行)
if [ "$(date +%H)" = "02" ]; then
    $PHP $SCR_PATH/scripts/nadesiko3hub_sync.php
    git add .
    git commit -a -m "backup $(date +'%Y-%m-%d')"
    git push
fi

