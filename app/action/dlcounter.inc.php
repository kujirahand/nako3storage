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
        'year' => $data['year'],
        'week_total' => $data['week_total'],
        'month_total' => $data['month_total'],
        'year_total' => $data['year_total'],
        'pending_count' => $data['pending_count'],
        'last_aggregated' => $last_aggregated,
        'daily_labels' => json_encode($data['daily_labels'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'daily_counts' => json_encode($data['daily_counts']),
        'hour_labels' => json_encode($data['hour_labels'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'hour_counts' => json_encode($data['hour_counts']),
        'monthly_labels' => json_encode($data['monthly_labels'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'monthly_counts' => json_encode($data['monthly_counts']),
        'weekly_labels' => json_encode($data['weekly_labels'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'weekly_counts' => json_encode($data['weekly_counts']),
        'wnako3_version_series' => json_encode($data['wnako3_version_series'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'version_rows' => $data['version_rows'],
        'file_rows' => $data['file_rows'],
        'version_file_rows' => $data['version_file_rows'],
    ]);
}
