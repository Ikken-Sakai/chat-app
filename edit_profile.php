<?php
// ログイン必須
require_once __DIR__ . '/auth.php';
require_login();

// 趣味の選択肢
$hobby_options = [
    '読書', 'ゲーム', '音楽', 'スポーツ', '旅行', 
    '料理', 'カラオケ', 'ドライブ', '映画鑑賞'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>プロフィールの編集</title>
    <link rel="stylesheet" href="style_edit_profile.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* チェックボックス用のスタイル */
        .hobby-options label { display: inline-block; margin-right: 15px; margin-bottom: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>プロフィールの編集</h1>

        <div id="message-area">
            <p id="loading-message">プロフィール情報を読み込み中...</p>
        </div>

        <form id="profileEditForm" style="display: none;">
            
            <div class="form-group">
                <label>ユーザー名</label>
                <input type="text" id="username" name="username" readonly>
                <p class="note">※変更できません</p>
            </div>

            <div class="form-group">
                <label for="department">部署</label>
                <select id="department" name="department">
                    <option value="">選択してください</option>
                    <option value="総務部">総務部</option>
                    <option value="人事部">人事部</option>
                    <option value="営業部">営業部</option>
                    <option value="開発部">開発部</option>
                    <option value="広報部">広報部</option>
                    <option value="経理部">経理部</option>
                    <option value="企画部">企画部</option>
                    <option value="その他">その他</option>
                </select>
            </div>

            <div class="form-group">
                <label>趣味</label>
                <div class="hobby-options">
                    <?php foreach ($hobby_options as $hobby): ?>
                        <label>
                            <input type="checkbox" name="hobbies[]" value="<?= htmlspecialchars($hobby, ENT_QUOTES, 'UTF-8') ?>">
                            <span><?= htmlspecialchars($hobby, ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="note">※複数選択可</p>
            </div>

            <div class="form-group">
                <label for="comment">コメント</label>
                <textarea id="comment" name="comment" rows="4" maxlength="255"></textarea>
            </div>

            <div class="btn-area">
                <a href="profile_list.php" class="btn btn-secondary" id="backBtn">プロフィール一覧に戻る</a>
                <button type="submit" id="submitBtn" class="btn btn-primary">更新</button>
            </div>
        </form>
    </div>

    <script>
    // 設定
    const API_ENDPOINT_GET = 'api.php?action=get_my_profile'; // 自分のプロフィール取得用APIの住所
    const API_ENDPOINT_POST = 'api.php'; // 更新用APIの住所 (actionパラメータは送信データに含める)

    // イベントリスナーの設定
    // HTMLの読み込みが完了したら、指定した関数 (async () => { ... }) を実行する
    document.addEventListener('DOMContentLoaded', async () => {
        // HTML要素の取得
        // これから使うHTML要素を変数に格納
        const form = document.getElementById('profileEditForm');           // フォーム全体
        const messageArea = document.getElementById('message-area');       // メッセージ表示エリア
        const submitBtn = document.getElementById('submitBtn');            // 「更新する」ボタン
        const usernameInput = document.getElementById('username');         // ユーザー名入力欄
        const departmentInput = document.getElementById('department');     // 部署入力欄
        const commentTextarea = document.getElementById('comment');        // コメント入力欄
        // フォームの中から、name属性が"hobbies[]"であるinput要素(チェックボックス)をすべて取得
        const hobbyCheckboxes = form.querySelectorAll('input[name="hobbies[]"]');

        // データ取得とフォーム初期化
        // API通信を try...catch で囲む
        try {
            console.log('Fetching my profile...'); // デバッグ用: 処理開始をコンソールに表示
            // GET APIに自分のプロフィールをfetchし、応答(response)を待つ(await)
            const response = await fetch(API_ENDPOINT_GET);
            
            // APIからエラーが返ってきた場合 (HTTPステータスが200番台でない)
            if (!response.ok) {
                 // エラー応答の中身(JSON)を解析してみる
                 const errorData = await response.json();
                 // エラーメッセージがあればそれ、なければHTTPステータスでエラーを投げる
                 throw new Error(errorData.error || `HTTPエラー: ${response.status}`);
            }
            // 返されたJSONを解析してプロフィール情報を取り出す
            const profile = await response.json();
            console.log('Profile data received:', profile); // デバッグ用: 受け取ったデータを確認

            // フォームに取得した値を設定
            // ユーザー名 (|| '' は、もしデータが空だったら空文字にする、という意味)
            usernameInput.value = profile.username || '';
            // 部署
            departmentInput.value = profile.department || '';
            // コメント
            commentTextarea.value = profile.comment || '';

            // 趣味の設定 (データベースには '読書,ゲーム' のようにカンマ区切りで保存されている想定)
            if (profile.hobbies) { // もし趣味の情報があれば
                // split(',') でカンマを区切り文字として文字列を配列に分解する
                // 例: "読書,ゲーム" -> ["読書", "ゲーム"]
                const selectedHobbies = profile.hobbies.split(','); 
                // すべての趣味チェックボックスについて、1つずつ確認する (forEachループ)
                hobbyCheckboxes.forEach(checkbox => {
                    // もし、そのチェックボックスの値(value)が、分解した配列の中に含まれていたら
                    if (selectedHobbies.includes(checkbox.value)) {
                        checkbox.checked = true; // チェックを入れる
                    }
                });
            }

            // 読み込みメッセージを隠し、フォームを表示する
            messageArea.style.display = 'none'; // メッセージエリアを非表示に
            form.style.display = 'block';     // フォームを表示状態に

        } catch (error) {
            // もし try ブロックの途中でエラーが発生したら、ここが実行される
            console.error('プロファイル読み込みエラー:', error); // エラー内容をコンソールに表示
            // ユーザーにエラーメッセージを表示
            messageArea.innerHTML = `<p class="error">プロファイル情報の読み込みに失敗しました: ${error.message}</p>`;
        }

        // フォーム送信処理
        // フォームで送信(submit)イベントが発生したら、指定した関数 (async (event) => { ... }) を実行する
        form.addEventListener('submit', async (event) => {
            // formが持つデフォルトの送信動作(ページリロード)を止める
            event.preventDefault(); 

            // ボタンを一時的に押せないようにし、「更新中...」と表示する
            submitBtn.disabled = true;
            submitBtn.textContent = '更新中...';

            //フォームから更新後のデータを集める
            const selectedHobbies = []; // 空の配列を用意
            // すべての趣味チェックボックスを調べる
            hobbyCheckboxes.forEach(checkbox => {
                if (checkbox.checked) { // もしチェックが入っていたら
                    selectedHobbies.push(checkbox.value); // 配列に追加する
                }
            });

            // APIに送るためのデータ(JavaScriptオブジェクト)を作成
            const dataToPost = {
                action: 'update_profile', // 「プロフィール更新」の指示を伝える
                department: departmentInput.value, // 部署入力欄の値
                hobbies: selectedHobbies,          // チェックされた趣味の配列
                comment: commentTextarea.value     // コメント入力欄の値
            };
            console.log('Sending data:', dataToPost); // デバッグ用: 送信するデータを確認

            // APIにデータを送信する (失敗する可能性があるので try...catch)
            try {
                // POST APIに更新依頼(fetch)を出す
                const updateResponse = await fetch(API_ENDPOINT_POST, {
                    method: 'POST', // POSTメソッドで送信
                    headers: { 'Content-Type': 'application/json' }, // 送るデータはJSON形式指定
                    body: JSON.stringify(dataToPost) // JavaScriptオブジェクトをJSON文字列に変換して送る
                });
                
                // APIからの応答(JSON)を解析
                const result = await updateResponse.json();
                // もしAPIからエラーが返ってきたら、エラーを投げる
                if (!updateResponse.ok) {
                    throw new Error(result.error || `HTTPエラー: ${updateResponse.status}`);
                }

                //成功した場合
                alert('プロフィールを更新しました。'); // ポップアップでメッセージ表示
                window.location.href = 'profile_list.php'; // プロフィール一覧ページに戻る

            } catch (error) {
                // もし更新処理中にエラーが発生したら
                console.error('プロフィール更新エラー:', error); // エラーをコンソールに表示
                // ユーザーにエラーを通知
                alert('エラー: プロフィールの更新に失敗しました。\n' + error.message);
                // ボタンを押せる状態に戻す
                submitBtn.disabled = false;
                submitBtn.textContent = '更新する';
            }
        });
    });
    // --- 戻るボタンの確認アラート ---
    const backBtn = document.getElementById('backBtn');
    if (backBtn) {
        backBtn.addEventListener('click', (event) => {
            const confirmLeave = confirm('変更内容は保存されません。本当に一覧に戻りますか？');
            if (!confirmLeave) event.preventDefault();
        });
    }
    </script>
</body>
</html>