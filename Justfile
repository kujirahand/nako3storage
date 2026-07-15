# なでしこ3貯蔵庫 (nako3storage) タスクランナー
# 使い方: `just <レシピ名>` (例: `just test`)
# 参照: docs/tests.md

# 引数なしなら test を実行する
default: test

# テスト用の依存関係をインストールする (vendor/pestphp/pest が無ければ)
# composer コマンドが無い環境では、`just composer-phar` でリポジトリ直下に
# composer.phar を用意すれば代わりに使う。
install:
    #!/usr/bin/env bash
    set -euo pipefail
    if [ -x vendor/bin/pest ]; then
        exit 0
    fi
    if command -v composer >/dev/null 2>&1; then
        composer install
    elif [ -f composer.phar ]; then
        php composer.phar install
    else
        echo "[ERROR] composer が見つかりません。" >&2
        echo "  - https://getcomposer.org/ からインストールするか、" >&2
        echo "  - \`just composer-phar\` を実行してリポジトリ直下に composer.phar を取得してください。" >&2
        exit 1
    fi

# composer コマンドが無い環境向けに、リポジトリ直下へ composer.phar を取得する
composer-phar:
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --install-dir=. --filename=composer.phar
    rm composer-setup.php

# テストスイートを実行する (tests/ 以下, Pest)
test: install
    php vendor/bin/pest

# 指定したファイル・フィルタだけテストを実行する (例: `just test-filter tests/Unit/UserModelTest.php`)
test-filter FILTER: install
    php vendor/bin/pest {{FILTER}}

# PHP構文チェック (AGENTS.md #13 と同等)
lint:
    find . -path './app/fw_simple/.git' -prune -o -path './nadesiko3hub/.git' -prune -o -path './vendor' -prune -o -name '*.php' -print -exec php -l {} \;

# コメント審査バッチを実行する
comment-audit:
    php scripts/comment_audit.php

# 素材アクセスログの集計バッチを実行する (1時間に1回程度 cron から実行する想定)
image-count:
    php scripts/image_count.php

# 作品アクセスログの集計バッチを実行する (1時間に1回程度 cron から実行する想定)
app-count:
    php scripts/app_count.php
