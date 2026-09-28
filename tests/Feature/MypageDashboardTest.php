<?php
declare(strict_types=1);

require_once N3S_TEST_ROOT . '/app/action/mypage.inc.php';

test('作品がない場合、ダッシュボードは0値と空案内を正しく描画する', function () {
    $userId = n3s_add_user('dash-empty@example.com', 'password1', 'ダッシュ空太郎');
    expect(n3s_login('dash-empty@example.com', 'password1'))->toBeTrue();

    $_GET['page'] = '0';
    $out = n3s_test_capture(fn() => n3s_web_mypage());

    expect($out)
        ->toContain('id="dashboard"')
        ->toContain('総アクセス数')
        ->toContain('今月のアクセス')
        ->toContain('獲得お気に入り')
        ->toContain('投稿作品数')
        ->toContain('まだ作品がありません。')
        ->toContain('直近1ヶ月のアクセスデータがまだありません。')
        ->toContain('直近30日間のアクセスデータがまだありません。')
        ->not->toContain('mypage-access-chart')
        ->not->toContain('chart.min.js');
});

test('作品とアクセス実績がある場合、ダッシュボードのメトリクス・人気ベスト5・急上昇ベスト5・グラフが正しく描画される', function () {
    $userId = n3s_add_user('dash-user@example.com', 'password1', 'ダッシュ実太郎');
    expect(n3s_login('dash-user@example.com', 'password1'))->toBeTrue();
    $now = time();

    // 6つの作品を作成 (view と fav を設定)
    $appIds = [];
    $appsData = [
        ['title' => '作品A', 'view' => 100, 'fav' => 15],
        ['title' => '作品B', 'view' => 50,  'fav' => 30],
        ['title' => '作品C', 'view' => 200, 'fav' => 5],
        ['title' => '作品D', 'view' => 10,  'fav' => 2],
        ['title' => '作品E', 'view' => 5,   'fav' => 1],
        ['title' => '作品F', 'view' => 1,   'fav' => 0], // 6番目: ベスト5から漏れる
    ];

    foreach ($appsData as $data) {
        $aid = db_insert(
            'INSERT INTO apps (title, author, memo, user_id, is_private, nakotype, copyright, tag, version, view, fav, ctime, mtime) ' .
            'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$data['title'], 'ダッシュ実太郎', '説明', $userId, 0, 'wnako', 'MIT', 'テスト', '3.7.2', $data['view'], $data['fav'], $now, $now]
        );
        $appIds[] = intval($aid);
    }

    // ログDBへのアクセスデータ投入
    $today = date('Y-m-d');
    $currentMonth = date('Y-m');

    // 作品D (aid = $appIds[3]) は累計は10だが直近で急上昇 (30アクセス)
    db_insert(
        'INSERT INTO access_stats (date, kind, app_id, count) VALUES (?,?,?,?)',
        [$today, 'show', $appIds[3], 30],
        'log'
    );
    // 作品A (aid = $appIds[0]) は直近で15アクセス (show: 10, widget: 5)
    db_insert(
        'INSERT INTO access_stats (date, kind, app_id, count) VALUES (?,?,?,?)',
        [$today, 'show', $appIds[0], 10],
        'log'
    );
    db_insert(
        'INSERT INTO access_stats (date, kind, app_id, count) VALUES (?,?,?,?)',
        [$today, 'widget', $appIds[0], 5],
        'log'
    );

    // 今月の月別集計 access_stats_monthly
    db_insert(
        'INSERT INTO access_stats_monthly (month, kind, app_id, count) VALUES (?,?,?,?)',
        [$currentMonth, 'show', $appIds[3], 30],
        'log'
    );
    db_insert(
        'INSERT INTO access_stats_monthly (month, kind, app_id, count) VALUES (?,?,?,?)',
        [$currentMonth, 'show', $appIds[0], 15],
        'log'
    );

    // ダッシュボード集計関数を直接検証
    $dash = n3s_mypage_get_dashboard_data($userId);
    expect($dash['total_views'])->toBe(366) // 100+50+200+10+5+1
        ->and($dash['total_favs'])->toBe(53) // 15+30+5+2+1+0
        ->and($dash['total_apps'])->toBe(6)
        ->and($dash['monthly_views'])->toBe(45) // 30+15
        ->and($dash['has_chart_data'])->toBeTrue();

    // 累計人気ベスト5の順序: 作品C(200) -> 作品A(100) -> 作品B(50) -> 作品D(10) -> 作品E(5)
    expect($dash['top_all_time'])->toHaveCount(5)
        ->and($dash['top_all_time'][0]['app_id'])->toBe($appIds[2]) // 作品C
        ->and($dash['top_all_time'][1]['app_id'])->toBe($appIds[0]) // 作品A
        ->and($dash['top_all_time'][2]['app_id'])->toBe($appIds[1]) // 作品B
        ->and($dash['top_all_time'][3]['app_id'])->toBe($appIds[3]) // 作品D
        ->and($dash['top_all_time'][4]['app_id'])->toBe($appIds[4]); // 作品E

    // 急上昇ベスト5: 作品D (30) -> 作品A (15)
    expect($dash['top_surging'])->toHaveCount(2)
        ->and($dash['top_surging'][0]['app_id'])->toBe($appIds[3]) // 作品D
        ->and($dash['top_surging'][0]['period_views'])->toBe(30)
        ->and($dash['top_surging'][1]['app_id'])->toBe($appIds[0]) // 作品A
        ->and($dash['top_surging'][1]['period_views'])->toBe(15);

    // マイページ表示のHTML検証
    $_GET['page'] = '0';
    $out = n3s_test_capture(fn() => n3s_web_mypage());

    expect($out)
        ->toContain('id="dashboard"')
        ->toContain('366') // 総アクセス数
        ->toContain('45')  // 今月のアクセス
        ->toContain('53')  // 獲得お気に入り
        ->toContain('🏆 これまでの人気作品ベスト5')
        ->toContain('作品C')
        ->toContain('作品A')
        ->toContain('🚀 直近1ヶ月の急上昇ベスト5')
        ->toContain('直近 30 回')
        ->toContain('直近 15 回')
        ->toContain('class="n3s-mypage-ranking-badge rank-0">1</span>')
        ->toContain('class="n3s-mypage-ranking-badge rank-1">2</span>')
        ->not->toContain('{{$')
        ->not->toContain('{{ $')
        ->toContain('👀 200') // 作品行のアクセス数表示
        ->toContain('👀 100');
});

test('マイページの2ページ目以降ではダッシュボードが表示されない', function () {
    $userId = n3s_add_user('dash-page1@example.com', 'password1', 'ダッシュページ太郎');
    expect(n3s_login('dash-page1@example.com', 'password1'))->toBeTrue();

    $_GET['page'] = '1';
    $out = n3s_test_capture(fn() => n3s_web_mypage());

    expect($out)
        ->not->toContain('id="dashboard"')
        ->not->toContain('🏆 これまでの人気作品ベスト5')
        ->not->toContain('mypage-access-chart');
});
