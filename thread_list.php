<?php
require_once __DIR__ . '/auth.php';
require_login(); // ログインしていない場合はlogin.phpにリダイレクト
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

        const LOGGED_IN_USERNAME = "<?= htmlspecialchars($_SESSION['user']['username'], ENT_QUOTES, 'UTF-8') ?>";

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
                // 各スレッドのHTMLテンプレート。返信フォームを追加
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
                        <button class="show-replies-btn" data-thread-id="${thread.id}" data-reply-count="${thread.reply_count}">返信${thread.reply_count}件</button>
                    </div>
                    <div class="replies-container" id="replies-for-${thread.id}" style="display: none;"></div>
                    
                    <form class="reply-form" data-parent-id="${thread.id}">
                        <textarea name="body" placeholder="返信を入力..." required rows="2"></textarea>
                        <button type="submit">返信する</button>
                    </form>
                `;
                $threadList.appendChild(threadElement);
            });
            
            // ループですべてのスレッドを描画し終わった後に、ボタンの準備を一度だけ行う
            setupReplyButtons();
            // 返信フォームの準備を行う関数を呼び出す
            setupReplyForms();
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
                const replyCount = button.dataset.replyCount; // data属性から件数を取得
                button.textContent = `返信${replyCount}件`;   // 取得した件数でテキストを生成
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
                        // 返信要素の生成をヘルパー関数の処理で
                        repliesContainer.appendChild(createReplyElement(reply));
                    });
                }
                button.textContent = '返信を隠す';
            } catch (error) {
                repliesContainer.innerHTML = `<p class="error">返信の読み込みに失敗しました: ${error.message}</p>`;
            }
        }

        /**
         * 返信一件分のHTML要素を生成するヘルパー関数
         * @param {object} reply - 返信データ（body, username, created_atを含む）
         * @returns {HTMLElement} - 生成されたdiv要素
         */
        function createReplyElement(reply) {
            const replyElement = document.createElement('div'); //返信を囲む<dvi>要素を作成
            replyElement.className = 'reply-item'; //cssクラス名
            //wscapeHTMLを通してXSS攻撃対策
            replyElement.innerHTML = `
                <p>${escapeHTML(reply.body)}</p>
                <div class="reply-meta">
                    <span>投稿者: ${escapeHTML(reply.username)}</span>
                    <span>投稿日時: ${reply.created_at}</span>
                </div>
            `;
            return replyElement;
        }

        /**
         * ページ上の全ての返信フォームにイベントを設定する関数
         */
        function setupReplyForms() {
            document.querySelectorAll('.reply-form').forEach(form => {
                form.addEventListener('submit', submitReply);
            });
        }

        /**
         * 返信フォームが送信されたときの処理を非同期で行う関数
         * @param {Event} event - submitイベントオブジェクト
         */
        async function submitReply(event) {
            event.preventDefault(); // デフォルトのフォーム送信（ページリロード）を中止
            
            const form = event.target; //送信されたformを取得
            const textarea = form.querySelector('textarea'); //form中のtextarea要素を見つけて取得
            const submitButton = form.querySelector('button'); //form中のbutton要素を取得
            const parentId = form.dataset.parentId; //formのdata-parent-idから返信先の親投稿IDを取得

            submitButton.disabled = true; //二度押し防止
            submitButton.textContent = '送信中...';

            try {
                const response = await fetch(API_ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        body: textarea.value,
                        parentpost_id: parentId
                    })
                });

                if (!response.ok) {
                    const result = await response.json();
                    throw new Error(result.error || `HTTPエラー: ${response.status}`);
                }

                // --- 画面への即時反映処理 ---
                //新規の返信を表示させるための<div>要素をIDで指定
                const repliesContainer = document.getElementById(`replies-for-${parentId}`);
                //返信がない表示があった場合、エラーになるのを防ぐ
                if (repliesContainer.querySelector('p')?.textContent.includes('まだ返信がありません')) {
                    //メッセージを消去し、新規返信をできるように
                    repliesContainer.innerHTML = '';
                }
                
                //画面に新しい返信データを作成
                const newReply = {
                    body: textarea.value,
                    username: LOGGED_IN_USERNAME, //投稿者名はログイン中のユーザ名
                    created_at: 'たった今'
                };
                //上記のデータを使って、HTML生成・返信コンテナの末尾に追加
                repliesContainer.appendChild(createReplyElement(newReply));

                //返信件数カウンターの表示更新
                const replyCountButton = document.querySelector(`button[data-thread-id='${parentId}']`);
                //ボタンのdata属性のカウント＋1
                const newCount = parseInt(replyCountButton.dataset.replyCount) + 1;
                replyCountButton.dataset.replyCount = newCount; //新しい件数で上書き
                if (repliesContainer.style.display === 'block') {
                    replyCountButton.textContent = '返信を隠す';
                } else {
                    replyCountButton.textContent = `返信${newCount}件`;
                }

                textarea.value = ''; // テキストエリアをクリア

            } catch (error) {
                alert('エラー: ' + error.message);
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = '返信する';
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