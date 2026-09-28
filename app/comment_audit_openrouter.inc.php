<?php
// コメント審査バッチと実モデル判定テストで共有する OpenRouter 呼び出し。
if (!function_exists('check_comment_with_openrouter')) {
    /** 戻り値: 'approved' | 'ng' | 'error' */
    function check_comment_with_openrouter($body, $api_key, $model)
    {
        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $prompt = "以下のコメントが、プログラミング投稿共有サイトのコメントとして適切か判断してください。いたずら、スパム、他者への誹謗中傷、不適切な言葉、過度な個人情報などが含まれる場合は不承認としてください。\n\n" .
                  "コメント内容:\n\"\"\"\n" . $body . "\n\"\"\"\n\n" .
                  "返答は必ず以下のJSONフォーマットのみで返してください。余計な説明やマークダウンの囲み（```json など）は一切含めず、純粋なJSON文字列としてください。\n" .
                  "{\"approved\": true} または {\"approved\": false}";

        $data = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 接続タイムアウト 10秒
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);        // 全体タイムアウト 30秒

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $curl_error = curl_errno($ch);
        $curl_error_msg = curl_error($ch);

        if (PHP_VERSION_ID < 80000 && is_resource($ch)) {
            curl_close($ch);
        }

        if ($curl_error) {
            echo "[ERROR] API接続エラー: " . $curl_error_msg . "\n";
            return 'error';
        }

        $res_data = json_decode($response, true);
        if ($http_code < 200 || $http_code >= 300 || !is_array($res_data) || isset($res_data['error'])) {
            $code = isset($res_data['error']['code']) ? $res_data['error']['code'] : 500;
            $msg = isset($res_data['error']['message']) ? $res_data['error']['message'] : 'Unknown error';
            echo "[ERROR] APIエラーレスポンス (HTTP {$http_code}, code: {$code}): {$msg}\n";
            return 'error';
        }

        if (isset($res_data['choices'][0]['message']['content']) && is_string($res_data['choices'][0]['message']['content'])) {
            $text = trim($res_data['choices'][0]['message']['content']);
            $json = json_decode($text, true);
            if (is_array($json) && isset($json['approved']) && is_bool($json['approved'])) {
                return $json['approved'] ? 'approved' : 'ng';
            }
        }

        echo "[WARNING] API応答が解析できませんでした。\n";
        return 'error';
    }
}
