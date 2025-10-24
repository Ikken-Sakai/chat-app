<?php
//--------------------------------------------
// ログインページ（login.php）
//--------------------------------------------

//-----------------------------
// 初期設定・DB接続
//-----------------------------
session_start();
require_once __DIR__ . '/db.php';

//-----------------------------
// 既にログイン済みなら thread_list.php へリダイレクト
//-----------------------------
if (isset($_SESSION['user'])) {
    header('Location: thread_list.php');
    exit;
}

$error = '';

//-----------------------------
// ログインフォーム送信処理
//-----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'ユーザー名とパスワードを入力してください。';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id'       => (int)$user['id'],
                'username' => (string)$user['username'],
            ];
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['last_active'] = time();

            header('Location: thread_list.php');
            exit;
        } else {
            $error = 'ユーザー名またはパスワードが正しくありません。';
        }
    }
}

//-----------------------------
// 登録済みユーザー名一覧を取得
//-----------------------------
$userStmt = $pdo->query("SELECT username FROM users ORDER BY created_at ASC");
$allUsers = $userStmt->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>ログイン | スレッドアプリ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="login-container">
    <h1>ログイン</h1>

    <?php if ($error !== ''): ?>
      <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <label for="username">ユーザー名</label>
      <input type="text" id="username" name="username" required value="<?= isset($username) ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') : '' ?>">

      <label for="password">パスワード</label>
      <input type="password" id="password" name="password" required>

      <button type="submit">ログイン</button>
    </form>

    <form action="register.php" method="get">
      <button type="submit" class="register-btn">新規登録</button>
    </form>
  </div>
</body>
</html>