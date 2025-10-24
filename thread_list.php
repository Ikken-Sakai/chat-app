<?php
//---------------------------------------------
// thread_list.php
//---------------------------------------------
/*
【概要】
このページは掲示板の「スレッド一覧画面」を表示するメイン画面。
JavaScriptが非同期通信 (fetch API) を用いて `api.php` にアクセスし、
スレッド一覧・返信・削除などの操作を行う。

【主な処理構成】
1. PHP部（上部）
   - ログインチェックとユーザー名の取得。
   - HTML構造の表示。

2. JavaScript部（下部）
   - GETリクエストでスレッド一覧を取得・描画。
   - POSTリクエストで返信投稿・削除・編集を実行。
   - ページネーション、ソート、削除、返信フォーム、XSS対策などの実装。

【セキュリティ対策】
- PHP側：
    - `require_login()` によるログイン未認証ユーザのアクセス制限。
    - `htmlspecialchars()` によるXSS防止（ユーザー名など出力時にエスケープ）。
- JavaScript側：
    - `escapeHTML()` によるXSS防止（投稿内容・ユーザー名の表示時）。
    - confirm()による削除確認。
    - fetch通信でのエラーハンドリングと入力バリデーション。

【通信フロー】
ブラウザ (JavaScript)
   ↓  fetch()
   →  api.php（GET/POST）
   ←  JSONデータ（スレッド・返信・メッセージなど）
   ↓
   DOMに反映（スレッド一覧表示） DOM=HTMLをJavaScriptで

*/

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
            <a href="logout.php" class="btn btn-secondary">ログアウト</a>
            <a href="new_thread.php" class="btn btn-primary">新規投稿</a>
            <a href="profile_list.php" class="btn btn-secondary">プロフィール一覧へ</a>
            <button id="refreshBtn" class="btn btn-secondary">↻</button>
        </div>
        <div class="sort-controls">
            <button class="sort-btn" data-sort="created_at" data-order="desc">新しい順</button>
            <button class="sort-btn" data-sort="created_at" data-order="asc">古い順</button>
            <button class="sort-btn" data-sort="updated_at" data-order="desc">更新順</button>
        </div>
        
        <p id="loading-message" aria-live="polite"></p>

        <div id="thread-list"></div>
        <div id="pagination" class="pagination"></div>
    </div>


    <script>
        //============================================================
        // 初期設定と共通変数
        //============================================================
        const API_ENDPOINT = 'api.php'; // API呼び出し先

        // HTML要素を取得
        const $loadingMessage = document.getElementById('loading-message');
        const $threadList = document.getElementById('thread-list'); //HTMLの箱(掲示板全体)
        const $refreshBtn = document.getElementById('refreshBtn'); // 更新ボタン要素を取得

        //PHPからログイン中のユーザ情報を取得しJavascript変数に埋め込み
        const LOGGED_IN_USERNAME = "<?= htmlspecialchars($_SESSION['user']['username'], ENT_QUOTES, 'UTF-8') ?>";
        //ログイン中のユーザIDも保持
        let loggedInUserId = null;

        // 現在のソート順とページ番号を保持する変数
        let currentSort = 'created_at'; // デフォルト: 作成日時
        let currentOrder = 'desc';      // デフォルト: 降順
        let currentPage = 1;            // デフォルト: 1ページ目

        //============================================================
        // スレッド一覧取得と表示
        //============================================================
        async function fetchAndDisplayThreads() {
            $loadingMessage.textContent = 'スレッドを読み込み中...';
            try {
                //APIエンドポイントにソートとページパラメータを追加
                const url = `${API_ENDPOINT}?sort=${currentSort}&order=${currentOrder}&page=${currentPage}`;
                const response = await fetch(url); //apiにGETリクエスト送信、一覧取得

                if (!response.ok) {
                    throw new Error(`HTTPエラー: ${response.status}`);
                }
                // APIからのレスポンス(オブジェクト)を一旦 data 変数で受け取る
                const data = await response.json(); 
                // グローバル変数にログインユーザーIDを保存
                loggedInUserId = data.current_user_id; 
                // displayThreadsには、オブジェクトの中からthreads配列だけを渡す
                displayThreads(data.threads);

                //ページ情報の取得とUI更新を追加
                const totalPages = data.totalPages || 1; // APIから総ページ数を取得 (なければ1)
                const receivedPage = data.currentPage || 1; // APIから現在のページ番号を取得 (なければ1)
                currentPage = receivedPage; // currentPageをAPIからの値で更新

                if (Array.isArray(data.threads)) {
                     displayThreads(data.threads);
                     // ページネーションUIを更新
                     updatePaginationUI(totalPages, currentPage); 
                } else {
                     console.error('API応答の data.threads が配列ではありません:', data.threads);
                     displayThreads([]); 
                     updatePaginationUI(0, 1); // エラー時はページネーションもクリア
                }

                //成功したら、更新しましたメッセージ表示
                $loadingMessage.textContent = '一覧を更新しました。';
                // メッセージがまだ「更新しました」の場合のみ消す
                // (連続クリックなどで「読み込み中」に変わっていたら消さない)
                if ($loadingMessage.textContent === '一覧を更新しました。') {
                    $loadingMessage.textContent = '';
                }
            } catch (error) {
                $loadingMessage.textContent = '';
                $threadList.innerHTML = `<p class="error">読み込みに失敗しました: ${error.message}</p>`;
            }
        }

        //============================================================
        // スレッド一覧をHTMLとして表示
        //============================================================
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
                        <span class="thread-meta">投稿者: ${escapeHTML(thread.username)}</span>
                        <span class="thread-title">${escapeHTML(thread.title)}</span>
                    </div>
                    <div class="thread-body">
                        <p>${escapeHTML(thread.body)}</p>
                        ${thread.updated_at && thread.updated_at !== thread.created_at
                            ? `<small class="edited-label">（編集済み: ${thread.updated_at}）</small>`
                            : ''}
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

        //============================================================
        // ソートボタン設定
        //============================================================
        /**
         * ページ上のソートボタンにクリックイベントを設定する関数
         */
        function setupSortButtons() {
            document.querySelectorAll('.sort-btn').forEach(button => {
                // 既存のリスナーを削除（念のため）
                const newButton = button.cloneNode(true);
                button.parentNode.replaceChild(newButton, button);

                newButton.addEventListener('click', () => {
                    const sortBy = newButton.dataset.sort;
                    const orderBy = newButton.dataset.order;

                    // 現在のソート条件と同じボタンが押されたら何もしない
                    if (sortBy === currentSort && orderBy === currentOrder) return; 

                    console.log(`ソート変更: ${sortBy} ${orderBy}`);
                    currentSort = sortBy;
                    currentOrder = orderBy;
                    currentPage = 1; // ソート順を変えたら1ページ目に戻す
                    fetchAndDisplayThreads(); // 再読み込み
                });
            });
        }

        //============================================================
        // ページネーション作成
        //============================================================
        /**
         * ページネーションのUIを生成・表示する関数
         * @param {number} totalPages - 総ページ数
         * @param {number} currentPage - 現在のページ番号
         */
        function updatePaginationUI(totalPages, currentPage) {
            const $pagination = document.getElementById('pagination');

            $pagination.innerHTML = ''; // まず中身を空にする

            // 「前へ」リンク (1ページ目じゃなければ表示)
            if (currentPage > 1) {
                $pagination.appendChild(createPageLink('« 前へ', currentPage - 1));
            }

            // ページ番号リンク (簡易版：全ページ表示)
            // (ページ数が多い場合は「...」で省略するロジックが必要になることも)
            for (let i = 1; i <= totalPages; i++) {
                $pagination.appendChild(createPageLink(i, i, i === currentPage));
            }

            // 「次へ」リンク (最終ページじゃなければ表示)
            if (currentPage < totalPages) {
                $pagination.appendChild(createPageLink('次へ »', currentPage + 1));
            }
        }

        //============================================================
        // 返信関連（表示・投稿）
        //============================================================
        /**
         * ページネーションのリンク要素（<a>または<strong>）を作成するヘルパー関数
         * @param {string|number} label - リンクの表示テキスト
         * @param {number} page - リンク先のページ番号
         * @param {boolean} isCurrent - 現在のページかどうか (trueなら強調表示)
         * @returns {HTMLElement} - 生成されたリンク要素
         */
        function createPageLink(label, page, isCurrent = false) {
            // 現在のページ番号はリンクではなく強調表示 (<strong>)
            if (isCurrent) {
                const strong = document.createElement('strong');
                strong.textContent = label;
                strong.style.margin = '0 5px'; // 見た目の調整
                strong.style.padding = '5px 8px';
                return strong;
            }
            
            // それ以外のページ番号はクリック可能なリンク (<a>)
            const link = document.createElement('a');
            link.href = '#'; // ページ遷移を防ぐため # を指定
            link.textContent = label;
            link.style.margin = '0 5px'; // 見た目の調整
            link.style.padding = '5px 8px';
            link.addEventListener('click', (event) => {
                event.preventDefault(); // デフォルトのリンク動作を無効化
                if (currentPage !== page) { // 現在のページと同じリンクは無視
                    console.log(`ページ移動: ${page}ページ目へ`);
                    currentPage = page; // 現在のページ番号を更新
                    fetchAndDisplayThreads(); // スレッド一覧を再取得
                }
            });
            return link;
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
                if (!response.ok) throw new Error(`HTTPエラー: ${response.status}`);
                const replies = await response.json();
                repliesContainer.innerHTML = '';

                if (replies.length === 0) {
                    repliesContainer.innerHTML = '<p>この投稿にはまだ返信がありません。</p>';
                } else {
                    // 最大3件まで表示（古い順で、下に新しい返信）
                    const MAX_VISIBLE = 2;
                    const visibleReplies = replies.length > MAX_VISIBLE
                        ? replies.slice(-MAX_VISIBLE)
                        : replies;

                    // 返信の描画
                    visibleReplies.forEach(reply => {
                        repliesContainer.appendChild(createReplyElement(reply));
                    });

                    // 3件より多い場合は「全件表示」ボタンを上に追加
                    if (replies.length > MAX_VISIBLE) {
                        const showAllBtn = document.createElement('button');
                        showAllBtn.textContent = `全${replies.length}件の返信をすべて表示`;
                        showAllBtn.className = 'show-all-btn';
                        showAllBtn.addEventListener('click', () => {
                            repliesContainer.innerHTML = '';
                            replies.forEach(reply => {
                                repliesContainer.appendChild(createReplyElement(reply));
                            });
                            showAllBtn.remove();
                        });
                        repliesContainer.prepend(showAllBtn);
                    }

                    // 削除ボタンを再設定
                    setupDeleteButtons();
                }

                // 返信表示中にボタンのテキストを変更
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
            const replyOwnerActions = isReplyOwner ? `
                <button class="btn btn-sm btn-secondary edit-reply-btn" data-reply-id="${reply.id}">編集</button>
                <button class="btn btn-sm btn-danger delete-btn reply-delete-btn" data-post-id="${reply.id}">🗑️</button>
            ` : '';

            //wscapeHTMLを通してXSS攻撃対策
            replyElement.innerHTML = `
                <p>${escapeHTML(reply.body)}</p>
                <div class="reply-meta">
                    <span>投稿者: ${escapeHTML(reply.username)}</span>
                    <span>投稿日時: ${reply.created_at}</span>
                    ${reply.updated_at && reply.updated_at !== reply.created_at
                        ? `<small class="edited-label">（編集済み: ${reply.updated_at}）</small>`
                        : ''}

                ${replyOwnerActions}
                </div>
            `;
            return replyElement;
        }

        //============================================================
        // 返信送信
        //============================================================
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

        //============================================================
        // 投稿削除
        //============================================================
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

        //--------------------------------------------------------------
        // ▼ 返信の編集処理（非同期）
        //--------------------------------------------------------------
        document.addEventListener('click', async function (e) {
        if (e.target.classList.contains('edit-reply-btn')) {
            const replyDiv = e.target.closest('.reply-item');
            const replyId = e.target.dataset.replyId;
            const bodyP = replyDiv.querySelector('p');
            const oldText = bodyP.textContent;

            // 編集用フォームに変換
            const textarea = document.createElement('textarea');
            textarea.value = oldText;
            textarea.classList.add('edit-textarea');
            bodyP.replaceWith(textarea);

            // 保存ボタンを追加
            const saveBtn = document.createElement('button');
            saveBtn.textContent = '保存';
            saveBtn.classList.add('btn', 'btn-sm', 'btn-primary');
            e.target.after(saveBtn);
            e.target.disabled = true;

            saveBtn.addEventListener('click', async () => {
                const newText = textarea.value.trim();
                if (!newText) {
                    alert('本文を入力してください');
                    return;
                }

                try {
                    const res = await fetch(API_ENDPOINT, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'edit_reply',
                            reply_id: replyId,
                            body: newText
                        })
                    });

                    const result = await res.json();
                    if (result.success) {
                        // 成功時、本文を即時更新
                        const newBody = document.createElement('p');
                        newBody.textContent = result.new_body;
                        textarea.replaceWith(newBody);

                        // 編集済みラベルを追加
                        const editedLabel = document.createElement('small');
                        editedLabel.classList.add('edited-label');
                        editedLabel.textContent = '（編集済み）';
                        newBody.after(editedLabel);

                        saveBtn.remove();
                        e.target.disabled = false;
                    } else {
                        alert(result.error || '更新に失敗しました');
                    }
                } catch (err) {
                    console.error('通信エラー:', err);
                    alert('サーバー通信に失敗しました');
                }
            });
        }
    });



        //============================================================
        // XSS対策（文字列エスケープ）
        //============================================================
        /**
         * XSS対策のためのHTMLエスケープ関数
         */
        function escapeHTML(str) {
            return str ? String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]) : '';
        }
        
        //============================================================
        // 初期化処理
        //============================================================
        if ($refreshBtn) { // ボタン要素が確実に見つかった場合のみ設定
             $refreshBtn.addEventListener('click', () => {
                 fetchAndDisplayThreads(); // スレッド一覧を再読み込み
             });
        }

        // ページが読み込まれたときに最初のデータ取得を実行
        document.addEventListener('DOMContentLoaded', () => {
            fetchAndDisplayThreads();
            setupSortButtons(); 
        });
    </script>
</body>
</html>