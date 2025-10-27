<?php
/*
======================================================================
統合APIファイル (api.php)

 【概要】
 本ファイルは、掲示板・プロフィール機能のすべてのデータ通信(API)を一括で処理する。
 JavaScript側からfetch()で呼び出され、JSON形式でデータを返す。

 【構成】
   ■ GETメソッド（データ取得系）
      ├ action=get_my_profile    ：自分のプロフィールを取得
      ├ action=get_profiles      ：プロフィール一覧を取得（ページング・ソート対応）
      ├ id=...                   ：特定の投稿(編集用)を取得
      ├ parent_id=...            ：特定スレッドへの返信一覧を取得
      └ (上記以外)              ：スレッド一覧を取得（ページング・ソート対応）

   ■ POSTメソッド（データ更新系）
      ├ action=update_profile    ：プロフィール新規登録・更新
      ├ action=delete            ：投稿削除
      ├ id=...                   ：投稿編集
      ├ parentpost_id=...        ：返信投稿
      └ (上記以外)              ：新規スレッド作成

 【セキュリティ対策】
   ・require_login() によりログイン必須
   ・PDO + プリペアドステートメントでSQLインジェクション防止 (不正なDBへの命令を防止)
   ・型キャスト(int)で不正なIDアクセス防止
   ・自分の投稿/プロフィールのみ編集・削除可能（権限チェック）
   ・JSON形式で安全にデータを返却（HTML出力なし）
======================================================================
*/

//--------------------------------------------------------------
// 共通設定の読み込みと初期設定
//--------------------------------------------------------------
require_once __DIR__ . '/auth.php';
require_login(); // ログインしていない場合はlogin.phpへリダイレクト
require_once __DIR__ . '/db.php';

// 返却データをJSONに指定
header('Content-Type: application/json; charset=utf-8');

// リクエストがGETかPOSTの振り分け
$method = $_SERVER['REQUEST_METHOD'];

