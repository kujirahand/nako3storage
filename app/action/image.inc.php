<?php
/// API for image access

function n3s_web_image()
{
    echo 'api only';
    exit;
}

function n3s_api_image()
{
    $fname = empty($_GET['f']) ? '' : $_GET['f'];
    $image_name = empty($_GET['image_name']) ? '' : $_GET['image_name'];
    $app_id = empty($_GET['app_id']) ? 0 : intval($_GET['app_id']);
    $token = empty($_GET['t']) ? '' : $_GET['t'];
    if ($image_name != '') {
        // app_idとimage_nameから探す
        $im = db_get1('SELECT * FROM images WHERE app_id=? AND image_name=? LIMIT 1', [$app_id, $image_name]);
        if (! $im) {
            header("HTTP/1.0 404 Not Found");
            echo '404 not found ... invalid image_name.';
            exit;
        }
        $image_id = $im['image_id'];
        $fname = $image_id . '.' . pathinfo($im['image_name'], PATHINFO_EXTENSION);
    }
    // match
    if (! preg_match('/^([0-9]+)\.([a-zA-Z0-9]+)$/', $fname, $m)) {
        header("HTTP/1.0 404 Not Found");
        echo '404 not found ... invalid filename.';
        exit;
    }
    $id = (int) ($m[1]);
    $ext = $m[2];
    $path = n3s_getImageFile($id, $ext, FALSE, $token);
    // check path
    if (! file_exists($path)) {
        header("HTTP/1.0 404 Not Found");
        echo '404 not found';
        exit;
    }
    
    // アクセスコントロール
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('Access-Control-Allow-Origin: *');
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept');
    // header("Cross-Origin-Resource-Policy: cross-origin");
    if ($ext === 'mp3' || $ext === 'ogg' || $ext === 'oga') {
        // 音声ファイルはHTTP_RANGEに対応させるため、サーバーに任せる
        $dir_images = n3s_get_config('dir_images', '');
        $path2 = substr($path, strlen($dir_images));
        $host = $_SERVER['HTTP_HOST'];
        $proto = empty($_SERVER['HTTPS']) ? 'http' : 'https';
        $self = empty($_SERVER['PHP_SELF']) ? './error' : $_SERVER['PHP_SELF'];
        $self_dir = dirname($self);
        $url = "{$proto}://{$host}{$self_dir}/images{$path2}";
        header("Location: $url");
        exit;
    }

    // output
    header('Content-Type: ' . n3s_get_mime($ext));
    header("Content-Disposition: attachment; filename=\"{$id}.{$ext}\"");
    readfile($path);
}
