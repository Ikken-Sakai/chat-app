<?php
// ログインしていないユーザーをログインページにリダイレクト
require_once __DIR__ . '/auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>投稿の編集</title>
    <link rel="stylesheet" href="style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    <div class="container">
        <h1>投稿の編集</h1>

        <div id="message-area">
            <p id="loading-message">投稿データを読み込んでいます...</p>
        </div>

        <form id="editPostForm" style="display: none;">
            <input type="hidden" name="id" value="">

            <div class="form-group">
                <label for="title">スレッドタイトル（編集できません）</label>
                <input type="text" id="title" name="title" readonly>
            </div>
            
            <div class="form-group">
                <label for="body">投稿内容</label>
                <textarea id="body" name="body" rows="8" required></textarea>
            </div>

            <button type="submit" id="submitBtn" class="btn btn-primary">更新する</button>
            <a href="thread_list.php" class="btn btn-secondary" style="margin-top: 10px;">一覧に戻る</a>
        </form>
    </div>

    <script>
    // HTMLの読み込みが完了したら、すぐに処理を開始する
    document.addEventListener('DOMContentLoaded', async () => {
        // これから何度も使うHTML要素を変数に入れておく
        const form = document.getElementById('editPostForm');
        const messageArea = document.getElementById('message-area');
        const submitBtn = document.getElementById('submitBtn');

        //編集対象の投稿データを取得してフォームに表示 ---

        //URLから編集対象の投稿IDを取得する (例: edit_post.php?id=12 -> 12を取得)
        const params = new URLSearchParams(window.location.search);
        const postId = params.get('id');

        //IDが指定されていなければ、エラーを表示して処理を中断
        if (!postId) {
            messageArea.innerHTML = '<p class="error">編集する投稿のIDが指定されていません。</p>';
            return;
        }

        try {
            //APIに問い合わせて、現在の投稿データを取得する
            //GETリクエストで ?id=... を付けてAPIを呼び出す
            const response = await fetch(`api.php?id=${postId}`);
            const post = await response.json();

            // APIからエラーが返された場合 (権限がないなど)
            if (!response.ok) {
                // response.okでない場合、json()で取得したエラーメッセージを投げる
                throw new Error(post.error || `HTTPエラー: ${response.status}`);
            }

            //取得したデータをフォームの各欄に設定する
            form.querySelector('input[name="id"]').value = post.id;
            form.querySelector('input[name="title"]').value = post.title;
            form.querySelector('textarea[name="body"]').value = post.body;

            //読み込みメッセージを消して、フォームを表示する
            messageArea.style.display = 'none';
            form.style.display = 'block';

        } catch (error) {
            // データの取得中にエラーが発生した場合
            messageArea.innerHTML = `<p class="error">データの読み込みに失敗しました: ${error.message}</p>`;
        }

        //フォームが送信されたときの処理を定義 ---

        // form要素でsubmitイベント（更新ボタンのクリック）が発生したら、以下の処理を実行する
        form.addEventListener('submit', async (event) => {
            //デフォルトのフォーム送信（ページリロード）を中止
            event.preventDefault();

            //ボタンを無効化し、ユーザーに処理中であることを示す
            submitBtn.disabled = true;
            submitBtn.textContent = '更新中...';

            //フォームから更新データを準備する
            const dataToPost = {
                id: form.querySelector('input[name="id"]').value, // 隠しフィールドから投稿IDを取得
                body: form.querySelector('textarea[name="body"]').value // テキストエリアから新しい本文を取得
            };

            try {
                // APIに更新データをPOSTで送信する
                const updateResponse = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(dataToPost) // JavaScriptオブジェクトをJSON文字列に変換
                });
                
                const result = await updateResponse.json();
                if (!updateResponse.ok) {
                    throw new Error(result.error || `HTTPエラー: ${updateResponse.status}`);
                }

                //成功したら、メッセージを表示して一覧ページに戻る
                alert('投稿を更新しました。');
                window.location.href = 'thread_list.php';

            } catch (error) {
                // 更新処理中にエラーが発生した場合
                alert('エラー: ' + error.message);
                // ボタンを再度有効化して、再送信できるようにする
                submitBtn.disabled = false;
                submitBtn.textContent = '更新する';
            }
        });
    });
    </script>
</body>
</html>