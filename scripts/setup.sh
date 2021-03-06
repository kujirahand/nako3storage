#!/usr/bin/env bash

# プロジェクトルートへ
ROOT=$(cd $(dirname $0)/..;pwd)
cd $ROOT
#dataディレクトリに書き込み権限を与える
chmod +w data

#英数字16文字によるadmin passwordを生成し、それをn3s_config.ini.phpに書き込む
echo -e "<?php\n\$n3s_config['admin_password'] = '$(head /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 16 | head -n 1)';" > n3s_config.ini.php
