document.addEventListener("DOMContentLoaded", function() {
    const appId = window.app_id;
    const params = new URLSearchParams(window.location.search);
    const editKey = params.get("editkey") || window.n3s_editkey || "";
    const accordionHeader = document.getElementById("comment_accordion_header");
    const accordionContent = document.getElementById("comment_accordion_content");
    const accordionIcon = document.getElementById("comment_accordion_icon");
    const summaryText = document.getElementById("comment_summary_text");
    
    const storageKey = "n3s_comments_open_" + appId;
    
    function loadComments() {
        fetch("api.php?action=comment&mode=list&app_id=" + appId + "&editkey=" + encodeURIComponent(editKey))
            .then(res => res.json())
            .then(data => {
                if (data.result) {
                    const comments = data.comments || [];
                    const count = countTotalComments(comments);
                    summaryText.innerText = "コメントが " + count + " 件あります";
                    renderComments(comments);
                } else {
                    summaryText.innerText = "コメントの読み込みに失敗しました。";
                }
            })
            .catch(err => {
                console.error(err);
                summaryText.innerText = "コメントの読み込み中にエラーが発生しました。";
            });
    }
    
    // コメント数をカウントするヘルパー
    function countTotalComments(comments) {
        let count = comments.length;
        comments.forEach(c => {
            if (c.replies) {
                count += c.replies.length;
            }
        });
        return count;
    }
    
    // コメント一覧を描画する関数
    function renderComments(comments) {
        const area = document.getElementById("comment_list_area");
        if (!area) return;
        
        if (comments.length === 0) {
            area.innerHTML = '<p style="color: #666; font-size: 0.95em; text-align: center; margin: 20px 0;">まだコメントはありません。</p>';
            return;
        }
        
        let html = "";
        comments.forEach(c => {
            const timeStr = formatTime(c.ctime);
            const favClass = c.liked ? "fav-active" : "";
            const isPending = c.status === 'pending';
            const isNg = c.status === 'ng';
            
            let statusLabel = '';
            
            html += `<div class="comment-thread" style="margin-bottom: 25px; border-bottom: 1px solid #f0f0f0; padding-bottom: 20px;">`;
            html += `  <div class="comment-item parent-comment">`;
            html += `    <div class="comment-meta" style="font-size: 0.85em; color: #666; display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">`;
            html += `      <strong style="color: #333;">👤 ${escapeHTML(c.name)}${statusLabel}</strong>`;
            html += `      <span style="color: #999;">${timeStr}</span>`;
            html += `    </div>`;
            
            let bodyStyle = 'margin: 8px 0; white-space: pre-wrap; font-size: 0.95em; line-height: 1.5; color: #222;';
            if (isPending || isNg) {
                bodyStyle += ' color: #888; font-style: italic;';
            }
            html += `    <div class="comment-body" style="${bodyStyle}">${escapeHTML(c.body)}</div>`;
            html += `    <div class="comment-actions" style="font-size: 0.85em; color: #777; display: flex; gap: 15px; align-items: center;">`;
            
            if (!isPending && !isNg) {
                html += `      <button class="comment-fav-btn ${favClass}" onclick="toggleCommentFav(${c.comment_id}, this)" style="background: none; border: none; cursor: pointer; padding: 4px 8px; color: #777; display: inline-flex; align-items: center; gap: 4px; border-radius: 4px; transition: background-color 0.2s;">`;
                html += `        ⭐ <span class="fav-count">${c.fav}</span>`;
                html += `      </button>`;
                
                if (window.n3s_is_login) {
                    html += `      <button onclick="showReplyForm(${c.comment_id})" class="pure-button" style="font-size: 0.9em; padding: 4px 8px; background-color: #f0f4f8; color: #0078d4; border: 1px solid #d0e0f0;">返信</button>`;
                }
            }
            
            if (c.can_delete) {
                html += `      <button onclick="deleteComment(${c.comment_id})" class="pure-button" style="font-size: 0.9em; padding: 4px 8px; background-color: #ffeef0; color: #d93025; border: 1px solid #f0d0d0; margin-left: auto;">削除</button>`;
            }
            
            html += `    </div>`;
            
            if (window.n3s_is_login) {
                if (!isPending && !isNg) {
                    html += `    <!-- 返信フォーム (初期状態非表示) -->`;
                    html += `    <div id="reply_form_container_${c.comment_id}" class="reply-form-container" style="display: none; margin-top: 12px; background-color: #fafafa; padding: 12px; border-radius: 6px; border: 1px solid #e5e5e5;">`;
                    html += `      <form onsubmit="submitReply(event, ${c.comment_id})">`;
                    html += `        <input type="hidden" name="edit_token" value="${window.n3s_edit_token}">`;
                    html += `        <input type="hidden" name="app_id" value="${appId}">`;
                    html += `        <input type="hidden" name="parent_id" value="${c.comment_id}">`;
                    html += `        <input type="hidden" name="editkey" value="${editKey}">`;
                    html += `        <textarea name="body" rows="2" style="width: 100%; box-sizing: border-box; padding: 8px; border-radius: 4px; border: 1px solid #ccc; font-size: 0.95em; margin-bottom: 8px; resize: vertical;" placeholder="返信を入力してください" required></textarea>`;
                    html += `        <div style="text-align: right;">`;
                    html += `          <button type="button" onclick="hideReplyForm(${c.comment_id})" class="pure-button" style="font-size: 0.85em; padding: 4px 10px; margin-right: 5px; background-color: #e0e0e0;">キャンセル</button>`;
                    html += `          <button type="submit" class="pure-button pure-button-primary" style="font-size: 0.85em; padding: 4px 10px;">返信を送信</button>`;
                    html += `        </div>`;
                    html += `      </form>`;
                    html += `    </div>`;
                }
            }
            
            html += `  </div>`;
            
            // 返信（子コメント）のレンダリング
            if (c.replies && c.replies.length > 0) {
                html += `  <div class="comment-replies" style="margin-left: 20px; margin-top: 12px; padding-left: 15px; border-left: 3px solid #e8e8e8; background-color: #fbfbfb; border-radius: 0 4px 4px 0; padding-top: 10px; padding-bottom: 10px;">`;
                c.replies.forEach(r => {
                    const rTimeStr = formatTime(r.ctime);
                    const rFavClass = r.liked ? "fav-active" : "";
                    const isRPending = r.status === 'pending';
                    const isRNg = r.status === 'ng';
                    
                    let rStatusLabel = '';
                    
                    html += `    <div class="comment-item reply-comment" style="margin-bottom: 12px; border-bottom: 1px dashed #eee; padding-bottom: 12px;">`;
                    html += `      <div class="comment-meta" style="font-size: 0.8em; color: #666; display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">`;
                    html += `        <strong style="color: #444;">👤 ${escapeHTML(r.name)}${rStatusLabel}</strong>`;
                    html += `        <span style="color: #999;">${rTimeStr}</span>`;
                    html += `      </div>`;
                    
                    let rBodyStyle = 'margin: 6px 0; white-space: pre-wrap; font-size: 0.9em; line-height: 1.4; color: #333;';
                    if (isRPending || isRNg) {
                        rBodyStyle += ' color: #888; font-style: italic;';
                    }
                    html += `      <div class="comment-body" style="${rBodyStyle}">${escapeHTML(r.body)}</div>`;
                    html += `      <div class="comment-actions" style="font-size: 0.8em; color: #777; display: flex; gap: 10px; align-items: center;">`;
                    
                    if (!isRPending && !isRNg) {
                        html += `        <button class="comment-fav-btn ${rFavClass}" onclick="toggleCommentFav(${r.comment_id}, this)" style="background: none; border: none; cursor: pointer; padding: 2px 6px; color: #777; display: inline-flex; align-items: center; gap: 4px; border-radius: 3px; transition: background-color 0.2s;">`;
                        html += `          ⭐ <span class="fav-count">${r.fav}</span>`;
                        html += `        </button>`;
                    }
                    
                    if (r.can_delete) {
                        html += `        <button onclick="deleteComment(${r.comment_id})" class="pure-button" style="font-size: 0.8em; padding: 2px 6px; background-color: #ffeef0; color: #d93025; border: 1px solid #f0d0d0; margin-left: auto;">削除</button>`;
                    }
                    
                    html += `      </div>`;
                    html += `    </div>`;
                });
                html += `  </div>`;
            }
            
            html += `</div>`;
        });
        
        area.innerHTML = html;
    }
    
    function formatTime(unixTime) {
        const d = new Date(unixTime * 1000);
        return d.getFullYear() + "-" + 
               String(d.getMonth() + 1).padStart(2, '0') + "-" + 
               String(d.getDate()).padStart(2, '0') + " " + 
               String(d.getHours()).padStart(2, '0') + ":" + 
               String(d.getMinutes()).padStart(2, '0');
    }
    
    function escapeHTML(str) {
        return str.replace(/&/g, "&amp;")
                  .replace(/</g, "&lt;")
                  .replace(/>/g, "&gt;")
                  .replace(/"/g, "&quot;")
                  .replace(/'/g, "&#039;");
    }
    
    // アコーディオンの開閉処理
    function openComments() {
        accordionContent.style.display = "block";
        accordionIcon.innerText = "▲";
        accordionHeader.style.backgroundColor = "#eee";
        localStorage.setItem(storageKey, "open");
    }
    
    function closeComments() {
        accordionContent.style.display = "none";
        accordionIcon.innerText = "▼";
        accordionHeader.style.backgroundColor = "#f9f9f9";
        localStorage.setItem(storageKey, "close");
    }
    
    accordionHeader.addEventListener("click", function() {
        if (accordionContent.style.display === "none") {
            openComments();
        } else {
            closeComments();
        }
    });
    
    accordionHeader.addEventListener("mouseenter", function() {
        accordionHeader.style.backgroundColor = "#f0f0f0";
    });
    accordionHeader.addEventListener("mouseleave", function() {
        if (accordionContent.style.display === "none") {
            accordionHeader.style.backgroundColor = "#f9f9f9";
        } else {
            accordionHeader.style.backgroundColor = "#eee";
        }
    });
    
    // 作品公開情報のアコーディオン開閉処理
    const infoHeader = document.getElementById("info_accordion_header");
    const infoContent = document.getElementById("info_accordion_content");
    const infoIcon = document.getElementById("info_accordion_icon");
    
    function openInfo() {
        if (infoContent && infoIcon && infoHeader) {
            infoContent.style.display = "block";
            infoIcon.innerText = "▼";
            infoHeader.style.backgroundColor = "#eee";
            infoHeader.style.borderRadius = "6px 6px 0 0";
        }
    }
    
    function closeInfo() {
        if (infoContent && infoIcon && infoHeader) {
            infoContent.style.display = "none";
            infoIcon.innerText = "▶";
            infoHeader.style.backgroundColor = "#f9f9f9";
            infoHeader.style.borderRadius = "6px";
        }
    }
    
    if (infoHeader) {
        infoHeader.addEventListener("click", function() {
            if (infoContent.style.display === "none") {
                openInfo();
            } else {
                closeInfo();
            }
        });
        
        infoHeader.addEventListener("mouseenter", function() {
            infoHeader.style.backgroundColor = "#f0f0f0";
        });
        infoHeader.addEventListener("mouseleave", function() {
            if (infoContent.style.display === "none") {
                infoHeader.style.backgroundColor = "#f9f9f9";
            } else {
                infoHeader.style.backgroundColor = "#eee";
            }
        });
    }
    
    // 初期状態の復元
    loadComments();
    if (localStorage.getItem(storageKey) === "open") {
        openComments();
    } else {
        closeComments();
    }
    
    // グローバルにバインドする関数（HTML内のonclickで呼び出すため）
    window.showReplyForm = function(commentId) {
        const container = document.getElementById("reply_form_container_" + commentId);
        if (container) {
            container.style.display = "block";
            const textarea = container.querySelector("textarea");
            if (textarea) {
                textarea.focus();
            }
        }
    };
    
    window.hideReplyForm = function(commentId) {
        const container = document.getElementById("reply_form_container_" + commentId);
        if (container) container.style.display = "none";
    };
    
    window.toggleCommentFav = function(commentId, btnEl) {
        const formData = new FormData();
        formData.append("comment_id", commentId);
        formData.append("edit_token", window.n3s_edit_token);
        formData.append("editkey", editKey);
        
        fetch("api.php?action=comment&mode=fav", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.result) {
                const countEl = btnEl.querySelector(".fav-count");
                if (countEl) countEl.innerText = data.fav;
                
                if (data.action === "added") {
                    btnEl.classList.add("fav-active");
                } else {
                    btnEl.classList.remove("fav-active");
                }
            } else {
                alert(data.msg || "いいねの送信に失敗しました。");
            }
        })
        .catch(err => {
            console.error(err);
            alert("通信エラーが発生しました。");
        });
    };
    
    window.submitComment = function(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        formData.append("editkey", editKey);
        
        fetch("api.php?action=comment&mode=add", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.result) {
                const msgArea = document.getElementById("comment_info_message");
                if (msgArea) {
                    msgArea.innerText = data.msg || "コメントを送信しましたが、不適切な内容が含まれていないかAIが審査した後に公開されます。しばらくお待ちください。";
                    msgArea.style.display = "block";
                    setTimeout(() => {
                        msgArea.style.display = "none";
                    }, 15000);
                }
                form.querySelector("textarea").value = "";
                // 投稿したら再読み込み
                loadComments();
            } else {
                alert(data.msg || "送信に失敗しました。");
            }
        })
        .catch(err => {
            console.error(err);
            alert("通信エラーが発生しました。");
        });
    };
    
    window.submitReply = function(e, parentId) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        formData.append("editkey", editKey);
        
        fetch("api.php?action=comment&mode=add", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.result) {
                const msgArea = document.getElementById("comment_info_message");
                if (msgArea) {
                    msgArea.innerText = data.msg || "コメントを送信しましたが、不適切な内容が含まれていないかAIが審査した後に公開されます。しばらくお待ちください。";
                    msgArea.style.display = "block";
                    setTimeout(() => {
                        msgArea.style.display = "none";
                    }, 15000);
                }
                form.querySelector("textarea").value = "";
                hideReplyForm(parentId);
                loadComments();
            } else {
                alert(data.msg || "返信の送信に失敗しました。");
            }
        })
        .catch(err => {
            console.error(err);
            alert("通信エラーが発生しました。");
        });
    };
    
    window.deleteComment = function(commentId) {
        if (!confirm("このコメントを削除しますか？\n(親コメントを削除した場合、返信コメントもすべて削除されます)")) {
            return;
        }
        
        const formData = new FormData();
        formData.append("comment_id", commentId);
        formData.append("edit_token", window.n3s_edit_token);
        formData.append("editkey", editKey);
        
        fetch("api.php?action=comment&mode=delete", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.result) {
                alert(data.msg);
                loadComments();
            } else {
                alert(data.msg || "削除に失敗しました。");
            }
        })
        .catch(err => {
            console.error(err);
            alert("通信エラーが発生しました。");
        });
    };
});
