<?php
if (empty($contents)) $contents = 'no contents';

// header
include dirname(__FILE__) . '/parts_html_header.tpl.php';
?>

    <h1>プログラムの一覧</h1>

<?php if ($list): ?>
    <div class="list">
    <table>
        <tr>
            <th>タイトル</th>
            <th>制作者</th>
            <th>メモ</th>
            <th>日付</th>
        </tr>
        <?php
        foreach ($list as $row) {
            $app_id = intval($row['app_id']);
            $title_h = htmlentities(mb_strimwidth($row['title'], 0, 14, '..'));
            $author_h = htmlentities(mb_strimwidth($row['author'], 0, 12, '..'));
            $memo_h = htmlentities(mb_strimwidth($row['memo'], 0, 14, '..'));
            $date_h = date("Y/m/d", $row['mtime']);
            // 空白をチェック
            if (!$title_h) $title_h = '(無題)';
            if (!$author_h) $author_h = '(匿名)';
            echo <<< EOS
<tr>
<td><a href="index.php?app_id={$app_id}&action=show">{$app_id}: {$title_h}</a></td>
<td>{$author_h}</td>
<td>{$memo_h}</td>
<td>{$date_h}</td>
</tr>
EOS;
        }
        ?>
    </table>
    <div><?php
        if ($next_url) echo "<a href='$next_url'>次へ←</a>";
        ?></div>
<?php else: ?>
    <div>ありません。</div>
<?php endif; ?>
<?php
// footer
include dirname(__FILE__) . '/parts_html_footer.tpl.php';
