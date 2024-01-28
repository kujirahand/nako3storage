<?php
if (!isset($argv[1]) || $argv[1] !== 'suki-nadesi.com') {
    echo "php mv_users.php suki-nadesi.com\n";
    exit;
}

$pdo1 = new PDO('sqlite:n3s_main.sqlite');;
$pdo2 = new PDO('sqlite:n3s_users.sqlite');

// --- init --
$sql_init = file_get_contents('../app/sql/init-users.sql');
foreach (explode(";", $sql_init) as $sql) {
    echo "exec: $sql\n";
    $sql = trim($sql);
    if ($sql === '') continue;
    $pdo2->exec($sql.";");
}

$users = $pdo1->query('SELECT * FROM users')->fetchAll();
foreach ($users as $user) {
    $user_id = $user['user_id'];
    $email = $user['email'];
    if ($email === '') {
        $email = "::dummy::$user_id";
    }
    $password = $user['password'];
    $pass_token = $user['pass_token'];
    $name = $user['name'];
    $screen_name = $user['screen_name'];
    $description = $user['description'];
    $twitter_id = $user['twitter_id'];
    $profile_url = $user['profile_url'];
    $ctime = $user['ctime'];
    $mtime = $user['mtime'];
    echo "@$user_id: $name<$email>\n";
    $sql = "INSERT INTO users (user_id,email,password,pass_token,name,screen_name,description,twitter_id,profile_url,ctime,mtime) ".
            "VALUES (?,?,?,?,?,?,?,?,?,?,?)";
    try {
        $stmt = $pdo2->prepare($sql);
        $stmt->execute([$user_id, $email, $password, $pass_token, $name, $screen_name, $description, $twitter_id, $profile_url, $ctime, $mtime]);
    } catch (Exception $e) {
        echo "ERROR: $e\n";
    }
}
echo "ok!\n";
