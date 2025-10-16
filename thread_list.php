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
        
        <p id="loading-message" aria-live="polite"></p>

        <div id="thread-list"></div>

    </div>


    <script>
        const API_ENDPOINT = 'api.php'; // APIのエンドポイント

        // HTML要素を取得
        const $loadingMessage = document.getElementById('loading-message');
        const $threadList = document.getElementById('thread-list'); //HTMLの箱(掲示板全体)

        /**
         * APIからスレッド一覧を取得して画面に表示する非同期関数
         */
        async function fetchAndDisplayThreads() {
            $loadingMessage.textContent = 'スレッドを読み込み中...';
            try {
                const response = await fetch(API_ENDPOINT);
                if (!response.ok) {
                    throw new Error(`HTTPエラー: ${response.status}`);
                }
                const threads = await response.json();
                displayThreads(threads);
                $loadingMessage.textContent = '';

            } catch (error) {
                $loadingMessage.textContent = '';
                $threadList.innerHTML = `<p class="error">読み込みに失敗しました: ${error.message}</p>`;
            }
        }

        /**
         * 受け取ったデータをもとにHTMLを組み立てて画面に表示する関数
         * @param {Array} threads - APIから受け取ったスレッドの配列データ

         * threadList: 掲示板全体（投稿が一覧表示される場所）
         * threadElement: 新しい投稿一つ分（タイトル、名前、本文などが含まれる）
         * appendChild(): 掲示板に新しい投稿を貼り付ける（追加する）行為
         */
        function displayThreads(threads) {
            $threadList.innerHTML = '';
            if (!Array.isArray(threads) || threads.length === 0) {
                $threadList.innerHTML = '<p>まだ投稿がありません。</p>';
                return;
            }

            threads.forEach(thread => {
                const threadElement = document.createElement('div');
                threadElement.className = 'thread-item';
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
                        <button class="show-replies-btn" data-thread-id="${thread.id}">返信〇件</button>
                    </div>
                    <div class="replies-container" id="replies-for-${thread.id}" style="display: none;"></div>
                `;
                $threadList.appendChild(threadElement);
            });
            
            // ループですべてのスレッドを描画し終わった後に、ボタンの準備を一度だけ行う
            setupReplyButtons();
        }

        /**
         * ページ上の全ての「返信〇件」ボタンにクリックイベントを設定する関数
         */
        function setupReplyButtons() {
            const buttons = document.querySelectorAll('.show-replies-btn'); //すべてのHTML分の中から返信ボタンを取り出し格納
            buttons.forEach(button => {
                // 同じボタンに何度もイベントを追加しないように、一度クリア(更新)
                // 2,3回ボタンを押したときにその回数分の処理をしてしまわないように
                button.replaceWith(button.cloneNode(true));
            });
            
            // クリックイベントの更新
            document.querySelectorAll('.show-replies-btn').forEach(button => {
                button.addEventListener('click', () => {
                    const threadId = button.dataset.threadId;
                    fetchAndDisplayReplies(threadId);
                });
            });
        }

        /**
         * 特定のスレッドIDに対する返信を取得し、表示/非表示を切り替える非同期関数
         * @param {string} parentPostId - 返信を取得する親スレッドのID
         */
        async function fetchAndDisplayReplies(parentPostId) {
            const repliesContainer = document.getElementById(`replies-for-${parentPostId}`);
            const button = document.querySelector(`[data-thread-id='${parentPostId}']`);

            if (repliesContainer.style.display === 'block') {
                repliesContainer.style.display = 'none';
                button.textContent = '返信〇件';
                return;
            }

            repliesContainer.innerHTML = '<p>返信を読み込み中...</p>';
            repliesContainer.style.display = 'block';

            try {
                const response = await fetch(`${API_ENDPOINT}?parent_id=${parentPostId}`);
                if (!response.ok) {
                    throw new Error(`HTTPエラー: ${response.status}`);
                }
                const replies = await response.json();
                repliesContainer.innerHTML = '';

                if (replies.length === 0) {
                    repliesContainer.innerHTML = '<p>この投稿にはまだ返信がありません。</p>';
                } else {
                    replies.forEach(reply => {
                        const replyElement = document.createElement('div');
                        replyElement.className = 'reply-item';
                        replyElement.innerHTML = `
                            <p>${escapeHTML(reply.body)}</p>
                            <div class="reply-meta">
                                <span>投稿者: ${escapeHTML(reply.username)}</span>
                                <span>投稿日時: ${reply.created_at}</span>
                            </div>
                        `;
                        repliesContainer.appendChild(replyElement);
                    });
                }
                button.textContent = '返信を隠す';

            } catch (error) {
                repliesContainer.innerHTML = `<p class="error">返信の読み込みに失敗しました: ${error.message}</p>`;
            }
        }

        /**
         * XSS対策のためのHTMLエスケープ関数
         */
        function escapeHTML(str) {
            // ... (この関数は変更なし) ...
            return str ? String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]) : '';
        }

        // ページが読み込まれたときに最初のデータ取得を実行
        document.addEventListener('DOMContentLoaded', fetchAndDisplayThreads);
    </script>
</body>
</html>