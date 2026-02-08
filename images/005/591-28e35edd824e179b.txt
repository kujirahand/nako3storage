def start_button_on_click(event):
    """開始ボタンをクリックされたときの処理"""  # --- (*1)
    # ボタンを無効化
    document.getElementById("start_button").disabled = True
    # ゲーム状態の初期化
    game["turns"] = GAME_TURNS  # 残りのゲームターン数を初期化
    game["score"] = 0  # スコアを初期化
    # ゲームを開始
    next_turn()

def canvas_on_click(event):
    """canvasをクリックした時の処理"""  # --- (*2)
    # クリックされた位置を取得 --- (*3)
    rect = canvas.getBoundingClientRect()
    click_x = event.clientX - rect.left
    click_y = event.clientY - rect.top
    # モグラが出現しているか？
    if not game["hide"]:
        # モグラの範囲内をクリックしたか？ --- (*4)
        if (game["mx"] <= click_x <= game["mx"] + WIDTH and
            game["my"] <= click_y <= game["my"] + WIDTH):
            game["score"] += 1  # スコアを1点加算
            game["hide"] = True  # モグラを消す
