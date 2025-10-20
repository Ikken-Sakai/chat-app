<?php
//---------------------------------------------
// 統合APIファイル (api.php)
//---------------------------------------------

// 共通設定の読み込み
require_once __DIR__ . '/auth.php';
require_login(); // ログイン必須
require_once __DIR__ . '/db.php';

// レスポンスの形式をJSONに指定
header('Content-Type: application/json; charset=utf-8');

// リクエストメソッドを取得 -- リクエストがGETかPOSTの振り分け
$method = $_SERVER['REQUEST_METHOD'];

//====================================================
// GETリクエストの処理 (一覧取得 または 返信一覧取得)
//====================================================
if ($method === 'GET') {
    try {
        // (A) URLに "?id=..." が指定されていれば「詳細1件」を返す (編集ページ用)
        if (isset($_GET['id'])) {
            // URLから編集対象の投稿IDを取得
            $post_id = (int)$_GET['id'];
            
            // 投稿IDに一致する投稿をデータベースから取得するSQL
            $sql = "
                SELECT p.id, p.title, p.body, p.user_id 
                FROM posts p 
                WHERE p.id = :id
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $post_id, PDO::PARAM_INT);
            $stmt->execute();
            $post = $stmt->fetch(PDO::FETCH_ASSOC);

            // 権限チェック：投稿が存在しない、または自分のものでない場合はエラー
            if (!$post || $post['user_id'] !== $_SESSION['user']['id']) {
                header('HTTP/1.1 403 Forbidden');
                echo json_encode(['error' => 'この投稿を編集する権限がありません。']);
                exit;
            }

            // 権限チェックを通過したら、投稿データをJSONで返す
            echo json_encode($post);

        // (B) URLに "?parent_id=..." があれば「返信一覧」を返す
        } elseif (isset($_GET['parent_id'])) {
            
            // URLから親投稿のIDを取得。念のため(int)で整数に変換し、安全性を高める
            $parent_id = (int)$_GET['parent_id'];

            // データベースに送る命令文（SQL）を準備
            // parentpost_idが、指定された親投稿のIDと一致する投稿（=返信）だけに絞り込む
            // 返信は会話の流れが分かりやすいように、古い順（昇順 ASC）で並び替える
            $sql = "
                SELECT 
                    p.id, p.user_id, p.body, p.created_at, u.username
                FROM posts AS p
                JOIN users AS u ON p.user_id = u.id
                WHERE p.parentpost_id = :parent_id
                ORDER BY p.created_at ASC
            ";
            
            // SQLインジェクション対策として、まずSQL文の"型枠"だけをデータベースに送って準備
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':parent_id', $parent_id, PDO::PARAM_INT); // 型枠の「:parent_id」の部分に、実際の値を安全に埋め込む（バインドする）
            $stmt->execute(); // 実行
            $replies = $stmt->fetchAll(PDO::FETCH_ASSOC); // 実行結果（返信データ）を全て取得し、PHPの配列に格納する

            echo json_encode($replies); // 取得したPHP配列→JSON形式に変換して、ブラウザに返却
        
        // (C) パラメータがない場合：「親スレッド一覧」を返す
        } else {
            // データベースに送る命令文（SQL）を準備
            // parentpost_idがNULLの投稿（=親投稿）だけに絞り込む
            // 親投稿は、新しいものが一番上に表示されるように降順（DESC）で並び替える

            $sql = "
                SELECT 
                    p.id, 
                    p.user_id, 
                    p.title,
                    p.body, 
                    p.created_at, 
                    p.updated_at,
                    u.username,
                    COUNT(r.id) AS reply_count
                FROM 
                    posts AS p
                JOIN 
                    users AS u ON p.user_id = u.id
                LEFT JOIN 
                    posts AS r ON p.id = r.parentpost_id
                WHERE 
                    p.parentpost_id IS NULL
                GROUP BY 
                    p.id
                ORDER BY 
                    p.created_at DESC
            ";
            
            // SQLを実行する（ユーザーからの入力値がないため、prepare/bindは必須ではないが、統一性のために使用）
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $threads = $stmt->fetchAll(PDO::FETCH_ASSOC); // 実行結果（親スレッドデータ一式）を取得し、PHPの配列に格納
            
            echo json_encode($threads); // 取得したPHP配列→JSON形式に変換して、ブラウザに返却
        }

    } catch (Exception $e) {
        // もしtryブロックの中でデータベースエラーなどが発生したら、ここで処理を中断
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'データベースエラー: ' . $e->getMessage()]); // エラーが発生したことを示すJSONをブラウザに返す
    }
    exit; // GETリクエストの処理はここで終了
}

