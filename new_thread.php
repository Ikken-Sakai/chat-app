<?php
// ログインしていないユーザーをログインページにリダイレクト
require_once __DIR__ . '/auth.php';
require_login();

// CSRF対策用のトークンを生成
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];


?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新規スレッド作成</title>
    <link rel="stylesheet" href="style_new_thread.css">
</head>
<body>
    <div class="container">
        <h1>新しいスレッドを作成</h1>
        <!-- エラーを表示する場所（非表示で準備） -->
        <p id="error-message" class="error" style="display: none;"></p>
        <!-- 新規スレッド作成フォーム -->
        <form id="newThreadForm">
            <div class="form-header">
                <div class="username-box">
                    <?= htmlspecialchars($_SESSION['user']['username'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <input type="text" id="title" name="title" class="title-input" placeholder="スレッドタイトル" required>
            </div>
            <textarea id="body" name="body" class="body-input" placeholder="投稿内容" required></textarea>
            <!-- CSRFトークン（セキュリティ対策用） -->
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <div class="button-group">
                <a href="thread_list.php" class="btn btn-back" id="backBtn">一覧に戻る</a>
                <button type="submit" id="submitBtn" class="btn">作成する</button>
            </div>
        </form>
    </div>

    <script>

        /**
         * fetchのラッパー関数(元ある関数を包む新しい関数)。セッションタイムアウト(401)を共通処理する。
         * @param {string} url - リクエスト先のURL
         * @param {object} [options] - fetchに渡すオプション (method, bodyなど)
         * @returns {Promise<Response>} fetchのレスポンスオブジェクト
         */
        async function apiFetch(url, options) {
            const response = await fetch(url, options);

            // 応答が401 Unauthorizedなら、セッション切れと判断
            if (response.status === 401) {
                alert('セッションタイム切れのため、ログイン画面にもどります');
                window.location.href = 'login.php'; // ログインページにリダイレクト
                
                //エラーをthrowする代わりに、後続の処理を停止させる
                return new Promise(() => {});
            }

            return response; // 正常な場合はそのままレスポンスを返す
        }
        // --- JavaScriptによる非同期フォーム送信 ---
        const form = document.getElementById('newThreadForm');
        const submitBtn = document.getElementById('submitBtn');
        const errorMessage = document.getElementById('error-message');

        // --- 戻るボタンの確認アラート処理 ---
        const backBtn = document.getElementById('backBtn');
        if (backBtn) {
            backBtn.addEventListener('click', async (event) => {
                // 1. デフォルトの <a href="..."> によるページ遷移をまず止める
                event.preventDefault(); 

                try {
                    // 2. まずセッションが有効かダミーAPIで確認する
                    // (apiFetchがセッション切れを検知したら、アラートを出してリダイレクトし、ここで処理が停止する)
                    await apiFetch('api.php?action=check_session');

                    // 3. セッションが有効だった場合のみ、確認ダイアログを出す
                    const confirmLeave = confirm('変更内容は保存されません。本当に一覧に戻りますか？');
                    if (confirmLeave) {
                        // OKなら本来のリンク先(href属性の値)に遷移する
                        window.location.href = backBtn.href; 
                    }
                    // キャンセルなら何もしない

                } catch (error) {
                    // apiFetchがエラー（Session expired以外）を投げた場合
                    // (セッション切れの場合はapiFetch内でリダイレクト処理が始まり、ここは実行されない)
                    console.error("Session check failed:", error);
                    alert("エラーが発生しました: " + error.message);
                }
            });
        }


        form.addEventListener('submit', async (event) => {
            // デフォルトのフォーム送信（画面遷移）を中止
            event.preventDefault();

            // ボタンを無効化し、ユーザーに処理中であることを示す
            submitBtn.disabled = true;
            submitBtn.textContent = '送信中...';
            errorMessage.style.display = 'none';

            // フォームから送信するデータを準備
            const formData = new FormData(form);
            const data = {
                title: formData.get('title'),
                body: formData.get('body'),
                token: formData.get('token') // CSRFトークンも送信
            };

            try {
                // api.phpにデータをPOSTリクエストで送信
                const response = await apiFetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data) // JavaScriptオブジェクトをJSON文字列に変換
                });

                const result = await response.json();

                // APIからエラーが返された場合
                if (!response.ok) {
                    throw new Error(result.error || `HTTPエラー: ${response.status}`);
                }

                // 成功した場合
                //alert('新しいスレッドが作成されました。');
                window.location.href = 'thread_list.php'; // 一覧ページに遷移

            } catch (error) {
                // エラーが発生した場合
                errorMessage.textContent = 'エラー: ' + error.message;
                errorMessage.style.display = 'block';
                
                // ボタンを再度有効化して、再送信できるようにする
                submitBtn.disabled = false;
                submitBtn.textContent = '作成する';
            }
        });
    </script>
</body>
</html>