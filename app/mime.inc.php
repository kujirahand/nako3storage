<?php

function n3s_get_mime($ext) {
    $mime = [
        "jpg" => "image/jpeg",
        "jpeg" => "image/jpeg",
        "jpe" => "image/jpeg",
        "gif" => "image/gif",
        "png" => "image/png",
        "mp3" => "audio/mpeg",
        "ogg" => "audio/ogg",
        "oga" => "audio/ogg",
        "txt" => "text/plain",
        "csv" => "text/csv",
        "tsv" => "text/tsv",
        "xml" => "text/xml",
        "json" => "application/json",
        "js" => "text/javascreipt",
    ];
    $ext = strtolower($ext);
    if (isset($mime[$ext])) {
        return $mime[$ext];
    }
    return "application/octet-stream";
}