//====================================================
// GETリクエストの処理 (一覧取得、　返信一覧取得、　詳細一件取得、　プロフィール一覧取得　、自分のプロフィール取得)
//====================================================
// GET: プロフィール/投稿の取得系エンドポイント（分岐は上部コメントを参照）
if ($method === 'GET') {
    try {
        // URLに "?action=get_my_profile" が指定されていれば「自分のプロフィール」を返す
        // GET: ログイン中ユーザーのプロフィール取得
        if (isset($_GET['action']) && $_GET['action'] === 'get_my_profile') {
            $user_id = $_SESSION['user']['id']; // ログイン中のユーザーIDを取得

            // usersテーブルとprofilesテーブルを結合して、該当ユーザーの情報を取得
            $sql = "
                SELECT 
                    u.username, 
                    p.department, 
                    p.hobbies, 
                    p.comment 
                FROM 
                    users AS u
                LEFT JOIN 
                    profiles AS p ON u.id = p.user_id
                WHERE 
                    u.id = :user_id
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);

            // もしユーザーが見つからなければエラー 
            if (!$profile) {
                 header('HTTP/1.1 404 Not Found');
                 echo json_encode(['error' => 'ユーザーが見つかりません。']);
                 exit;
            }

            echo json_encode($profile); // プロフィールデータをJSONで返す

        // URLに "?action=get_profiles" が指定されていれば「プロフィール一覧」を返す
        // GET: プロフィール一覧（ページング・並び順）
        }elseif (isset($_GET['action']) && $_GET['action'] === 'get_profiles') {
            
            // 1ページあたりの表示件数(10ページずつ)
            $limit = 10; 


            // 並び替え方向（昇順asc/降順desc）, デフォルトは昇順
            $order = $_GET['order'] ?? 'asc';

            // 現在のページ番号取得。URLパラメータで?page=2など指定された場合に対応。指定がなければ1ページ目に設定
            $page = isset($_GET['page']) && filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                ? (int)$_GET['page']
                : 1;

            //全件取得
            //LEFT JOINでプロフィール未登録のユーザも一覧に出せる「未設定」で表示
            $sql = "
                SELECT 
                    u.id AS user_id, 
                    u.username, 
                    p.department, 
                    p.hobbies, 
                    p.comment,
                    p.updated_at 
                FROM 
                    users AS u
                LEFT JOIN 
                    profiles AS p ON u.id = p.user_id
            ";
            
            //SQL実行の準備
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            //取得した全データを連想配列形式(名前付きのキーに値をセットして使う配列)で取り出す
            $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC); 

            // 並び替えタイプを取得（デフォルトはusername）
            $sortType = $_GET['sort'] ?? 'username';

            // ソート処理の分岐
            if ($sortType === 'newest') {
                //新着順ソート：更新日時（updated_at）が新しいものを上に
                usort($profiles, function($a, $b) {
                    // null（更新日なし）なら一番古い扱いにする
                    $a_date = $a['updated_at'] ?? '1970-01-01 00:00:00';
                    $b_date = $b['updated_at'] ?? '1970-01-01 00:00:00';
                    // strtotime()で日時を数値に変換して比較（降順）
                    return strtotime($b_date) <=> strtotime($a_date);
                });
            } else {
                //名前順ソート（自然順）
                //自然順ソートで並べる
                //usort()は配列を、自分で決めたルールで並び替える関数
                //strnatcmp()は自然順比較を行う関数（数字を数値として比較）demo2がdemo10よりも先に並ぶ
                $order = $_GET['order'] ?? 'asc';
                usort($profiles, function($a, $b) use ($order) {
                    $cmp = strnatcmp($a['username'], $b['username']);
                    return ($order === 'desc') ? -$cmp : $cmp;
                });
            }
            //ページング処理
            //総ユーザ数（配列の件数を数える）
            //SQLで再カウントするより、取得済みの配列を数える（よりシンプル）
            $total_profiles = count($profiles);

            //ページの総数を計算（全体35件で1ページ10件なら4ページ） ceil()は小数点切り上げ
            $totalPages = ceil($total_profiles / $limit);

            //表示すべき範囲を切り出す（1ページ＝10件）(2ページ目なら(2－1)*10＝10件目から表示)
            $offset = ($page - 1) * $limit;

            //array_slice()で全体の中から「今のページ分だけ」取り出す
            $paged_profiles = array_slice($profiles, $offset, $limit);

            
            //応答データを作成
            $response_data = [
                'profiles' => $paged_profiles, //今のページで表示する分のプロフィール
                'current_user_id' => $_SESSION['user']['id'], // 自分のプロフィール判定用(編集ボタンを出すかどうか)
                'totalPages' => $totalPages, //全体ページ数
                'currentPage' => $page //現在のページ番号
            ];
            //JSON変換でブラウザへ送る
            echo json_encode($response_data);

        // URLに "?id=..." が指定されていれば「詳細1件」を返す (編集ページ用)
        // GET: 投稿詳細1件（編集用・本人のみ）
        }elseif (isset($_GET['id'])) {
            // URLから編集対象の投稿IDを取得
            $post_id = (int)$_GET['id'];
            
            // 投稿IDに一致する投稿をデータベースから取得するSQL
            $sql = "
                SELECT 
                    p.id, 
                    p.title, 
                    p.body, 
                    p.user_id 
                FROM 
                    posts p 
                WHERE 
                    p.id = :id
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

        // URLに "?parent_id=..." があれば「返信一覧」を返す
        // GET: 指定スレッドの返信一覧（古い順）
        } elseif (isset($_GET['parent_id'])) {
            
            // URLから親投稿のIDを取得。念のため(int)で整数に変換し、安全性を高める
            $parent_id = (int)$_GET['parent_id'];

            // データベースに送る命令文（SQL）を準備
            // parentpost_idが、指定された親投稿のIDと一致する投稿（=返信）だけに絞り込む
            // 返信は会話の流れが分かりやすいように、古い順（昇順 ASC）で並び替える
            $sql = "
                SELECT 
                    p.id, 
                    p.user_id, 
                    p.body, 
                    p.created_at, 
                    u.username
                FROM 
                    posts AS p
                JOIN 
                    users AS u ON p.user_id = u.id
                WHERE 
                    p.parentpost_id = :parent_id
                ORDER BY 
                    p.created_at ASC
            ";
            
            // SQLインジェクション対策として、まずSQL文の"型枠"だけをデータベースに送って準備
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':parent_id', $parent_id, PDO::PARAM_INT); // 型枠の「:parent_id」の部分に、実際の値を安全に埋め込む（バインドする）
            $stmt->execute(); // 実行
            $replies = $stmt->fetchAll(PDO::FETCH_ASSOC); // 実行結果（返信データ）を全て取得し、PHPの配列に格納する

            echo json_encode($replies); // 取得したPHP配列→JSON形式に変換して、ブラウザに返却
        
        // パラメータがない場合：「親スレッド一覧」を返す
        } else {
            // ソート・ページング処理
            
            // 1ページあたりの表示件数
            $limit = 10; 

            // 並び替えの基準 (デフォルトは作成日時)
            $sort_column = 'created_at'; // デフォルト値
            $allowed_sort_columns = ['created_at', 'updated_at']; // ホワイトリスト
            if (isset($_GET['sort']) && in_array($_GET['sort'], $allowed_sort_columns)) {
                $sort_column = $_GET['sort'];
            }

            // 並び替えの方向 (デフォルトは降順 DESC)
            $order = 'DESC'; // デフォルト値
            if (isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC') {
                $order = 'ASC';
            }

            // 現在のページ番号 (デフォルトは1ページ目)
            $page = 1;
            if (isset($_GET['page']) && filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                $page = (int)$_GET['page'];
            }

            // SQLのOFFSETを計算 (1ページ目なら0, 2ページ目なら10)
            $offset = ($page - 1) * $limit;

            // --- 総ページ数を計算 ---
            $count_sql = "SELECT COUNT(*) FROM posts WHERE parentpost_id IS NULL";
            $count_stmt = $pdo->query($count_sql);
            $total_threads = (int)$count_stmt->fetchColumn();
            $totalPages = ceil($total_threads / $limit); // 総数を1ページあたりの件数で割り、小数点以下を切り上げ

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
                    p.{$sort_column} {$order} -- 動的に設定
                LIMIT :limit OFFSET :offset      -- 動的に設定
            ";
            
            // SQLを実行する（ユーザーからの入力値がないため、prepare/bindは必須ではないが、統一性のために使用）
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            $threads = $stmt->fetchAll(PDO::FETCH_ASSOC); // 実行結果（親スレッドデータ一式）を取得し、PHPの配列に格納
            $response_data = [
                'threads' => $threads,
                'current_user_id' => $_SESSION['user']['id'],
                'totalPages' => $totalPages,
                'currentPage' => $page
            ];

            echo json_encode($response_data); // 配列全体をJSONで返す
        }

    } catch (Exception $e) {
        // もしtryブロックの中でデータベースエラーなどが発生したら、ここで処理を中断
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'データベースエラー: ' . $e->getMessage()]); // エラーが発生したことを示すJSONをブラウザに返す
    }
    exit; // GETリクエストの処理はここで終了
}

