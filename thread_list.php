<?php
// thread_list.php
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
    <link rel="stylesheet" href="style_thread.css">
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

            <div class="sort-controls-inline">
                <select id="sortSelect" class="sort-select">
                    <option value="created_at_desc">新しい順</option>
                    <option value="created_at_asc">古い順</option>
                    <option value="updated_at_desc">更新順</option>
                </select>
            </div>
        </div>

        
        <p id="loading-message" aria-live="polite"></p>

        <div id="thread-list"></div>
        <div id="pagination" class="pagination"></div>
    </div>


    <script>
        // 初期設定と共通変数
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

        // スレッド一覧取得と表示
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

        // スレッド一覧をHTMLとして表示
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
                
            threadElement.innerHTML = `
                <div class="thread-header">
                    <div class="thread-header-left">
                    <span class="thread-meta">投稿者: ${escapeHTML(thread.username)}</span>
                    </div>
                    <div class="thread-header-right">
                    <span class="thread-date">${thread.created_at}</span>
                    ${thread.updated_at && thread.updated_at !== thread.created_at
                        ? `<small class="edited-label">（編集済み: ${thread.updated_at}）</small>`
                        : ''}
                    </div>
                </div>

                <hr class="title-divider"> 
                <div class="thread-title-line">${escapeHTML(thread.title)}</div>

                <div class="thread-body">
                    <p>${escapeHTML(thread.body)}</p>
                </div>

                <div class="thread-info">
                    <div class="thread-info-left">
                    <button class="show-replies-btn" data-thread-id="${thread.id}" data-reply-count="${thread.reply_count}">
                        返信${thread.reply_count}件
                    </button>
                    </div>
                    <div class="action-buttons">
                    ${thread.user_id === loggedInUserId ? `
                        <button class="btn-edit" onclick="location.href='edit_post.php?id=${thread.id}'">編集</button>
                        <button class="btn-delete delete-btn" data-post-id="${thread.id}">削除</button>
                    ` : ''}
                    </div>
                </div>

                <hr class="divider">

                <div class="replies-container" id="replies-for-${thread.id}" style="display: none;"></div>

                <form class="reply-form" data-parent-id="${thread.id}">
                    <textarea name="body" placeholder="返信を入力..." required rows="2"></textarea>
                    <button type="submit" class="btn-reply">返信する</button>
                </form>
                `;



            //DOMに追加
            $threadList.appendChild(threadElement);
        }); 
            // ループですべてのスレッドを描画し終わった後に、ボタンの準備を一度だけ行う
            setupReplyButtons();
            // 返信フォームの準備を行う関数を呼び出す
            setupReplyForms();
        }

        // ソートセレクト設定
        function setupSortButtons() {
            const sortSelect = document.getElementById('sortSelect');
            if (!sortSelect) return;

            sortSelect.addEventListener('change', () => {
                const selectedValue = sortSelect.value.trim(); // "created_at_desc" など
                const parts = selectedValue.split('_');

                // created_at_asc → ["created", "at", "asc"]
                const orderBy = parts.pop();              // 最後の要素（asc/desc）
                const sortBy = parts.join('_');           // 残りを結合 → "created_at"

                currentSort = sortBy;
                currentOrder = orderBy;
                currentPage = 1;

                //console.log(`選択値: ${selectedValue}`);
                //console.log(`sort=${currentSort}, order=${currentOrder}`);
                //console.log(`送信URL: ${API_ENDPOINT}?sort=${currentSort}&order=${currentOrder}&page=${currentPage}`);

                localStorage.setItem('thread_sort', selectedValue); //ソート設定を localStorage に保存

                fetchAndDisplayThreads(); // 再読み込み
            });
        }



        // ページネーション作成
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

        // 返信関連（表示・投稿）
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

            // forceOpen=false のときだけトグル処理を行う（開閉切り替え）
            if (!forceOpen && repliesContainer.style.display === 'block') {
                // 閉じる前に最新の返信数を取得してボタンの件数を更新
                try {
                    const countRes = await fetch(`${API_ENDPOINT}?parent_id=${parentPostId}&_=${Date.now()}`, {
                        cache: "no-store" // キャッシュを無効化して最新データを取得
                    });
                    if (countRes.ok) {
                        const countData = await countRes.json();
                        const replies = countData.replies || countData; // データ形式に対応
                        const replyCount = countData.count || replies.length; // 件数を取得

                        // 最新の件数をボタンに反映
                        button.dataset.replyCount = replyCount;
                        button.textContent = `返信${replyCount}件`;
                    } else {
                        // 通信エラー時は古い件数をそのまま使う
                        const replyCount = button.dataset.replyCount;
                        button.textContent = `返信${replyCount}件`;
                    }
                } catch {
                    // 通信例外が発生した場合も古い件数をそのまま表示
                    const replyCount = button.dataset.replyCount;
                    button.textContent = `返信${replyCount}件`;
                }

                // 返信一覧を非表示にして終了
                repliesContainer.style.display = 'none';
                return;
            }


            repliesContainer.innerHTML = '<p>返信を読み込み中...</p>';
            repliesContainer.style.display = 'block';

            try {
                const response = await fetch(`${API_ENDPOINT}?parent_id=${parentPostId}&_=${Date.now()}`, {
                    cache: "no-store"
                });
                if (!response.ok) throw new Error(`HTTPエラー: ${response.status}`);

                // APIが {count, replies} 形式でも単純配列でも動作するように
                const data = await response.json();
                const replies = data.replies || data;
                const replyCount = data.count || replies.length;

                repliesContainer.innerHTML = '';

                // 件数をボタンに反映（削除後でも即更新される）
                button.dataset.replyCount = replyCount;
                button.textContent = replyCount === 0 ? '返信0件' : '返信を隠す';

                if (replyCount === 0) {
                    repliesContainer.innerHTML = '<p>この投稿にはまだ返信がありません。</p>';
                    return;
                }
                const MAX_VISIBLE = 2; //3件以上は省略
                // forceOpen（true=全件表示）なら全件、falseなら最新2件だけ
                const visibleReplies = (forceOpen || replies.length <= MAX_VISIBLE)
                    ? replies
                    : replies.slice(-MAX_VISIBLE);


                // 返信の描画
                visibleReplies.forEach(reply => {
                    repliesContainer.appendChild(createReplyElement(reply));
                });

                // 「すべての返信を表示」ボタン（件数非表示）
                if (!forceOpen && replies.length > MAX_VISIBLE) {
                    const showAllBtn = document.createElement('button');
                    showAllBtn.textContent = 'すべての返信を表示';
                    showAllBtn.className = 'show-all-btn';

                    showAllBtn.addEventListener('click', async () => {
                        try {
                            const newResponse = await fetch(`${API_ENDPOINT}?parent_id=${parentPostId}&_=${Date.now()}`, { cache: "no-store" });
                            const newData = await newResponse.json();
                            const latestReplies = newData.replies || newData;

                            repliesContainer.innerHTML = '';
                            latestReplies.forEach(reply => {
                                repliesContainer.appendChild(createReplyElement(reply));
                            });

                            // ボタン削除（2重押下防止）
                            showAllBtn.remove();

                        } catch (error) {
                            repliesContainer.innerHTML = `<p class="error">再読み込みに失敗しました: ${error.message}</p>`;
                        }
                    });

                    repliesContainer.prepend(showAllBtn);
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

            // 改行を<br>に変換した上で、XSS対策を保持
            const formattedBody = escapeHTML(reply.body).replace(/\n/g, '<br>');

            //escapeHTMLを通してXSS攻撃対策
            replyElement.innerHTML = `
                <p>${formattedBody}</p>
                <div class="reply-meta">
                    <div class="reply-left">
                        <span>投稿者: ${escapeHTML(reply.username)}</span>
                    </div>
                    <div class="reply-right">
                        <div class="reply-right-top">
                            ${reply.updated_at && reply.updated_at !== reply.created_at
                                ? `<small class="edited-label">（編集済み）</small>`
                                : ''}
                            <span class="reply-date">投稿日時: ${reply.created_at}</span>
                        </div>
                        <div class="reply-right-buttons">
                            ${reply.user_id === loggedInUserId ? `
                                <button class="btn btn-sm btn-secondary edit-reply-btn" data-reply-id="${reply.id}">編集</button>
                                <button class="btn btn-sm btn-danger delete-btn reply-delete-btn" data-post-id="${reply.id}">削除</button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;

            return replyElement;
        }

        // 返信送信
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

            let result;
            try {
                result = await response.json();
            }catch (err) {
                console.error('JSON解析エラー:', err);
                result = { error: `HTTPエラー: ${response.status}` };
            }
            if (!response.ok) throw new Error(result.error || `HTTPエラー: ${response.status}`);


                // (2) 返信送信が完了したら、返信欄を自動で開く
                const repliesContainer = document.getElementById(`replies-for-${parentId}`);
                repliesContainer.style.display = 'block'; // 非表示なら開く

                // (3) 返信一覧を最新状態に更新（DBから再取得）
                await fetchAndDisplayReplies(parentId, true);

                // (4) 件数ボタンのカウントを更新
                const replyCountButton = document.querySelector(`button[data-thread-id='${parentId}']`);
                replyCountButton.textContent = '返信を隠す'; // 常に開いた状態で表示

                // (5) 入力欄をリセット
                textarea.value = '';

            }catch (err) {
                console.error('JSON解析エラー:', err);
                result = { error: `HTTPエラー: ${response.status}` };


            } finally {
                // (6) ボタンの状態を戻す
                submitButton.disabled = false;
                submitButton.textContent = '返信する';
            }
    }

        // 投稿削除
        /**
         * ページ全体にクリックイベントを設定し、
         * 「削除」ボタンが押されたときのみ削除処理を呼び出す。
         * （イベントデリゲーションで、新しく生成されたボタンにも対応）
         */
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('delete-btn')) { // 削除ボタンが押されたかを判定
                const button = e.target;                     // 押された削除ボタンを取得
                const postId = button.dataset.postId;        // ボタンに埋め込まれた投稿IDを取得
                deletePost(postId, button);                  // 削除処理を呼び出し
            }
        });

        /**
         * 投稿を削除する処理を行う非同期関数
         * @param {string} postId - 削除する投稿のID
         * @param {HTMLElement} buttonElement - クリックされた削除ボタン要素
         */
        async function deletePost(postId, buttonElement) {
            if (!confirm('本当にこの投稿を削除しますか？')) return; // 確認ダイアログでキャンセルされたら処理中止

            //処理中はボタンを無効化、テキストを変更
            buttonElement.disabled = true;         // 二重クリック防止のためボタンを無効化
            buttonElement.textContent = '削除中...'; // ユーザーに処理中であることを表示

            try {
                //APIに削除リクエスト送信
                const response = await fetch(API_ENDPOINT, { // APIに非同期通信で削除リクエストを送る
                    method: 'POST',                          // POSTメソッドを使用
                    headers: { 'Content-Type': 'application/json' }, // JSON形式で送信
                    body: JSON.stringify({
                        action: 'delete', //APIに削除セクションと伝える
                        id: postId        //APIに削除対象のIDを伝える
                    })
                });

                const result = await response.json(); // APIからの応答をJSONとして取得
                if (!response.ok) throw new Error(result.error || `HTTPエラー: ${response.status}`); // エラー時は例外を投げる

                // 返信かどうか判定
                const isReply = buttonElement.classList.contains('reply-delete-btn'); // 返信削除ボタンならtrue

                // DOMから削除
                const postElement = buttonElement.closest(isReply ? '.reply-item' : '.thread-item'); // 投稿または返信のHTML要素を探す
                if (postElement) postElement.remove(); // 画面上から該当の投稿を削除

                // 返信削除時は件数ボタンを更新
                if (isReply) {  
                    const parentThreadItem = buttonElement.closest('.thread-item');   // 削除された返信の親スレッド要素を取得  
                    if (!parentThreadItem) return; // ←親スレッドが見つからない場合は処理中断  

                    const replyCountButton = parentThreadItem.querySelector('.show-replies-btn'); // 「返信○件」ボタンを取得  
                    if (!replyCountButton) return; // ←ボタンが存在しない場合は処理中断 

                    const currentCount = parseInt(replyCountButton.dataset.replyCount || '0', 10); // 現在の返信数を数値として取得（なければ0）  
                    const newCount = Math.max(currentCount - 1, 0);  // 返信を1減らし、0未満にならないように調整  

                    replyCountButton.dataset.replyCount = newCount;  // 新しい返信数をデータ属性に反映  

                    // ここで強制的に再描画（表示状態も維持）
                    const parentId = replyCountButton.dataset.threadId;
                    const repliesContainer = parentThreadItem.querySelector('.replies-container');

                    if (parentId && repliesContainer) {
                        repliesContainer.style.display = 'block'; // 非表示にならないように強制表示
                        repliesContainer.innerHTML = '<p>更新中...</p>'; // ローディング表示
                        await fetchAndDisplayReplies(parentId, true); // 最新状態に再描画

                        // 削除後に「全件表示ボタン」が残っていたら確実に削除
                        const allBtn = repliesContainer.querySelector('.show-all-btn');
                        if (allBtn) allBtn.remove();
                    }

                    // 返信が0件なら「まだ返信がありません」を表示
                    if (newCount === 0) {
                        const repliesContainer = parentThreadItem.querySelector('.replies-container');
                        if (repliesContainer) {
                            repliesContainer.innerHTML = '<p>この投稿にはまだ返信がありません。</p>';
                        }
                    }
                }

                alert('削除しました。'); // 成功メッセージを表示
            } catch (error) {
                alert('エラー: ' + error.message); // エラー発生時はアラートで通知
            
            //エラーが発生したら元の状態に
            } finally {
                buttonElement.disabled = false;     // ボタンを再び有効化
                buttonElement.textContent = '削除'; // ボタンの表示を元に戻す
            }
        }



        // ▼ 返信の編集処理（非同期）
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

                        // 返信メタ情報（右側）を取得
                        const replyRight = replyDiv.querySelector('.reply-right');
                        if (replyRight) {
                            const dateSpan = replyRight.querySelector('.reply-date');
                            if (dateSpan && !replyRight.querySelector('.edited-label')) {
                                dateSpan.before(editedLabel);
                            }
                        }

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



        // XSS対策（文字列エスケープ）
        /**
         * XSS対策のためのHTMLエスケープ関数
         */
        function escapeHTML(str) {
            return str ? String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]) : '';
        }
        
        // 初期化処理
        if ($refreshBtn) { // ボタン要素が確実に見つかった場合のみ設定
             $refreshBtn.addEventListener('click', () => {
                 fetchAndDisplayThreads(); // スレッド一覧を再読み込み
             });
        }

        //ページ読み込み時に実行される処理
        document.addEventListener('DOMContentLoaded', () => {
            const savedSort = localStorage.getItem('thread_sort');// 保存されているソート設定（例："created_at_desc"）を取得
            const sortSelect = document.getElementById('sortSelect');// ソートセレクト要素を取得

            //保存済みの設定があり、セレクトボックスが存在する場合のみ処理
            if (savedSort && sortSelect) {
                sortSelect.value = savedSort; // 画面上のセレクトボックスを前回の設定に戻す

                // 値を分解して、ソート項目と昇順・降順をそれぞれ取り出す
                const parts = savedSort.split('_');
                const orderBy = parts.pop();  // 最後の要素（"asc"または"desc"）
                const sortBy = parts.join('_'); // 残りを結合して "created_at" などにする

                // 現在のソート条件を設定
                currentSort = sortBy;
                currentOrder = orderBy;
            }

            // スレッド一覧を読み込み・表示
            fetchAndDisplayThreads();

            // ソートセレクトのイベントを有効化（選択変更で並び替え可能に）
            setupSortButtons();
        });

    </script>
</body>
</html>