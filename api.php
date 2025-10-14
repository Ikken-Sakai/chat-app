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

//-----------------------------
// GET, POST以外の不正なリクエストに対する処理
//-----------------------------
send_json_response(['error' => '許可されていないメソッドです。'], 405);