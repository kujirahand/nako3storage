<?php
global $MAX_APP, $MAX_ZENBUN_SEARCH;
$MAX_APP = 200; // 何件まで表示するか
$MAX_ZENBUN_SEARCH = 30;

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
    $error = '';
    if (mb_strlen($search_word) < 3) {
        $error = '検索語は3文字以上で指定してください。';
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
    if (strpos($search_word, '*') !== FALSE) {
        $search_wc = str_replace('*', '%', $search_word);
    } else {
        $search_wc = "%{$search_word}%";
    }
    
    // 作者名かタイトルで検索
    if ($target == 'normal') {
        $sql = 
          'SELECT * FROM apps '.
          'WHERE '.
          '  (author=? OR title LIKE ?)'.
          '  AND(is_private=0) '.
          'ORDER BY fav DESC '.
          'LIMIT ?';
        $list = db_get($sql, [
            $search_word, 
            $search_wc, 
            $MAX_APP
          ]);
    }
    // プログラムを全部検索
    else if ($target == 'program') {
        $error = '現在実装中です';
        $list = [];
        $sql_material = 
          'SELECT * FROM materials '.
          'WHERE body LIKE ? '.
          'ORDER BY material_id DESC '.
          'LIMIT ?';
        $materials = db_get(
          $sql_material, [
            $search_wc,
            $MAX_ZENBUN_SEARCH,
          ],
          'material');
        foreach ($materials as $m) {
          $app_id = $m['app_id'];
          $material_id = $m['material_id'];
          $body = $m['body'];
          $body_a = preg_split('#(\r\n|\r|\n)#', $body);
          $body_a = array_filter($body_a,
            function($s)use($search_word){
              return strpos($s, $search_word);
            });
          $body_a = array_slice($body_a, 0, 3);
          $body_a = array_map(function($s){
            return trim(str_replace('　', '', $s));
          }, $body_a);
          $row = db_get1(
            'SELECT * FROM apps '.
            'WHERE (material_id=?) '.
            '  AND (is_private=0) '.
            'LIMIT 1',
            [$material_id]);
          if ($row) {
            $row['body'] = implode('\n', $body_a);
            $list[] = $row;
          }
        }
    }
    return [
        "search_word" => $search_word,
        "target" => $target,
        "list" => $list,
        "error" => $error,
    ];
}