//====================================================
// POSTリクエストの処理 (新規スレッド作成, 返信、　編集 )
//====================================================
if ($method === 'POST') {
    $json_data = file_get_contents('php://input'); // クライアントから送信されたJSONデータを受け取る
    $data = json_decode($json_data, true);

    try {
        $user_id = $_SESSION['user']['id']; // ログイン中のユーザーIDは共通で取得

        // (A) idが含まれている場合、投稿編集として処理
        if (isset($data['id']) && !empty($data['id'])) {
            // バリデーション
            if (empty($data['body'])) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['error' => '投稿内容を入力してください。']);
                exit;
            }

            $post_id = (int)$data['id']; //編集対象の投稿のIDを保存するための変数(宛先)
            $new_body = $data['body']; //新しい投稿本文を保存するための変数(荷物の中身

            //権限チェック - 編集しようとしている投稿の元の投稿者IDを取得
            $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $stmt->execute([$post_id]);
            $post = $stmt->fetch();

            // 投稿が存在しない、または自分の投稿でない場合はエラー
            if (!$post || $post['user_id'] !== $user_id) {
                header('HTTP/1.1 403 Forbidden');
                echo json_encode(['error' => 'この投稿を編集する権限がありません。']);
                exit;
            }

            // --- データベース更新処理 ---
            $sql = "UPDATE posts SET body = :body, updated_at = NOW() WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':body', $new_body, PDO::PARAM_STR);
            $stmt->bindValue(':id', $post_id, PDO::PARAM_INT);
            $stmt->execute();
            
            echo json_encode(['message' => '投稿が更新されました。']);

        // (B) parentpost_idが含まれている場合、返信投稿として処理
        } elseif (isset($data['parentpost_id']) && !empty($data['parentpost_id'])) {

            //バリデーション：返信内容（bodyが空でないかチェック）
            if (empty($data['body'])) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['error' => '返信内容を入力してください。']);
                exit;
            }

            //返信をDBに挿入するSQL文
            $sql = "INSERT INTO posts (user_id, body, parentpost_id) VALUES (:user_id, :body, :parentpost_id)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':body', $data['body'], PDO::PARAM_STR);
            $stmt->bindValue(':parentpost_id', (int)$data['parentpost_id'], PDO::PARAM_INT);
            $stmt->execute();

            //成功時にクライアントに伝える。
            header('HTTP/1.1 201 Created');
            echo json_encode(['message' => '返信が投稿されました。']);

        // (C) 上記以外は、新規スレッド作成として処理
        } else {
            //バリデーション：titleとbodyが空でないかチェック
            if (empty($data['title']) || empty($data['body'])) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['error' => 'タイトルと本文は必須です。']);
                exit;
            }

            //データベースに新しいスレッドを挿入するSQL文
            $sql = "INSERT INTO posts (user_id, title, body) VALUES (:user_id, :title, :body)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':title', $data['title'], PDO::PARAM_STR);
            $stmt->bindValue(':body', $data['body'], PDO::PARAM_STR);
            $stmt->execute();

            //成功時にクライアントへ伝える
            header('HTTP/1.1 201 Created');
            echo json_encode(['message' => '新しいスレッドが作成されました。']);
        }
    } catch (Exception $e) {
        //エラー処理
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'データベースエラー: ' . $e->getMessage()]);
    }
    exit;
}
