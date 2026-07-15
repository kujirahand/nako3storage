<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

// no api access
function n3s_api_stats()
{
    n3s_api_output(false, ['msg' => 'should use web access']);
}

function n3s_web_stats()
{
    if (!n3s_is_admin()) {
        n3s_error('admin only', '管理者専用のページです');
        return;
    }

    // 直近30日間の日別統計を取得
    $days = 30;
    $since = date('Y-m-d', strtotime("-{$days} days"));

    $daily_show   = n3s_stats_get_daily('show',   $since);
    $daily_widget = n3s_stats_get_daily('widget', $since);
    $daily_api    = n3s_stats_get_daily('api',    $since);

    // 合計値
    $total_show   = array_sum(array_column($daily_show,   'count'));
    $total_widget = array_sum(array_column($daily_widget, 'count'));
    $total_api    = array_sum(array_column($daily_api,    'count'));

    // アクセス数上位の作品 (show/widget)
    $top_show   = n3s_stats_top_apps('show',   $since, 10);
    $top_widget = n3s_stats_top_apps('widget', $since, 10);

    // 閲覧数上位の素材 (image.php, scripts/image_count.php が集計した累計値)
    $top_images = n3s_stats_top_images(10);

    // 日付リストを作成してチャートデータ用に整形
    $date_labels   = [];
    $show_counts   = [];
    $widget_counts = [];
    $api_counts    = [];
    $daily_show_map   = array_column($daily_show,   'count', 'date');
    $daily_widget_map = array_column($daily_widget, 'count', 'date');
    $daily_api_map    = array_column($daily_api,    'count', 'date');
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $date_labels[]   = $d;
        $show_counts[]   = isset($daily_show_map[$d])   ? intval($daily_show_map[$d])   : 0;
        $widget_counts[] = isset($daily_widget_map[$d]) ? intval($daily_widget_map[$d]) : 0;
        $api_counts[]    = isset($daily_api_map[$d])    ? intval($daily_api_map[$d])    : 0;
    }

    n3s_template_fw('stats.html', [
        'days'          => $days,
        'since'         => $since,
        'total_show'    => $total_show,
        'total_widget'  => $total_widget,
        'total_api'     => $total_api,
        'date_labels'   => json_encode($date_labels,   JSON_UNESCAPED_UNICODE),
        'show_counts'   => json_encode($show_counts,   JSON_UNESCAPED_UNICODE),
        'widget_counts' => json_encode($widget_counts, JSON_UNESCAPED_UNICODE),
        'api_counts'    => json_encode($api_counts,    JSON_UNESCAPED_UNICODE),
        'top_show'      => $top_show,
        'top_widget'    => $top_widget,
        'top_images'    => $top_images,
    ]);
}

/**
 * 日別のアクセス合計 (app_id=0 の行) を取得する
 */
function n3s_stats_get_daily($kind, $since)
{
    $rows = db_get(
        'SELECT date, count FROM access_stats WHERE kind=? AND app_id=0 AND date >= ? ORDER BY date ASC',
        [$kind, $since],
        'log'
    );
    return $rows ? $rows : [];
}

/**
 * アクセス数上位の作品一覧を取得する
 */
function n3s_stats_top_apps($kind, $since, $limit = 10)
{
    $rows = db_get(
        'SELECT app_id, SUM(count) AS total FROM access_stats
          WHERE kind=? AND app_id > 0 AND date >= ?
          GROUP BY app_id ORDER BY total DESC LIMIT ?',
        [$kind, $since, $limit],
        'log'
    );
    if (!$rows) { return []; }
    // タイトルをメインDBから補完
    foreach ($rows as &$row) {
        $app = db_get1('SELECT title, author FROM apps WHERE app_id=?', [$row['app_id']]);
        $row['title']  = $app ? $app['title']  : '(削除済み)';
        $row['author'] = $app ? $app['author'] : '';
    }
    return $rows;
}

/**
 * 閲覧数上位の素材一覧を取得する (images.view, scripts/image_count.php が集計)
 */
function n3s_stats_top_images($limit = 10)
{
    $rows = db_get(
        'SELECT image_id, title, filename, app_id, view FROM images
          WHERE view > 0 ORDER BY view DESC LIMIT ?',
        [$limit]
    );
    if (!$rows) { return []; }
    $baseurl = n3s_get_config('baseurl', '.');
    foreach ($rows as &$row) {
        $row['image_url'] = $baseurl . '/image.php?f=' . rawurlencode($row['filename']);
    }
    return $rows;
}
