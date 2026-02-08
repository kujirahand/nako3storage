import random
from js import setTimeout, document

# 定数の宣言
GAME_TURNS=30   #ゲーム終了までのターン数
INTERVAL=1000   #モグラが出現する間隔(ms)
WIDTH=50        #モグラのサイズ(正方形)

#info要素の取得
info=document.getElementById("info")
#canvas要素の取得
canvas=document.getElementById("canvas")
context=canvas.getContext("2d")
#ゲーム全体の流れを辞書型変数で管理
game={
    "turns": GAME_TURNS,    # 残りのゲームターン数
    "score": 0,             # スコア
    "mx":0,                 # モグラx座標
    "my":0,                 # モグラy座標
    "hide":True,            # モグラが隠れているかどうか
}

def next_turn():
    #---次のターンの処理を行う関数
    # 残りのターン数を確認
    if game["turns"] <= 0:
        game_over()
        return
    game["turns"] -=1       # 残りのターン数を減らす
    # モグラの状態を変更して画面を描画
    update_mogura()
    update_screen()
    # 次回のタイマをセット
    setTimeout(next_turn, INTERVAL)

def update_mogura():
    #---モグラの状態を変更する関数
    # モグラが頭を出すかを更新
    game["hide"] = not game["hide"]
    if not game["hide"]:
        # モグラの位置をランダムに決定
        game["mx"]=random.randint(0, canvas.width - WIDTH)
        game["my"]=random.randint(0, canvas.height - WIDTH)

def update_screen():
    #---ゲーム画面の更新処理
    # 画面をクリア
    context.clearRect(0, 0, canvas.width, canvas.height)
    # モグラを描画
    if not game["hide"]:
        context.fillStyle="brown"
        context.fillRect(game["mx"], game["my"], WIDTH, WIDTH)
    # スコアを更新
    info.innerText=(f"スコア：{game['score']}点／"
                    f"残り時間：{game['turns']}")

def game_over():
    #---ゲーム終了時の処理
    # 最終スコアを表示
    info.innerText=f"もぐら叩き終了：スコア{game['score']}点"
    # ゲーム開始ボタンを有効化
    document.getElementById("start_button").disable=False