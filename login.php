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
// 既にログイン済みなら index.php へリダイレクト
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

            header('Location: index.php');
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
  <title>ログイン | 自己紹介アプリ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="style.css"> </head>
<body>
  <div class="container">
  <h1>ログイン</h1>

    <?php if (isset($success)): ?>
      <div class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <label for="username">ユーザー名</label>
      <input type="text" id="username" name="username" required value="<?= isset($username) ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') : '' ?>">
      <label for="password">パスワード</label>
      <input type="password" id="password" name="password" required>
      <button type="submit" class="btn btn-primary">ログイン</button>
      <a href="register.php" class="btn btn-secondary">新規登録</a>
    </form>

    <?php if (!empty($allUsers)): ?>
      <table class="user-table" id="userTable">
        <thead>
          <tr>
            <th>登録されているユーザー</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allUsers as $u): ?>
            <tr>
              <td><?= htmlspecialchars($u, ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      
      <button id="showMoreBtn" class="btn-show-more">さらに表示</button>

    <?php endif; ?>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
      // 定数: 初期表示件数と追加表示件数
      const INITIAL_SHOW = 5;
      const SHOW_PER_CLICK = 10;

      // HTML要素を取得
      const userTable = document.getElementById('userTable');
      const showMoreBtn = document.getElementById('showMoreBtn');

      // テーブルやボタンが存在しない、またはユーザーが5人以下の場合は処理を中断
      if (!userTable || !showMoreBtn || userTable.querySelectorAll('tbody tr').length <= INITIAL_SHOW) {
          // もしユーザーが5人以下なら、最初から全て表示されているので何もしない
          if(userTable && userTable.querySelectorAll('tbody tr').length <= INITIAL_SHOW) {
              // ボタンは不要なので非表示のまま
          }
          return;
      }

      const allRows = userTable.querySelectorAll('tbody tr');
      const totalRows = allRows.length;
      let visibleCount = 0;

      // 表示状態を更新する関数
      function updateVisibility() {
          // 全ての行をループ
          allRows.forEach((row, index) => {
              // 表示件数内であれば表示、それ以外は非表示
              row.style.display = (index < visibleCount) ? '' : 'none';
          });

          // 全ての行が表示されたら「さらに表示」ボタンを隠す
          if (visibleCount >= totalRows) {
              showMoreBtn.style.display = 'none';
          } else {
              showMoreBtn.style.display = 'block';
          }
      }

      // 「さらに表示」ボタンのクリックイベント
      showMoreBtn.addEventListener('click', () => {
          // 表示件数を増やす
          visibleCount += SHOW_PER_CLICK;
          updateVisibility();
      });

      // --- 初期化 ---
      visibleCount = INITIAL_SHOW;
      updateVisibility();
  });
  </script>

</body>
</html>