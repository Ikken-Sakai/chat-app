<?php
//--------------------------------------------
// データベース接続共通ファイル（db.php）
//--------------------------------------------
// 各ページで require_once して使用。
// PDOを用いてMySQLデータベースに安全に接続する。

//-----------------------------
// 接続情報（環境に合わせて変更）
//-----------------------------
$dsn      = 'mysql:host=localhost;dbname=chat_app;charset=utf8mb4'; // 接続先・DB名・文字コード
$db_user  = 'root';    // MySQLユーザー名
$db_pass  = '';        // MySQLパスワード（XAMPPは空欄がデフォルト）

//-----------------------------
// PDOによる接続処理
//-----------------------------
// PDO（PHP Data Objects）はDB操作の標準的な方法。
// try-catchで例外処理を行うことで、安全に接続を確認。
try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // エラー時に例外を投げる（開発時に有用）
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // 取得結果を連想配列として扱う
        PDO::ATTR_EMULATE_PREPARES   => false,                   // プリペアドステートメントのエミュレーションを無効化
        PDO::ATTR_STRINGIFY_FETCHES  => false,                   // 数値を文字列に変換しない（型を保持）
    ]);
    
    // MySQLのタイムゾーンをJST（Asia/Tokyo）に設定
    $pdo->exec("SET time_zone = '+09:00'");
} catch (PDOException $e) {
    //-----------------------------
    // 接続失敗時の処理
    //-----------------------------
    // 本番環境では内部ログ出力が望ましいが、
    // 学習用のため簡易メッセージのみを画面に表示。
    exit('データベース接続に失敗しました。設定を確認してください。');
}
?>
