<?php
require_once __DIR__ . '/auth.php';
// require_login(); // ログインしていない場合はlogin.phpにリダイレクト
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>スレッド一覧</title>
    <link rel="stylesheet" href="style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    <div class="container">
        <h1>スレッド一覧</h1>

        <div class="nav-links">
            <p><?= htmlspecialchars($_SESSION['user']['username'], ENT_QUOTES, 'UTF-8') ?>さんとしてログイン中</p>
            <a href="new_thread.php" class="btn btn-primary">新規スレッド作成</a>
            <a href="logout.php" class="btn btn-secondary">ログアウト</a>
        </div>
        
        <p id="apiStatus" aria-live="polite"></p>

        <div id="thread-list"></div>

    </div>

    <script>
    // APIのエンドポイント
    const API_ENDPOINT = 'api.php';

    // HTML要素を取得
    const $apiStatus = document.getElementById('apiStatus');
    const $container = document.getElementById('thread-list'); // HTMLの中から場所を正確に見つける

    /**
     * APIからスレッド一覧を取得して画面に表示する非同期関数
     */
    async function fetchAndDisplayThreads() {
        $apiStatus.textContent = 'スレッドを読み込み中...';
        try {
            //昨日作成したAPIを呼び出す
            const response = await fetch(API_ENDPOINT);

            // エラーチェック
            if (!response.ok) {
                throw new Error(`HTTPエラー: ${response.status}`);
            }

            // レスポンスをJSONとして解析
            const threads = await response.json();

            // 画面表示関数を呼び出す
            displayThreads(threads);
            $apiStatus.textContent = ''; // 読み込み完了したらメッセージを消す

        } catch (error) {
            // エラーが発生した場合
            $apiStatus.textContent = '';
            $container.innerHTML = `<p class="error">読み込みに失敗しました: ${error.message}</p>`;
        }
    }

    /**
     * 受け取ったデータをもとにHTMLを組み立てて画面に表示する関数
     * @param {Array} threads - APIから受け取ったスレッドの配列データ
     */
    function displayThreads(threads) {
        // コンテナを空にする
        $container.innerHTML = '';

        // スレッドが1件もなければメッセージを表示
        if (!Array.isArray(threads) || threads.length === 0) {
            $container.innerHTML = '<p>まだ投稿がありません。</p>';
            return;
        }

        // データの各要素をループしてHTMLを生成
        threads.forEach(thread => {
            // 新しいdiv要素を作成
            const threadElement = document.createElement('div');
            // CSSクラスを付与
            threadElement.className = 'thread-item';

            // HTMLの中身をテンプレートリテラルで組み立てる
            threadElement.innerHTML = `
                <div class="thread-header">
                    <span class="thread-title">${escapeHTML(thread.title)}</span>
                    <span class="thread-meta">投稿者: ${escapeHTML(thread.username)}</span>
                </div>
                <div class="thread-body">
                    <p>${escapeHTML(thread.body)}</p>
                </div>
                <div class="thread-footer">
                    <span>投稿日時: ${thread.created_at}</span>
                </div>
            `;
            
            // コンテナに作成したHTML要素を追加
            $container.appendChild(threadElement);
        });
    }

    /**
     * XSS対策のためのHTMLエスケープ関数
     */
    function escapeHTML(str) {
        return str.replace(/[&<>"']/g, function(match) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[match];
        });
    }

    // ページが読み込まれたときに最初のデータ取得を実行
    document.addEventListener('DOMContentLoaded', fetchAndDisplayThreads);
    </script>
</body>
</html>