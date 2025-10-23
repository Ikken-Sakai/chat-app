<?php
// ログイン必須
require_once __DIR__ . '/auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>プロフィール一覧</title>
    <link rel="stylesheet" href="style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* プロフィール表示用の簡易スタイル */
        .profile-item { border: 1px solid #ccc; padding: 15px; margin-bottom: 15px; border-radius: 5px; position: relative; }
        .profile-item h3 { margin-top: 0; margin-bottom: 10px; }
        .profile-item p { margin: 5px 0; }
        .profile-item .edit-link { position: absolute; top: 15px; right: 15px; }
        .pagination { margin-top: 20px; text-align: center; }
        .pagination a, .pagination strong { margin: 0 5px; padding: 5px 8px; text-decoration: none; border: 1px solid #ddd; }
        .pagination strong { background-color: #eee; }
    </style>
</head>
<body>
    <div class="container">
        <h1>プロフィール一覧</h1>

        <div class="nav-links">
            <p><?= htmlspecialchars($_SESSION['user']['username'], ENT_QUOTES, 'UTF-8') ?>さんとしてログイン中</p>
            <a href="thread_list.php" class="btn btn-secondary">スレッド一覧へ</a>
            <a href="logout.php" class="btn btn-secondary">ログアウト</a>
            <button id="refreshBtn" class="btn btn-secondary">↻</button> 
        </div>

        <p id="loading-message" aria-live="polite"></p>

        <div class="sort-controls" style="margin-bottom: 15px;">
            並び替え:
            <button class="sort-btn" data-sort="username" data-order="asc">氏名順 (昇順)</button>
            <button class="sort-btn" data-sort="username" data-order="desc">氏名順 (降順)</button>
            </div>

        <div id="profile-list"></div>

        <div id="pagination" class="pagination"></div>

    </div>
    <script>
        // actionパラメータ追加で、プロフィール情報をAPIにGETリクエスト
        const API_ENDPOINT = 'api.php?action=get_profiles'; 

        // HTML要素の取得
        // 画面上の部品にJavaScriptからアクセスできるように、変数に格納
        const $loadingMessage = document.getElementById('loading-message'); // 「読み込み中...」メッセージ表示エリア
        const $profileList = document.getElementById('profile-list');     // プロフィール一覧を表示するメインエリア
        const $pagination = document.getElementById('pagination');         // ページ番号リンクを表示するエリア
        const $refreshBtn = document.getElementById('refreshBtn');         // 「↻」更新ボタン

        // 状態管理のための変数
        let loggedInUserId = null; // 今ログインしている人のID (APIから教えてもらうまで不明なので null で初期化)
        let currentSort = 'username'; 
        let currentOrder = 'asc';  // 今どの順番で並んでいるか (デフォルトは「氏名順(昇順)」)
        let currentPage = 1;       // 今何ページ目を表示しているか (最初は「1ページ目」)

        /**
         * GETでプロフィール一覧を取得し、画面に表示させる関数
         */
        async function fetchAndDisplayProfiles() {
            //「読み込み中...」と表示する
            $loadingMessage.textContent = 'プロフィールを読み込み中...';
            loggedInUserId = null; // 毎回リセットしておく (念のため)
            
            try {
                // APIに注文を出すためのURLを作る (ソート順とページ番号も伝える)
                const url = `${API_ENDPOINT}&sort=${currentSort}&order=${currentOrder}&page=${currentPage}`;
                console.log('Fetching Profiles:', url); // デバッグ用にURLを確認
                
                // APIに注文(fetch)を出す (awaitでJSONが届くまで待つ)
                const response = await fetch(url);

                // エラーが来たら、処理を中断してエラーを表示
                if (!response.ok) { 
                    // エラーの内容をできるだけ詳しく取得する (複雑なので詳細は省略)
                    let errorText = `HTTPエラー: ${response.status}`;
                    try { 
                        const errorData = await response.json(); 
                        errorText += ` - ${errorData.error || JSON.stringify(errorData)}`;
                    } catch (e) {
                        try { errorText += ` - ${(await response.text()).substring(0,100)}...`; } catch (et) {}
                    }
                    throw new Error(errorText); // catchブロックに処理を移す
                }

                // APIから届いたJSONデータを解析する (awaitで解析が終わるまで待つ)
                const data = await response.json(); 

                // 届いたデータがちゃんとしているかチェック ---
                if (!data || typeof data !== 'object') { 
                    throw new Error('APIからの応答が不正な形式です (オブジェクトではありません)。'); 
                }
                // ログイン中のIDがちゃんと入っているか確認
                if (typeof data.current_user_id === 'number') { 
                    loggedInUserId = data.current_user_id; // あったら箱に入れる
                } else { 
                    console.warn('APIからの応答に current_user_id が含まれていません。'); 
                }

                // ページネーションに必要な情報を取り出す
                const totalPages = data.totalPages || 1;    // 全ページ数 (もしデータがなければ1とする)
                const receivedPage = data.currentPage || 1; // 現在のページ番号 (もしデータがなければ1とする)
                currentPage = receivedPage;                 // 変数currentPageを更新

                // プロフィール一覧のデータがちゃんと配列になっているか確認
                if (Array.isArray(data.profiles)) {
                    // 配列だったら、displayProfilesにデータを渡す
                    displayProfiles(data.profiles); 
                    // updatePaginationUIに情報を渡してリンクを作ってもらう
                    updatePaginationUI(totalPages, currentPage); 
                } else {
                    // 配列じゃなかったらエラーを記録し、「情報なし」として表示
                    console.error('APIからの応答の data.profiles が配列ではありません:', data.profiles);
                    displayProfiles([]); 
                    updatePaginationUI(0, 1); // ページリンクも表示しない
                }
                
                //成功したら「読み込み完了」メッセージを表示
                $loadingMessage.textContent = `読み込み完了 (${data.profiles?.length || 0}件 / ${totalPages}ページ中 ${currentPage}ページ目)`;
                // 2秒後にメッセージを消すタイマーをセット
                setTimeout(() => { 
                    if ($loadingMessage.textContent.startsWith('読み込み完了')) { // まだメッセージが表示されていたら
                        $loadingMessage.textContent = ''; // 消す
                    }
                }, 2000);

            } catch (error) {
                // もし途中で何か問題が起きたら、エラーを記録してユーザーに伝える
                console.error("プロフィール読み込みエラー:", error); 
                $profileList.innerHTML = `<p class="error">読み込みに失敗しました。</p>`;
                $pagination.innerHTML = ''; // ページリンクも消す
                $loadingMessage.textContent = ''; // ローディングメッセージも消す
            } 
        }

        /**
         * 受け取ったプロフィールデータを元にHTMLを作り、画面に表示する関数
         * @param {Array} profiles - プロフィール情報の配列 (空の場合もある)
         */
        function displayProfiles(profiles) {
            $profileList.innerHTML = ''; // まず、表示エリアを空っぽにする
            
            if (!Array.isArray(profiles) || profiles.length === 0) { // もしプロフィールが1件もなければ、「情報がありません」と表示して終了
                $profileList.innerHTML = '<p>プロフィール情報がありません。</p>';
                return;
            }

            // プロフィール情報を1件ずつ取り出して処理する (forEachループ)
            profiles.forEach(profile => {
                const profileElement = document.createElement('div'); // プロフィール1件分の箱 (<div>) を作る
                profileElement.className = 'profile-item'; // CSSで見栄えを整えるためのクラス名
                const isOwner = (loggedInUserId !== null && profile.user_id === loggedInUserId); //自分のプロフィールかを判定する (ログインIDと比較)
                // 自分のであれば、「編集」ボタンのHTMLを作る (そうでなければ空文字)
                const editLink = isOwner ? `
                    <a href="edit_profile.php" class="btn btn-sm btn-secondary edit-link">編集</a>
                ` : ''; 

                // プロフィール情報を表示するHTMLを組み立てる
                //      - 安全のため escapeHTML を通す
                //      - もし情報が空(null)だったら '未設定' などと表示する (|| '未設定')
                profileElement.innerHTML = `
                    ${editLink}
                    <h3>${escapeHTML(profile.username || '不明')}</h3>
                    <p><strong>部署:</strong> ${escapeHTML(profile.department || '未設定')}</p>
                    <p><strong>趣味:</strong> ${escapeHTML(profile.hobbies || '未設定')}</p>
                    <p><strong>コメント:</strong> ${escapeHTML(profile.comment || '未設定')}</p>
                    <small>最終更新: ${escapeHTML(profile.updated_at || '---')}</small>
                `;
                // 組み立てたHTMLを表示エリアに追加する
                $profileList.appendChild(profileElement);
            });
            
            // すべて表示し終わったら、ソーティングボタンを有効化する
            setupSortButtons(); 
        }

        /**
         * ページ上のソートボタンにクリックされたときの動作を教える関数
         */
        function setupSortButtons() {
            document.querySelectorAll('.sort-btn').forEach(button => { // ページ内にある全てのソートボタン (.sort-btn) を見つける
                // ボタンが重複して反応しないように、クローンボタン、処理初期化
                const newButton = button.cloneNode(true);
                if (button.parentNode) button.parentNode.replaceChild(newButton, button);
                
                newButton.removeEventListener('click', handleSortClick); //ボタンから古い命令 (EventListener) を削除
                newButton.addEventListener('click', handleSortClick);    //ボタンに新しい命令 (EventListener) を登録 -> クリックされたら handleSortClick を呼ぶ
            });
        }
        
        /**
         * ソートボタンがクリックされたときに実行される関数
         */
        function handleSortClick(event) {
            const button = event.currentTarget; //どのボタンが押されたかを取得
            //ボタンに設定されている並び替え条件 (data-sort, data-order) を取得
            const sortBy = button.dataset.sort;
            const orderBy = button.dataset.order;
            
            // もし今と同じ条件のボタンなら、何もしないで終了
            if (sortBy === currentSort && orderBy === currentOrder) return; 
            
            // 新しい並び替え条件を記憶する
            currentSort = sortBy;
            currentOrder = orderBy;
            //並び替えたら必ず1ページ目に戻す
            currentPage = 1; 
            
            // fetchAndDisplayProfilesを呼び出して、新しい条件で一覧を再取得・表示させる
            fetchAndDisplayProfiles(); 
        }

        /**
         * ページ番号リンクの見た目を作る関数
         * @param {number} totalPages - 全ページ数
         * @param {number} currentPage - 今表示しているページ番号
         */
        function updatePaginationUI(totalPages, currentPage) {
            // まず表示エリアを空にする
            $pagination.innerHTML = ''; 
            // 1ページしかないなら何も表示しない
            if (totalPages <= 1) return; 
            
            // 「前へ」リンクを作る (もし1ページ目じゃなければ)
            if (currentPage > 1) {
                $pagination.appendChild(createPageLink('« 前へ', currentPage - 1));
            }
            
            // 1ページ目から最後のページまで、ページ番号リンクを作る (forループ)
            for (let i = 1; i <= totalPages; i++) {
                // createPageLink さんにリンク作りをお願いする (今表示中のページは isCurrent = true で渡す)
                $pagination.appendChild(createPageLink(i, i, i === currentPage));
            }
            
            // 「次へ」リンクを作る (もし最後のページじゃなければ)
            if (currentPage < totalPages) {
                $pagination.appendChild(createPageLink('次へ »', currentPage + 1));
            }
        }

        /**
         * ページ番号リンクの作成、1つ分のHTML要素を作るヘルパー関数
         * @param {string|number} label - リンクの文字 (例: '« 前へ', 3, '次へ »')
         * @param {number} page - そのリンクが指すページ番号
         * @param {boolean} isCurrent - それが今表示中のページか (trueなら強調表示)
         * @returns {HTMLElement} - 作られたリンク要素 (<a> か <strong>)
         */
        function createPageLink(label, page, isCurrent = false) {
            // もし今表示中のページ番号なら、クリックできない文字 (<strong>) にする
            if (isCurrent) { 
                const strong = document.createElement('strong'); // <strong>要素を作る
                strong.textContent = label; // 文字を入れる
                strong.style.margin = '0 5px'; // 見た目の調整
                strong.style.padding = '5px 8px';
                return strong; // 作った要素を返す
            }
            
            // そうでなければ、クリックできるリンク (<a>) にする
            const link = document.createElement('a'); // <a>要素を作る
            link.href = '#'; // 実際のページ遷移はしないように '#' を指定
            link.textContent = label; // 文字を入れる
            link.style.margin = '0 5px'; // 見た目の調整
            link.style.padding = '5px 8px';
            
            // リンクに押下時の指示を追加 (addEventListener)
            link.addEventListener('click', (event) => {
                event.preventDefault(); // リンク本来の動き(ページ遷移)を止める
                if (currentPage !== page) { // もし今表示中のページと同じリンクじゃなければ
                    currentPage = page; // 今のページ番号をクリックされた番号に更新
                    fetchAndDisplayProfiles(); // 親方 (fetchAndDisplayProfiles) を呼んで再表示
                }
            });
            return link; // 作った要素を返す
        }

        /**
         * XSS対策のためのHTMLエスケープ関数 (文字を安全な形に変換)
         */
        function escapeHTML(str) {
            // (内容は thread_list.php と同じ)
            return str ? String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]) : '';
        }
        
        // イベントの設定
        // もし「↻」ボタンが存在したら
        if ($refreshBtn) { 
            // 押下時にfetchAndDisplayProfiles) を呼んでね」と教える
            $refreshBtn.addEventListener('click', fetchAndDisplayProfiles); 
        }

        // 最初の実行
        // ブラウザがページのHTMLを全部読み終わったら (DOMContentLoaded)
        // 最初にfetchAndDisplayProfilesを呼んで、プロフィール一覧を表示させる
        document.addEventListener('DOMContentLoaded', fetchAndDisplayProfiles); 
    </script>
</body>
</html>