<?php
// include version
include_once dirname(__DIR__).'/nako3storage_version.inc.php';
include_once dirname(__DIR__).'/nako_version.inc.php';
define('N3S_APP_TITLE', '🍯 プログラム貯蔵庫');

// default config
global $n3s_config;
$root = dirname(__DIR__);
$app_dir = __DIR__;
$url_root = empty($_SERVER['REQUEST_URI']) ? './' : dirname($_SERVER['REQUEST_URI']);
$n3s_config = [
    "page_title" => N3S_APP_TITLE,
    "top_message" => "",
    "admin_users" => [1],
    "admin_email" => "", // 管理者のメルアドを書いてください
    "mail_from" => "", // メールの送信元情報の指定
    "admin_contact_link" => "(Please set admin_contact_link in config file.)",
    "dir_data" => "{$root}/data",
    "dir_images" => "{$root}/images",
    "url_images" => "{$url_root}/images",
    "dir_app" => __DIR__,
    "dir_template" => "{$root}/app/template",
    "dir_resource" => "{$root}/app/resource",
    'dir_cache' => $root.'/cache',
    "dir_action" => "{$root}/app/action",
    "dir_astorage" => "{$root}/data_astorage",
    "dir_sql" => "{$root}/app/sql",
    "file_db_main" => "sqlite:{$root}/data/n3s_main.sqlite",
    "file_db_log" => "sqlite:{$root}/data/n3s_log.sqlite",
    "file_db_material" => "sqlite:{$root}/data/n3s_material.sqlite",
    "file_db_users" => "sqlite:{$root}/data/n3s_users.sqlite",
    "size_source_max" => 1024 * 1024 * 3, // 最大保存サイズ3MB
    "size_field_max" => 1024 * 3,        // 最大フィールドサイズ3KB
    "size_upload_max" => 1024 * 1024 * 7, // 最大アップロードサイズ
    "size_astorage_key_max" => 256,        // アプリ内ストレージAPIのkey最大サイズ(バイト)
    "size_astorage_value_max" => 1024 * 64, // アプリ内ストレージAPIのvalue最大サイズ(64KB)
    "extra_header_html" => "",
    "search_word" => "",
    "n3s_css_mtime" => filemtime("$app_dir/resource/basic.css"),
    "nako3storage_version" => N3S_APP_VERSION,
    "nako_default_version" => NAKO_DEFAULT_VERSION,
    "session_lifetime" => 60 * 60 * 24, // 24時間セッションを保持
    "use_image_name_shortcut" => TRUE, // "/images/app_id/image_name"のショートカットを有効にする
    // for analytics
    "analytics" => "",
    // sandbox
    // "sandbox_url" => "https://n3s-sandbox.nadesi.com/", // 末尾にスラッシュをいれること!!
    "sandbox_url" => "", 
    // "app_root_url" => "https://n3s.nadesi.com/", // 末尾にスラッシュを入れること!!
    "app_root_url" => "http://localhost/repos/nako3storage/",
    "sandbox_params" => "allow-same-origin allow-modals allow-forms allow-scripts allow-popups allow-top-navigation-by-user-activation allow-downloads",
    // hub
    "nadesiko3hub_enabled" => false,
    "nadesiko3hub_dir" => "$root/nadesiko3hub",
    // NG Words
    "ng_words" => ["NGワードのテスト"],
    // -----------------------------------------------------
    // Discord
    "discord_webhook_name" => "なでしこ3貯蔵庫",
    "discord_webhook_url" => "",
    // Webhook送信時にTLS証明書検証を行うか (todo-security.md #10)。
    // 既定はfalse(従来通り --insecure 付き)。サーバー証明書の検証環境が整っている場合は
    // n3s_config.ini.php で true にすることを推奨。
    "webhook_secure" => true,
    // -----------------------------------------------------
    // Google OAuth ログイン (docs/user_login_oauth_google.md)
    "google_oauth_client_id" => "",
    "google_oauth_client_secret" => "",
    // 例: "https://n3s.nadesi.com/index.php?action=login&page=google_callback"
    "google_oauth_redirect_uri" => "",
    // -----------------------------------------------------
    // Gemini APIキー (コメント審査用)
    "gemini_api_key" => "",
    // コメント審査の自動承認モード (true: 審査をスキップして自動承認、false: 審査を行う)
    "comment_audit_auto_approve" => false,
];

// timezone
date_default_timezone_set('Asia/Tokyo');
