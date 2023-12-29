<?php

function n3s_get_mime($ext)
{
    $mime = [
        "jpg" => "image/jpeg",
        "jpeg" => "image/jpeg",
        "jpe" => "image/jpeg",
        "gif" => "image/gif",
        "png" => "image/png",
        "svg" => "image/svg+xml",
        "mp3" => "audio/mpeg",
        "ogg" => "audio/ogg",
        "oga" => "audio/ogg",
        "txt" => "text/plain",
        "csv" => "text/csv",
        "tsv" => "text/tsv",
        "xml" => "text/xml",
        "json" => "application/json",
        "js" => "text/javascreipt",
        "mid" => "audio/midi",
    ];
    $ext = strtolower($ext);
    if (isset($mime[$ext])) {
        return $mime[$ext];
    }
    return "application/octet-stream";
}