//====================================================
// POSTリクエストの処理 (新規スレッド作成, 返信、　編集 、削除、　プロフィール更新)
//====================================================
// POST: 作成・更新・削除系エンドポイント（分岐は上部コメントを参照）
if ($method === 'POST') {
    $json_data = file_get_contents('php://input'); // クライアントから送信されたJSONデータを受け取る
    $data = json_decode($json_data, true);

    try {
        $user_id = $_SESSION['user']['id']; // ログイン中のユーザーIDは共通で取得

        //"action": "update_profile" が含まれている場合、プロフィール更新として処理
    // POST: プロフィールの新規作成/更新（UPSERT）
    if (isset($data['action']) && $data['action'] === 'update_profile') {
            
            // 入力データを取得 (存在しない場合は空文字や空配列に)
            $department = $data['department'] ?? '';
            $hobbies = $data['hobbies'] ?? []; // 趣味は配列で受け取る想定
            $comment = $data['comment'] ?? '';

            // バリデーション (例: 文字数制限など。ここでは省略)
            // if (mb_strlen($comment) > 255) { /* エラー処理 */ }

            // 趣味の配列をカンマ区切りの文字列に変換
            $hobbies_string = implode(',', $hobbies);

      {/* 成功メッセージ表示時はフォームを非表示にする */}
            // UPSERT (Update or Insert) 処理 ---
            // まず、既にプロフィールが存在するか確認
            $check_sql = "SELECT id FROM profiles WHERE user_id = :user_id";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $check_stmt->execute();
            $existing_profile = $check_stmt->fetch();

            if ($existing_profile) {
                // 存在する場合: UPDATE
                $sql = "UPDATE profiles SET department = :department, hobbies = :hobbies, comment = :comment, updated_at = NOW() WHERE user_id = :user_id";
            } else {
                // 存在しない場合: INSERT
                $sql = "INSERT INTO profiles (user_id, department, hobbies, comment) VALUES (:user_id, :department, :hobbies, :comment)";
            }

            // SQLを実行
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':department', $department, PDO::PARAM_STR);
            $stmt->bindValue(':hobbies', $hobbies_string, PDO::PARAM_STR);
            $stmt->bindValue(':comment', $comment, PDO::PARAM_STR);
            $stmt->execute();
            
            echo json_encode(['message' => 'プロフィールが更新されました。']);

        //"action": "delete" が含まれている場合、投稿削除として処理
        // POST: 投稿の削除（本人のみ）
        }elseif (isset($data['action']) && $data['action'] === 'delete') {
            // バリデーション: 削除対象のidがあるか確認
            if (empty($data['id'])) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['error' => '削除する投稿のIDが指定されていません。']);
                exit;
            }
            $post_id = (int)$data['id']; // 削除対象の投稿ID

            // 権限チェック
            // 削除しようとしている投稿の元の投稿者IDを取得
            $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $stmt->execute([$post_id]);
            $post = $stmt->fetch();

            // 投稿が存在しない、または自分の投稿でない場合はエラー
            if (!$post || $post['user_id'] !== $user_id) {
                header('HTTP/1.1 403 Forbidden');
                echo json_encode(['error' => 'この投稿を削除する権限がありません。']);
                exit;
            }

            // データベース削除処理
            // 権限チェック後、投稿をデータベースから削除するSQL文
            $sql = "DELETE FROM posts WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $post_id, PDO::PARAM_INT);
            $stmt->execute();
            
            // 成功したことをクライアントに伝える
            echo json_encode(['message' => '投稿が削除されました。']);


        //idが含まれている場合、投稿編集として処理
        // POST: 投稿本文の編集（本人のみ）
        }elseif (isset($data['id']) && !empty($data['id'])) {
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

        // parentpost_idが含まれている場合、返信投稿として処理
        // POST: 返信の新規作成
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

        //返信編集処理（非同期）
        }elseif (isset($data['action']) && $data['action'] === 'edit_reply') {
            // 返信編集専用API
            // 入力データを安全に取得
            $reply_id = (int)($data['reply_id'] ?? 0);
            $body = trim($data['body'] ?? '');

            // バリデーション
            if ($reply_id <= 0 || $body === '') {
                echo json_encode(['error' => '不正なデータです。']);
                exit;
            }

            // ログイン中ユーザーのみ編集可能
            $user_id = $_SESSION['user']['id'];

            // 自分の返信であるか確認
            $check = $pdo->prepare('SELECT user_id FROM posts WHERE id = ?');
            $check->execute([$reply_id]);
            $reply = $check->fetch(PDO::FETCH_ASSOC);

            if (!$reply || $reply['user_id'] !== $user_id) {
                echo json_encode(['error' => '権限がありません。']);
                exit;
            }

            // DB更新
            $stmt = $pdo->prepare('UPDATE posts SET body = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$body, $reply_id]);

            echo json_encode(['success' => true, 'new_body' => htmlspecialchars($body, ENT_QUOTES, 'UTF-8')]);
            exit;

        // 上記以外は、新規スレッド作成として処理
        }else {
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