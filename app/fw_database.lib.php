<?php
// DATABASE
global $FW_DB_MAIN; // PDOオブジェクトの配列
global $FW_DB_INFO; // 設定

function database_set($file_db, $file_sql, $dbname = 'main') {
  global $FW_DB_INFO;
  $FW_DB_INFO = [
    $dbname => [
      'file_db' => $file_db,
      'file_sql' => $file_sql,
      'handle' => null,
    ]
  ];
}

function database_get($dbname = 'main') {
  global $FW_DB_INFO;

  // 既にオープンしたか確認
  if (isset($FW_DB_INFO[$dbname]['handle']) && $FW_DB_INFO[$dbname]['handle']) {
    return $FW_DB_INFO[$dbname]['handle'];
  }

  // Check info
  if (empty($FW_DB_INFO[$dbname]['file_db'])) {
    echo '<h1>[ERROR] Database not set.</h1>'; exit;
  }
  // Open
  $file_db = $FW_DB_INFO[$dbname]['file_db'];
  $file_sql = $FW_DB_INFO[$dbname]['file_sql'];
  if (substr($file_db, 0, 7) == 'sqlite:') {
    $file_db = substr($file_db, 7);
  }
  $need_init = FALSE;
  if (!file_exists($file_db)) {
    $need_init = TRUE;
  }
  $pdo = $FW_DB_MAIN = new PDO('sqlite:'.$file_db);
  // エラーで例外を投げる
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  // 連想配列を返す
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  // 生成
  if ($need_init) {
    $sql = file_get_contents($file_sql);
    $pdo->exec($sql);
  }
  return $pdo;
}

function db_exec($sql, $params = array()) {
  $db = database_get();
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  return $db;
}

function db_insert($sql, $params = array()) {
  $db = database_get();
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  $id = $db->lastInsertId();
  return $id;
}

function db_get($sql, $params = array()) {
  $db = database_get();
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  $r = $stmt->fetchAll();
  return $r;
}

function db_get1($sql, $params = array()) {
  $r = db_get($sql, $params);
  if ($r != null && count($r) > 0) {
    return $r[0];
  }
  return null;
}

function db_table_exists($table) {
  $r = db_get1(
    "SELECT * FROM sqlite_master ".
    "WHERE type='table' AND name=?",
    [$table]);
  return (isset($r['name']));
}





