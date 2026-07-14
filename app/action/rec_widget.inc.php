<?php
/**
 * ウィジェット実行カウント用 API (Issue #217)
 *
 * widget.html (sandbox 内) から run=0 で「実行」ボタンが押されたとき、
 * JavaScript 側が fetch でこのエンドポイントを呼び、実行回数を記録する。
 *
 * GET / POST: index.php?action=rec_widget&page=<app_id>
 *
 * セキュリティ:
 *  - 副作用は access_stats テーブルへの書き込みのみ。
 *  - ログイン投稿の作品オーナー本人の実行は除外する（widget_frame.inc.php と同ルール）。
 *  - app_id が存在しない / 非公開の場合は単純に 200 OK で空レスポンスを返す
 *    （存在チェックのエラー詳細をクライアントに漏らさない）。
 */

// web エンドポイントは無効（API 専用）
function n3s_web_rec_widget()
{
    http_response_code(405);
    exit;
}

function n3s_api_rec_widget()
{
    // app_id を取得・検証
    $app_id = isset($_GET['page']) ? intval($_GET['page']) : 0;
    if ($app_id <= 0) {
        n3s_api_output(false, ['msg' => 'invalid app_id']);
        return;
    }

    // 作品が存在するか・公開かを確認（余分な情報は漏らさない）
    $app = db_get1('SELECT user_id, is_private, editkey FROM apps WHERE app_id=?', [$app_id]);
    if (!$app) {
        // 存在しなくても 200 を返す（存在チェックを悪用させない）
        n3s_api_output(true, []);
        return;
    }
    // 閲覧権限がない場合はカウントしない
    // is_private=1（非公開）および is_private=2（限定公開）を editkey なしで直接呼んで
    // 統計を水増しできる問題を修正 (P1 security fix)
    $editkey = isset($_GET['editkey']) ? $_GET['editkey'] : '';
    if (!n3s_private_access_allowed($app, $editkey)) {
        n3s_api_output(true, []);
        return;
    }

    // オーナー本人の実行は除外（widget_frame.inc.php と同ルール）
    $owner_id = intval($app['user_id']);
    if ($owner_id > 0 && n3s_get_user_id() === $owner_id) {
        n3s_api_output(true, []);
        return;
    }

    // 実行回数を記録
    n3s_record_access('widget', $app_id);
    n3s_api_output(true, []);
}
