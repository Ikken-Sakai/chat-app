<?php
session_start();
require_once __DIR__ . '/db.php';

// 既にログイン済みの場合はリダイレクト
if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error = '';

// POSTリクエストがあった場合の処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // フォームから送られたデータを取得
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // バリデーション
    if ($username === '' || $password === '' || $password_confirm === '') {
        $error = 'すべての項目を入力してください。';
    } elseif (mb_strlen($username) > 50) {
        $error = 'ユーザー名は50文字以内で入力してください。';
    } elseif ($password !== $password_confirm) {
        $error = 'パスワードが一致しません。';
    } else {
        // ユーザー名が既に使われていないかチェック
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ((int)$stmt->fetchColumn() > 0) {
            $error = 'そのユーザー名は既に使用されています。';
        } else {
            // パスワードをハッシュ化してデータベースに登録
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
            $stmt->execute([$username, $hashed_password]);

            // 登録完了後、成功メッセージ付きでログインページにリダイレクト
            header('Location: login.php?registered=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>新規登録 | 自己紹介アプリ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="container">
    <h1>新規ユーザー登録</h1>

    <?php if ($error !== ''): ?>
      <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <div class="form-group">
        <label for="username">ユーザー名</label>
        <input type="text" id="username" name="username" required>
      </div>

      <div class="form-group">
        <label for="password">パスワード</label>
        <input type="password" id="password" name="password" required>
      </div>

      <div class="form-group">
        <label for="password_confirm">パスワード（確認用）</label>
        <input type="password" id="password_confirm" name="password_confirm" required>
      </div>

      <button type="submit" class="btn btn-success">登録する</button>
      <a href="login.php" class="btn btn-secondary" style="margin-top: 10px;">ログイン画面に戻る</a>
    </form>
  </div>
</body>
</html>