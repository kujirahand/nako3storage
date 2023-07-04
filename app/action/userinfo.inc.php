<?php
// ユーザー情報の編集
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

function n3s_web_userinfo()
{
    // user_idの確認
    $user_id = empty($_GET['page']) ? 0 : intval($_GET['page']);
    $mode = empty($_GET['mode']) ? '' : $_GET['mode'];
    if ($user_id == 0) {
        n3s_error('ユーザーIDが不正です', 'ユーザー情報でユーザーIDが不正です。');
        return;
    }
    // ユーザー情報を取得
    $user = db_get1(
        'SELECT * FROM users WHERE user_id=?',
        [$user_id]
    );
    if (!$user) {
        n3s_error('ユーザーIDが不正です', 'ユーザー情報でユーザーIDが不正です。');
        return;
    }
    $old_name = $user['name'];
    // 自身？
    $self = n3s_get_login_info();
    $is_self = ($user_id == $self['user_id']);
    $error = '';
    $email = empty($user['email']) ? '' : $user['email'];
    $name = $user['name'];
    $description = $user['description'];
    if ($description == '') {
        $description = '(未設定)';
    }
    // mode
    if ($mode === 'update' && $is_self) {
        $name2 = empty($_POST['name']) ? '' : $_POST['name'];
        $description2 = empty($_POST['description']) ? '' : $_POST['description'];
        if ($name == '' || $description2 == '') {
            $error = '空白の項目があります。全て正しく埋めてください。';
        } if (mb_strlen($name) > 12) {
            $error = '名前は12文字以内にしてください。';
        } else {
            db_exec('UPDATE users set name=?, description=?, mtime=? WHERE user_id=?',
            [
                $name2,
                $description2,
                time(),
                $user_id,
            ]);
            $name = $name2;
            $description = $description2;
            $error = '更新しました！！';
            n3s_log("{$name}(以前:{$old_name})", "ユーザー情報更新", 1);
        }
    }

    n3s_template_fw('userinfo.html', [
        'email' => $email,
        'user_id' => $user_id,
        'name' => $name,
        'description' => $description,
        'is_self' => $is_self,
        'error' => $error,
    ]);
}

