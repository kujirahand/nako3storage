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
    $user = n3s_getUserInfo($user_id);
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
    $screen_name = isset($user['screen_name']) ? $user['screen_name'] : '';
    $profile_url = n3s_get_user_image_url($user, 0);
    $image_id = intval(isset($user['image_id']) ? $user['image_id'] : 0);
    if ($description == '') {
        $description = '(未設定)';
    }
    // mode
    if ($mode === 'update' && $is_self) {
        if (!n3s_checkEditToken('userinfo')) {
            n3s_error('更新できません', 'トークンが無効です。もう一度やり直してください。');
            return;
        }
        $name2 = empty($_POST['name']) ? '' : $_POST['name'];
        $description2 = empty($_POST['description']) ? '' : $_POST['description'];
        $screen_name2 = isset($_POST['screen_name']) ? trim($_POST['screen_name']) : '';
        $screen_name2 = ltrim($screen_name2, '@');
        if ($name2 == '' || $description2 == '') {
            $error = '空白の項目があります。全て正しく埋めてください。';
        } elseif (mb_strlen($name2) > 12) {
            $error = '名前は12文字以内にしてください。';
        } elseif ($screen_name2 !== '' && !preg_match('/^[A-Za-z0-9_]{1,15}$/', $screen_name2)) {
            $error = 'Xアカウントは英数字とアンダースコア15文字以内で入力してください。';
        } else {
            db_exec('UPDATE users set name=?, description=?, screen_name=?, mtime=? WHERE user_id=?',
            [
                $name2,
                $description2,
                $screen_name2,
                time(),
                $user_id,
            ], 'users');
            $name = $name2;
            $description = $description2;
            $screen_name = $screen_name2;
            $_SESSION['name'] = $name2;
            $_SESSION['screen_name'] = $screen_name2;
            $has_upload = isset($_FILES['user_image']) &&
                isset($_FILES['user_image']['error']) &&
                intval($_FILES['user_image']['error']) !== UPLOAD_ERR_NO_FILE;
            if ($has_upload) {
                try {
                    $image_id = n3s_save_user_image($user_id, $_FILES['user_image']);
                    $profile_user = [
                        'image_id' => $image_id,
                        'profile_url' => '',
                    ];
                    $profile_url = n3s_get_user_image_url($profile_user, 0);
                    $_SESSION['profile_url'] = n3s_get_user_image_url($profile_user);
                    $error = 'プロフィールと画像を更新しました。';
                } catch (Exception $e) {
                    $error = 'プロフィールは更新しましたが、画像の処理に失敗しました。 ' .
                        htmlspecialchars($e->getMessage(), ENT_QUOTES);
                }
            } elseif (isset($_POST['user_image_delete']) && intval($_POST['user_image_delete']) === 1) {
                db_exec('UPDATE users SET image_id=0, mtime=? WHERE user_id=?', [time(), $user_id], 'users');
                $image_id = 0;
                $profile_url = n3s_get_user_default_image_url();
                $_SESSION['profile_url'] = $profile_url;
                $error = 'プロフィール画像を削除しました。';
            } else {
                $error = '更新しました。';
            }
            n3s_log("{$name}(以前:{$old_name})", "ユーザー情報更新", 1);
        }
    }

    n3s_template_fw('userinfo.html', [
        'email' => $email,
        'user_id' => $user_id,
        'name' => $name,
        'description' => $description,
        'screen_name' => $screen_name,
        'profile_url' => $profile_url,
        'image_id' => $image_id,
        'edit_token' => n3s_getEditToken('userinfo'),
        'is_self' => $is_self,
        'error' => $error,
        'link_mypage' => n3s_getURL('all', 'mypage'),
        'link_material' => n3s_getURL('all', 'mypage', ['mode' => 'material']),
        'link_all_fav' => n3s_getURL('all', 'mypage', ['fav' => 'all']),
        'link_userinfo' => n3s_getURL($user_id, 'userinfo'),
    ]);
}
