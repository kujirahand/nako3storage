<?php
// tests/Unit/CdnTest.php
declare(strict_types=1);

// cdn.php と同様のバリデーションロジックを検証する単体テスト

function cdn_test_validate_version(string $ver): bool
{
    return (bool) preg_match('#^3\.\d{1,3}\.\d{1,3}$#', $ver);
}

function cdn_test_sanitize_file(string $file): string
{
    $file = trim($file);
    if (substr($file, 0, 1) === '/') {
        $file = substr($file, 1);
    }
    $file = str_replace('..', '', $file);
    $file = str_replace(':', '', $file);
    $file = str_replace('%20', '', $file);
    return $file;
}

function cdn_test_validate_file_chars(string $file): bool
{
    if (!preg_match('#^[a-zA-Z0-9\-\_\.\/\%]+$#', $file) || $file === "") {
        return false;
    }
    return true;
}

function cdn_test_is_bad_response($body, $ext): bool
{
    if ($body === FALSE || $body === NULL) {
        return TRUE;
    }
    $trimmed = ltrim((string)$body);
    if ($trimmed === '') {
        return TRUE;
    }
    if ($ext === 'js' || $ext === 'mjs' || $ext === 'css' || $ext === 'map') {
        if (preg_match('#^<#', $trimmed)) {
            return TRUE;
        }
    }
    if ($ext === 'map') {
        if (preg_match('#^[\{\[]#', $trimmed) !== 1) {
            return TRUE;
        }
    }
    return FALSE;
}

test('cdn.php のバージョン形式バリデーションは正しいフォーマットのみを許可する', function () {
    expect(cdn_test_validate_version('3.0.0'))->toBeTrue();
    expect(cdn_test_validate_version('3.11.9'))->toBeTrue();
    expect(cdn_test_validate_version('3.999.999'))->toBeTrue();

    expect(cdn_test_validate_version('2.0.0'))->toBeFalse();
    expect(cdn_test_validate_version('latest'))->toBeFalse();
    expect(cdn_test_validate_version('3.1'))->toBeFalse();
    expect(cdn_test_validate_version('3.1.2.3'))->toBeFalse();
    expect(cdn_test_validate_version('../3.1.2'))->toBeFalse();
});

test('cdn.php のファイル名サニタイズ処理は無効な相対パス記号やスペースを除去する', function () {
    expect(cdn_test_sanitize_file('/release/wnako3.js'))->toBe('release/wnako3.js');
    expect(cdn_test_sanitize_file('release/../wnako3.js'))->toBe('release//wnako3.js');
    expect(cdn_test_sanitize_file('release:wnako3.js'))->toBe('releasewnako3.js');
    expect(cdn_test_sanitize_file('release%20wnako3.js'))->toBe('releasewnako3.js');
});

test('cdn.php のサニタイズ後の許可文字チェックは安全な文字のみを許可する', function () {
    expect(cdn_test_validate_file_chars('release/wnako3.js'))->toBeTrue();
    expect(cdn_test_validate_file_chars('release-1.2_3.js'))->toBeTrue();

    expect(cdn_test_validate_file_chars(''))->toBeFalse();
    expect(cdn_test_validate_file_chars('release;wnako3.js'))->toBeFalse();
    expect(cdn_test_validate_file_chars('release?v=1'))->toBeFalse();
    expect(cdn_test_validate_file_chars('release|wnako3.js'))->toBeFalse();
});

test('cdn.php の不正レスポンス検出処理は壊れたレスポンスを正しく判定する', function () {
    // 正常なJSコード
    expect(cdn_test_is_bad_response('console.log("hello");', 'js'))->toBeFalse();
    
    // JSとして読み込まれたHTML (CDNの404エラーページなど)
    expect(cdn_test_is_bad_response('<!DOCTYPE html><html>', 'js'))->toBeTrue();
    
    // 空レスポンス
    expect(cdn_test_is_bad_response('', 'js'))->toBeTrue();
    expect(cdn_test_is_bad_response(null, 'js'))->toBeTrue();

    // 正常なJSON (mapファイル)
    expect(cdn_test_is_bad_response('{"version":3}', 'map'))->toBeFalse();
    expect(cdn_test_is_bad_response('[1, 2, 3]', 'map'))->toBeFalse();
    
    // 不正なJSON (mapファイル)
    expect(cdn_test_is_bad_response('invalid json', 'map'))->toBeTrue();
});
