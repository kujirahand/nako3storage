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
        // 拡張子があればimage_nameから拡張子を取得する
        if (strpos($image_name, '.') !== false) {
            $fname = $image_id . '.' . pathinfo($image_name, PATHINFO_EXTENSION);
        } else {
            $fname = $image_id . '.png';
        }
    }
    // match
    if (preg_match('/^([0-9]+)\.([a-zA-Z0-9]+)$/', $fname, $m)) {
        $id = (int) ($m[1]);
        $ext = $m[2];
    } else {
        if (preg_match('/^([0-9]+)\.$/', $fname, $m)) {
            $id = (int) ($m[1]);
            $ext = "";
        } else {
            header("HTTP/1.0 404 Not Found");
            echo '404 not found ... invalid filename.';
            exit;
        }
    }
    $path = n3s_getImageFile($id, $ext, FALSE, $token);
    // check path
    if (! file_exists($path)) {
        header("HTTP/1.0 404 Not Found");
        echo "404 not found (e100)";
        exit;
    }
    
    // アクセスコントロール
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('Access-Control-Allow-Origin: *');
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept');
    // header("Cross-Origin-Resource-Policy: cross-origin");
    if ($ext === 'mp3' || $ext === 'ogg' || $ext === 'oga') {
        // 音声ファイルは <audio> のシークのため HTTP Range に対応する必要がある。
        // 以前は実ファイル(/images/...)へ Location リダイレクトしてWebサーバーに
        // 配信を任せていたが、images/ への直接アクセスを禁止した(todo-security.md #3)ため、
        // image.php 自身が Range 対応で配信する。
        n3s_output_file_with_range($path, n3s_get_mime($ext));
        exit;
    }

    // output
    header('Content-Type: ' . n3s_get_mime($ext));
    header("Content-Disposition: attachment; filename=\"{$id}.{$ext}\"");
    readfile($path);
}

// HTTP Range ヘッダを解釈して配信すべきバイト範囲 [start, end] を返す。
// - Range 指定なし / 解釈不能: null (ファイル全体を返すべき)
// - 範囲が不正で満たせない(416相当): false
// $range は "bytes=START-END" 形式のみ対応(複数レンジ等は未対応→全体を返す)。
function n3s_parse_byte_range($range, $size)
{
    $size = intval($size);
    if ($size <= 0) {
        return null;
    }
    if (!is_string($range) || $range === '') {
        return null;
    }
    if (!preg_match('/^bytes=\s*(\d*)-(\d*)\s*$/i', $range, $m)) {
        return null; // 複数レンジ等は未対応。全体を返す
    }
    $startStr = $m[1];
    $endStr = $m[2];
    if ($startStr === '' && $endStr === '') {
        return false; // "bytes=-" は不正
    }
    if ($startStr === '') {
        // 末尾 N バイト指定: bytes=-N
        $length = intval($endStr);
        if ($length <= 0) {
            return false;
        }
        if ($length > $size) {
            $length = $size;
        }
        return [$size - $length, $size - 1];
    }
    $start = intval($startStr);
    $end = ($endStr === '') ? $size - 1 : intval($endStr);
    if ($end > $size - 1) {
        $end = $size - 1; // 終端はファイル末尾までにクランプ
    }
    if ($start > $end || $start < 0 || $start >= $size) {
        return false;
    }
    return [$start, $end];
}

// ファイルを HTTP Range 対応で出力する(音声のシーク再生用)。
// images/ への直接アクセスを禁止したため、Webサーバーに任せず image.php が配信する。
function n3s_output_file_with_range($path, $mime)
{
    $size = @filesize($path);
    $fp = @fopen($path, 'rb');
    if ($size === false || $fp === false) {
        header('HTTP/1.0 404 Not Found');
        echo '404 not found (e101)';
        return;
    }
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');

    $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '';
    $parsed = n3s_parse_byte_range($range, $size);
    if ($parsed === false) {
        header('HTTP/1.1 416 Range Not Satisfiable');
        header("Content-Range: bytes */{$size}");
        fclose($fp);
        return;
    }
    if ($parsed === null) {
        $start = 0;
        $end = $size - 1;
    } else {
        list($start, $end) = $parsed;
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    $length = $end - $start + 1;
    header('Content-Length: ' . $length);

    fseek($fp, $start);
    $remaining = $length;
    $chunk = 8192;
    while ($remaining > 0 && !feof($fp)) {
        $read = ($remaining > $chunk) ? $chunk : $remaining;
        $data = fread($fp, $read);
        if ($data === false || $data === '') {
            break;
        }
        echo $data;
        $remaining -= strlen($data);
    }
    fclose($fp);
}
