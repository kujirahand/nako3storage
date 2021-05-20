<?php
// include version
include_once dirname(__DIR__).'/nako3storage_version.inc.php';
include_once dirname(__DIR__).'/nako_version.inc.php';

// default config
global $n3s_config;
$root = dirname(__DIR__);
$app_dir = __DIR__;
$url_root = dirname($_SERVER['REQUEST_URI']);
$n3s_config = [
    "page_title" => "🍯 なでしこ3貯蔵庫",
    "top_message" => "",
    "admin_users" => [1],
    "admin_contact_link" => "(Please set admin_contact_link in config file.)",
    "dir_data" => "{$root}/data",
    "dir_images" => "{$root}/images",
    "url_images" => "{$url_root}/images",
    "dir_app" => __DIR__,
    "dir_template" => "{$root}/app/template",
    'dir_cache' => $root.'/cache',
    "dir_action" => "{$root}/app/action",
    "dir_sql" => "{$root}/app/sql",
    "file_db_main" => "sqlite:{$root}/data/n3s_main.sqlite",
    "file_db_material" => "sqlite:{$root}/data/n3s_material.sqlite",
    "size_source_max" => 1024 * 1024 * 3, // 最大保存サイズ3MB
    "size_field_max" => 1024 * 3,        // 最大フィールドサイズ3KB
    "size_upload_max" => 1024 * 1024 * 3, // 最大アップロードサイズ
    "extra_header_html" => "",
    "search_word" => "",
    "n3s_css_mtime" => filemtime("$app_dir/template/basic.css"),
    "nako3storage_version" => N3S_APP_VERSION,
    "nako_default_version" => NAKO_DEFAULT_VERSION,
    // for twitter login
    "twitter_api_key" => "",
    "twitter_api_secret" => "",
    "twitter_acc_token" => "",
    "twitter_acc_secret" => "",
    // for analytics
    "analytics" => "",
];


