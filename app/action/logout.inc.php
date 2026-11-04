<?php
// no api login
function n3s_api_logout()
{
    n3s_api_output('ng', ['msg' => 'should use web access']);
}

function n3s_web_logout()
{
    // ログアウトは状態変更なので POST + edit_token を必須とする (#286)。
    // GET で来た場合は確認画面を出す (ブックマーク等のGETリンクから
    // 意図せずログアウトさせられるのを防ぎつつ、到達自体は妨げない)。
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !n3s_checkEditToken()) {
        $token = n3s_getEditToken();
        $form = '<p>ログアウトしますか？</p>' .
            '<form method="POST" action="index.php?page=my&amp;action=logout">' .
            '<input type="hidden" name="edit_token" value="' .
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">' .
            '<button type="submit" class="pure-button">ログアウトする</button>' .
            '</form>';
        n3s_template_fw('basic.html', ['contents' => $form]);
        return;
    }
    // set session
    n3s_logout();
    // message
    n3s_template_fw('basic.html', ['contents' => 'ログアウトしました。']);
}
