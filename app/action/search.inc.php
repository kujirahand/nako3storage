<?php
global $MAX_APP;
$MAX_APP = 200; // 何件まで表示するか

function n3s_web_search()
{
    $r = n3s_list_search();
    n3s_template_fw('search.html', $r);
}

function n3s_list_search()
{
    global $n3s_config, $MAX_APP;
        
    $n3s_config['search_word'] = $search_word = isset($_GET['search_word']) ? trim($_GET['search_word']) : '';
    if ($search_word == '') {
        return [
            "search_word" => '',
            "list" => [],
        ];
    }
    
    // get db
    $sql = 
      'SELECT * FROM apps '.
      'WHERE '.
      '  (author=? OR title LIKE ?)'.
      '  AND(is_private=0) '.
      'ORDER BY fav DESC '.
      'LIMIT ?';
    $list = db_get($sql, [
        $search_word, 
        '%'.$search_word.'%', 
        $MAX_APP
      ]);
    $db = database_get();
    $db = n3s_get_db();
    
    return [
        "search_word" => $search_word,
        "list" => $list,
    ];
}
