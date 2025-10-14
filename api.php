<?php
//---------------------------------------------
// 統合APIファイル (api.php)
//---------------------------------------------

//-----------------------------
// 共通設定の読み込み
//-----------------------------
require_once __DIR__ . '/auth.php';
#require_login(); // ログイン必須
require_once __DIR__ . '/db.php';

//-----------------------------
// レスポンスの形式をJSONに指定
//-----------------------------
header('Content-Type: application/json; charset=utf-8');

//-----------------------------
// リクエストメソッドを取得
//-----------------------------
$method = $_SERVER['REQUEST_METHOD'];

//====================================================
// GETリクエストの処理 (一覧取得)
//====================================================
if ($method === 'GET') {
    try {
        // postsテーブルから親投稿(parentpost_idがNULL)のみを取得するSQL文
        // user_idを元にusersテーブルをJOINし、投稿者名(username)も取得する
        $sql = "
            SELECT
                p.id,
                p.user_id,
                p.title,
                p.body,
                p.created_at,
                p.updated_at,
                u.username
            FROM
                posts AS p
            JOIN
                users AS u ON p.user_id = u.id
            WHERE
                p.parentpost_id IS NULL
            ORDER BY
                p.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 取得したデータをJSON形式でクライアントに返す
        echo json_encode($threads);

    } catch (Exception $e) {
        // エラーが発生した場合は、ステータスコード500でエラーメッセージを返す
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'データベースエラー: ' . $e->getMessage()]);
    }
    exit; // 処理を終了
}

//====================================================
// POSTリクエストの処理 (新規スレッド作成)
//====================================================
if ($method === 'POST') {
    // クライアントから送信されたJSONデータを受け取る
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);

    // バリデーション: titleとbodyが空でないかチェック
    if (empty($data['title']) || empty($data['body'])) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'タイトルと本文は必須です。']);
        exit;
    }

    try {
        // ログイン中のユーザーIDをセッションから取得
        $user_id = $_SESSION['user']['id'];
        $title = $data['title'];
        $body = $data['body'];

        // データベースに新しいスレッド（親投稿）を挿入するSQL文
        $sql = "INSERT INTO posts (user_id, title, body) VALUES (:user_id, :title, :body)";
        
        $stmt = $pdo->prepare($sql);

        // パラメータをバインド(紐づけ)
        // ex) :titleには$titleという変数の内容を必ず入れるように指示
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':title', $title, PDO::PARAM_STR);
        $stmt->bindValue(':body', $body, PDO::PARAM_STR);
        
        $stmt->execute(); // 実行

        // 成功したことをクライアントに伝える
        header('HTTP/1.1 201 Created');
        echo json_encode(['message' => '新しいスレッドが作成されました。']);

    } catch (Exception $e) {
        // エラーが発生した場合
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'データベースエラー: ' . $e->getMessage()]);
    }
    exit;
}

//-----------------------------
// GET, POST以外の不正なリクエストに対する処理
//-----------------------------
send_json_response(['error' => '許可されていないメソッドです。'], 405);