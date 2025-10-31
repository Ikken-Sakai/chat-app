<?php
//--------------------------------------------
// 認証・セッション管理共通ファイル（auth.php）
//--------------------------------------------
// 各ページの冒頭で require_once して使用。
// ログイン必須チェック・アイドルタイムアウト（一定時間無操作で自動ログアウト）を一括管理。

//-----------------------------
// セッション開始
//-----------------------------
session_start();

//-----------------------------
// アイドルタイムアウトの制限時間設定
//-----------------------------
// （例）10分＝600秒。テスト時は短縮・延長
const IDLE_LIMIT =  30;

//-----------------------------
// ログイン必須関数
//-----------------------------
// 各ページで require_login() を呼び出すことで、未ログイン時は login.php へ強制リダイレクト。
function require_login(): void {
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

//-----------------------------
// アイドルタイムアウト処理
//-----------------------------
// 最終操作時刻を確認して、無操作時間がIDLE_LIMITを超えていれば自動ログアウト。
$now  = time();                               // 現在時刻（UNIXタイムスタンプ）
$last = $_SESSION['last_active'] ?? $now;     // 最後のアクセス時刻（初回は現在時刻）

// タイムアウト判定
if (($now - $last) > IDLE_LIMIT) {
    //-----------------------------
    // タイムアウト時の処理
    //-----------------------------
    // セッション情報を全削除
    $_SESSION = [];

    // Cookieを使用している場合はCookieも削除
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }

    // セッション破棄 + ログインページへ戻す
    session_destroy();
    // APIリクエストかどうかで応答を切り替える
    if (defined('IS_API_REQUEST') && IS_API_REQUEST) {
        // APIリクエストの場合：401 UnauthorizedヘッダーとJSONエラーを返す
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'セッションがタイムアウトしました。']);
    } else {
        // 通常のページアクセスの場合：ログインページへリダイレクト
        header('Location: login.php?expired=1');
    }
    exit;
}

//-----------------------------
// アクセスがあった場合：最終操作時刻を更新
//-----------------------------
$_SESSION['last_active'] = $now;
?>