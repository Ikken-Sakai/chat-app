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
            <button id="refreshBtn" class="btn btn-secondary">↻</button>
        </div>
        
        <p id="loading-message" aria-live="polite"></p>

        <div id="thread-list"></div>

    </div>


    <script>
        const API_ENDPOINT = 'api.php'; // APIのエンドポイント

        // HTML要素を取得
        const $loadingMessage = document.getElementById('loading-message');
        const $threadList = document.getElementById('thread-list'); //HTMLの箱(掲示板全体)
        const $refreshBtn = document.getElementById('refreshBtn'); // 更新ボタン要素を取得
        //PHPからログイン中のユーザ名をJavascript変数に埋め込み
        const LOGGED_IN_USERNAME = "<?= htmlspecialchars($_SESSION['user']['username'], ENT_QUOTES, 'UTF-8') ?>";
        //ログイン中のユーザIDも保持
        let loggedInUserId = null;

        /**
         * APIからスレッド一覧を取得して画面に表示する非同期関数
         */
        async function fetchAndDisplayThreads() {
            $loadingMessage.textContent = 'スレッドを読み込み中...';
            try {
                //apiにGETリクエスト送信
                const response = await fetch(API_ENDPOINT);
                if (!response.ok) {
                    throw new Error(`HTTPエラー: ${response.status}`);
                }
                // APIからのレスポンス(オブジェクト)を一旦 data 変数で受け取る
                const data = await response.json(); 
                // グローバル変数にログインユーザーIDを保存
                loggedInUserId = data.current_user_id; 
                // displayThreadsには、オブジェクトの中からthreads配列だけを渡す
                displayThreads(data.threads);

                //成功したら、更新しましたメッセージ表示
                $loadingMessage.textContent = '一覧を更新しました。';
                // 2秒後にメッセージを自動的に消す
                setTimeout(() => {
                    // メッセージがまだ「更新しました」の場合のみ消す
                    // (連続クリックなどで「読み込み中」に変わっていたら消さない)
                    if ($loadingMessage.textContent === '一覧を更新しました。') {
                        $loadingMessage.textContent = '';
                    }
                }, 2000); // 2000ミリ秒 = 2秒

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

            //threadsを1つづつ取り出し
            threads.forEach(thread => {
                const threadElement = document.createElement('div');
                threadElement.className = 'thread-item';

                // 各スレッドのHTMLテンプレート。返信フォームを追加
                // 自分の投稿かどうかを判定 (APIから取得したログインIDと比較)
                const isOwner = (thread.user_id === loggedInUserId);
                
                // 編集・削除ボタンのHTMLを条件付きで生成
                // isOwnerがtrueの場合のみボタンのHTMLを生成、falseなら空文字
                const ownerActions = isOwner ? `
                    <a href="edit_post.php?id=${thread.id}" class="btn btn-sm btn-secondary">編集</a>
                    <button class="btn btn-sm btn-danger delete-btn" data-post-id="${thread.id}">🗑️</button>
                ` : '';

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
                        ${ownerActions}
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
            // 削除ボタンの準備を行う関数を呼び出す
            setupDeleteButtons();
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
         * @param {string} parentpostid - 返信を取得する親スレッドのid
         * @param {boolean} forceOpen - trueの場合、閉じる動作を無効化して常に開く
         */
        async function fetchAndDisplayReplies(parentPostId, forceOpen = false) {
            const repliesContainer = document.getElementById(`replies-for-${parentPostId}`);
            const button = document.querySelector(`[data-thread-id='${parentPostId}']`);

            // forceOpen=false のときだけトグル処理を行う
            if (!forceOpen && repliesContainer.style.display === 'block') {
                repliesContainer.style.display = 'none';
                const replyCount = button.dataset.replyCount;
                button.textContent = `返信${replyCount}件`;
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
                    // 返信を表示した後、内部に追加された削除ボタンにもイベントを設定する
                    setupDeleteButtons();
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

            // 返信の所有者か判定 (loggedInUserIdはグローバル変数)
            //loggedInUserIdがnullでないことも確認
            const isReplyOwner = (loggedInUserId !== null && reply.user_id === loggedInUserId);
            // 所有者なら編集・削除ボタンのHTMLを生成 (返信ボタンには識別用クラスも付与)
            const replyOwnerActions = isReplyOwner ?`
                <a href="edit_post.php?id=${reply.id}" class="btn btn-sm btn-secondary reply-edit-btn">編集</a>
                <button class="btn btn-sm btn-danger delete-btn reply-delete-btn" data-post-id="${reply.id}">🗑️</button>
            ` : '';

            //wscapeHTMLを通してXSS攻撃対策
            replyElement.innerHTML = `
                <p>${escapeHTML(reply.body)}</p>
                <div class="reply-meta">
                    <span>投稿者: ${escapeHTML(reply.username)}</span>
                    <span>投稿日時: ${reply.created_at}</span>
                    ${replyOwnerActions}
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
            event.preventDefault(); // ページのリロードを防止

            const form = event.target;
            const textarea = form.querySelector('textarea');
            const submitButton = form.querySelector('button');
            const parentId = form.dataset.parentId;

            submitButton.disabled = true;
            submitButton.textContent = '送信中...';

            try {
                //APIにPOST送信（bodyとparentpost_idを送る）
                // api.php の POST 内「(C)返信投稿処理」が実行される
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

                // (2) 返信送信が完了したら、返信欄を自動で開く
                const repliesContainer = document.getElementById(`replies-for-${parentId}`);
                repliesContainer.style.display = 'block'; // 非表示なら開く

                // (3) 返信一覧を最新状態に更新（DBから再取得）
                await fetchAndDisplayReplies(parentId, true);

                // (4) 件数ボタンのカウントを更新
                const replyCountButton = document.querySelector(`button[data-thread-id='${parentId}']`);
                const currentCount = parseInt(replyCountButton.dataset.replyCount) || 0;
                const newCount = currentCount + 1;
                replyCountButton.dataset.replyCount = newCount;
                replyCountButton.textContent = '返信を隠す'; // 常に開いた状態で表示

                // (5) 入力欄をリセット
                textarea.value = '';

            } catch (error) {
                alert('エラー: ' + error.message);
            } finally {
                // (6) ボタンの状態を戻す
                submitButton.disabled = false;
                submitButton.textContent = '返信する';
            }
    }

        /**
         * ページ上の全ての削除ボタンにクリックイベントを設定する関数
         */
        function setupDeleteButtons() {
            // '.delete-btn' というクラスを持つ全てのボタン要素を取得
            document.querySelectorAll('.delete-btn').forEach(button => {
                // 同じボタンに何度もイベントを追加しないように、古いイベントを削除して新しいイベントを設定
                // cloneNode(true) でボタンを複製し、replaceWithで元のボタンと入れ替える
                const newButton = button.cloneNode(true);
                // 元のボタンの親要素が存在する場合のみ置換を実行 (削除済みの要素へのアクセスを防ぐ)
                if (button.parentNode) {
                    button.parentNode.replaceChild(newButton, button);
                }
                
                // 新しいボタンにクリックイベントリスナーを追加
                newButton.removeEventListener('click', handleDeleteButtonClick); // 既存のリスナーを削除
                newButton.addEventListener('click', handleDeleteButtonClick); // 新しい関数を参照
            });
        }
        
        // 削除ボタンクリック時のイベントハンドラ関数を分離 (deletePostを呼び出すだけ)
        function handleDeleteButtonClick(event) {
             const button = event.currentTarget; // クリックされたボタン要素
             const postId = button.dataset.postId;
             deletePost(postId, button); 
        }

        /**
         * 投稿を削除する処理を行う非同期関数
         * @param {string} postId - 削除する投稿のID
         * @param {HTMLElement} buttonElement - クリックされた削除ボタン要素
         */
        async function deletePost(postId, buttonElement) {
            // ユーザーに最終確認のダイアログを表示
            if (!confirm('本当にこの投稿を削除しますか？')) {
                return; // 「キャンセル」が押されたら何もしないで処理を終了
            }

            // 処理中はボタンを無効化し、テキストを変更
            buttonElement.disabled = true;
            buttonElement.textContent = '削除中...';

            try {
                // APIに削除リクエストを送信
                const response = await fetch(API_ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete', // APIに削除アクションであることを伝える
                        id: postId        // APIに削除対象のIDを伝える
                    })
                });

                const result = await response.json();
                if (!response.ok) {
                    // APIからエラーが返された場合
                    throw new Error(result.error || `HTTPエラー: ${response.status}`);
                }

                // 画面からの即時削除
                //削除要素の特定方法を修正
                //親投稿か返信化で削除する要素を切り替え
                const postElement = buttonElement.closest('.reply-item') || buttonElement.closest('.thread-item');
                if (postElement) {
                    // 親投稿(.thread-item) または 返信(.reply-item) の要素をDOMツリーから削除
                    postElement.remove(); 
                    // もし削除したのが返信なら、親投稿の返信件数も更新
                    if (buttonElement.classList.contains('reply-delete-btn')) {
                        // 削除された返信要素から、親のスレッド要素を探す
                        const parentThreadItem = postElement.closest('.thread-item');
                        if (parentThreadItem) {
                            // 親スレッド要素の中から返信件数ボタンを探す
                            const replyCountButton = parentThreadItem.querySelector('.show-replies-btn');
                            // ボタンのdata属性から現在の件数を取得し、数値に変換
                            const currentCount = parseInt(replyCountButton.dataset.replyCount);
                            // 件数が有効な数値で、かつ0より大きい場合のみ処理
                            if (!isNaN(currentCount) && currentCount > 0) {
                                // 件数を1減らす
                                const newCount = currentCount - 1;
                                // ボタンのdata属性と表示テキストを更新
                                replyCountButton.dataset.replyCount = newCount;
                                const repliesContainer = parentThreadItem.querySelector('.replies-container');
                                // 返信欄が開いている状態か閉じた状態かでテキストを調整
                                if (repliesContainer.style.display === 'block') {
                                     replyCountButton.textContent = '返信を隠す'; // 開いていたら隠すボタンのまま
                                } else {
                                     replyCountButton.textContent = `返信${newCount}件`;
                                }
                                // もし最後の返信だったら「まだ返信がありません」を表示 (開いている場合のみ)
                                if (newCount === 0 && repliesContainer.style.display === 'block') {
                                     repliesContainer.innerHTML = '<p>この投稿にはまだ返信がありません。</p>';
                                }
                            }
                        }
                    }
                }
                alert('削除しました。'); // メッセージ修正

            } catch (error) {
                // エラーが発生した場合
                alert('エラー: ' + error.message);
                // エラーが発生したらボタンの状態を元に戻す
                buttonElement.disabled = false;
                buttonElement.textContent = '削除';
            } 
            // finally ブロックは削除処理では不要 (成功したら要素ごと消えるため)
        }

        /**
         * XSS対策のためのHTMLエスケープ関数
         */
        function escapeHTML(str) {
            return str ? String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]) : '';
        }
        
        if ($refreshBtn) { // ボタン要素が確実に見つかった場合のみ設定
             $refreshBtn.addEventListener('click', () => {
                 console.log('更新ボタンがクリックされました。'); // デバッグ用ログ
                 fetchAndDisplayThreads(); // スレッド一覧を再読み込み
             });
        }

        // ページが読み込まれたときに最初のデータ取得を実行
        document.addEventListener('DOMContentLoaded', fetchAndDisplayThreads);
    </script>
</body>
</html>