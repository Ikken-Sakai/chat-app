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
                <a href="thread_list.php" class="btn btn-back">一覧に戻る</a>
                <button type="submit" id="submitBtn" class="btn">作成する</button>
            </div>
        </form>
    </div>

    <script>
    // --- JavaScriptによる非同期フォーム送信 ---
    const form = document.getElementById('newThreadForm');
    const submitBtn = document.getElementById('submitBtn');
    const errorMessage = document.getElementById('error-message');

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
            const response = await fetch('api.php', {
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
            alert('新しいスレッドが作成されました。');
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