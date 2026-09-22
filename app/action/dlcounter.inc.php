<?php
// CDNダウンロード統計ダッシュボード (Issue #252)
header('X-Frame-Options: SAMEORIGIN');

require_once dirname(__DIR__) . '/dlcounter_lib.inc.php';

function n3s_api_dlcounter()
{
    n3s_api_output(false, ['msg' => 'should use web access']);
}

function n3s_web_dlcounter()
{
    if (!n3s_is_admin()) {
        n3s_error('admin only', '管理者専用のページです');
        return;
    }

    try {
        $data = n3s_get_cdn_download_dashboard();
    } catch (Throwable $e) {
        n3s_error('download counter error', 'CDNダウンロード統計を取得できませんでした。');
        return;
    }

    $last_aggregated = $data['last_aggregated_at'] > 0
        ? date('Y-m-d H:i:s', $data['last_aggregated_at'])
        : '未集計';

    n3s_template_fw('dlcounter.html', [
        'week_since' => $data['week_since'],
        'month' => $data['month'],
        'week_total' => $data['week_total'],
        'month_total' => $data['month_total'],
        'pending_count' => $data['pending_count'],
        'last_aggregated' => $last_aggregated,
        'daily_labels' => json_encode($data['daily_labels'], JSON_UNESCAPED_UNICODE),
        'daily_counts' => json_encode($data['daily_counts'], JSON_UNESCAPED_UNICODE),
        'hour_labels' => json_encode($data['hour_labels'], JSON_UNESCAPED_UNICODE),
        'hour_counts' => json_encode($data['hour_counts'], JSON_UNESCAPED_UNICODE),
        'version_rows' => $data['version_rows'],
        'file_rows' => $data['file_rows'],
        'version_file_rows' => $data['version_file_rows'],
    ]);
}
