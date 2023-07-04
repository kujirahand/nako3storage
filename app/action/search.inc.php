<?php
global $MAX_APP, $MAX_ZENBUN_SEARCH;
$MAX_APP = 200; // 何件まで表示するか
$MAX_ZENBUN_SEARCH = 30;

require_once __DIR__.'/save.inc.php';

function n3s_web_search()
{
    $r = n3s_list_search();
    n3s_template_fw('search.html', $r);
}

function n3s_list_search()
{
    global $n3s_config, $MAX_APP, $MAX_ZENBUN_SEARCH;

    $n3s_config['search_word'] = $search_word = isset($_GET['search_word']) ? trim($_GET['search_word']) : '';
    $target = isset($_GET['target']) ? $_GET['target'] : 'normal';
    $offset = intval(isset($_GET['offset']) ? $_GET['offset'] : '0');
    $error = '';
    if (mb_strlen($search_word) < 2) {
        $error = '検索語は2文字以上で指定してください。';
    }
    if ($search_word == '' || $error != '') {
        return [
            "target" => $target,
            "search_word" => $search_word,
            "list" => [],
            "error" => $error,
        ];
    }
    // wildcard options
    if (strpos($search_word, '*') !== false) {
        $search_wc = str_replace('*', '%', $search_word);
    } else {
        $search_wc = "%{$search_word}%";
    }
    
    // タイトルで検索
    $list = [];
    if ($target == 'normal') {
        $sql =
          'SELECT * FROM apps '.
          'WHERE '.
          '  (title LIKE ? OR tag LIKE ?)'.
          '  AND(is_private=0) '.
          'ORDER BY fav DESC, app_id DESC '.
          'LIMIT ? OFFSET ?';
        $list = db_get($sql, [
            $search_wc,
            $search_wc,
            $MAX_APP,
            $offset
          ]);
    }
    elseif ($target == 'tag') {
        $sql =
          'SELECT * FROM apps '.
          'WHERE '.
          '  (tag LIKE ?)'.
          '  AND(is_private=0) '.
          'ORDER BY fav DESC, app_id DESC '.
          'LIMIT ? OFFSET ?';
        $list = db_get($sql, [
            $search_wc,
            $MAX_APP,
            $offset
          ]);
    }
    elseif ($target == 'author') {
        $sql =
          'SELECT * FROM apps '.
          'WHERE '.
          '  (author=?)'.
          '  AND(is_private=0) '.
          'ORDER BY fav DESC, app_id DESC '.
          'LIMIT ? OFFSET ?';
        $list = db_get($sql, [
            $search_word,
            $MAX_APP,
            $offset
          ]);
    }
    // プログラムを全部検索
    elseif ($target == 'program') {
        // Google検索に投げる!!
        $enc = urldecode($search_word);
        // header("Location: https://www.google.com/search?q=site%3A%2F%2Fn3s.nadesi.com+{$enc}");
        header("Location: https://github.com/search?q=repo%3Akujirahand%2Fnadesiko3hub%20{$enc}&type=code");
        echo '現在不可軽減のため、アプリ内の全文検索を停止しています。すみません。';
        exit;
        
        $list = [];
        $r = db_get1('SELECT max(app_id) FROM apps', []);
        if (!$r) {
            echo 'db error';
            exit;
        }
        $max_id = $r['max(app_id)'];
        $materials = [];
        $tid = $max_id;
        for ($i = 0; $i < 3; $i++) {
            $dbname = n3s_getMaterialDB($tid);
            $sql_material =
              'SELECT * FROM materials '.
              'WHERE body LIKE ? '.
              'ORDER BY material_id DESC '.
              'LIMIT ?';
            $ma = db_get(
                $sql_material,
                [
                $search_wc,
                $MAX_ZENBUN_SEARCH,
              ],
                $dbname
            );
            $materials = $materials + $ma;
            $tid -= 100;
            if ($tid < 0) {
                break;
            }
        }
        foreach ($materials as $m) {
            $app_id = $m['app_id'];
            $material_id = $m['material_id'];
            $body = $m['body'];
            $body_a = preg_split('#(\r\n|\r|\n)#', $body);
            $body_a = array_filter(
                $body_a,
                function ($s) use ($search_word) {
                return strpos($s, $search_word);
            }
            );
            $body_a = array_slice($body_a, 0, 3);
            $body_a = array_map(function ($s) {
                return trim(str_replace('　', '', $s));
            }, $body_a);
            $row = db_get1(
                'SELECT * FROM apps '.
            'WHERE (material_id=?) '.
            '  AND (is_private=0) '.
            'LIMIT 1',
                [$material_id]
            );
            if ($row) {
                $row['body'] = implode('\n', $body_a);
                $list[] = $row;
            }
        }
    }
    // タグにリンクをつける
    foreach ($list as &$i) {
        $i['tag_link'] = n3s_makeTagLink($i['tag']);
    }
    return [
        "search_word" => $search_word,
        "target" => $target,
        "list" => $list,
        "error" => $error,
    ];
}
