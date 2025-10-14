# Chat App（業務利用想定チャットシステム）

## 概要
このプロジェクトは、**社内での業務利用を想定したチャットアプリケーション**です。  
ログインしたユーザーがスレッドを作成し、返信・編集・削除ができる構成になっています。  
PHP / MySQL / JavaScript（Fetch API）を使用し、XAMPP環境で動作します。  
Gitによるソース管理を行い、BacklogおよびGitHubと連携しています。

---

## 使用技術
| 分類 | 内容 |
|------|------|
| 言語 | PHP / JavaScript / SQL |
| データベース | MySQL |
| 環境 | XAMPP / VS Code |
| バージョン管理 | Git（Backlog・GitHub 連携） |

---

## 主な機能
- ユーザー登録・ログイン / ログアウト  
- スレッド一覧表示  
- 新規スレッド作成  
- 投稿への返信（階層構造）  
- 投稿の編集・削除（本人のみ可能）  
- プロフィール一覧閲覧機能
- ページング・ソート機能  
- セッション管理によるアクセス制御  
- UIデザイン（CSSによる最終調整予定）

---

## 画面構成
| ファイル名 | 役割 |
|-------------|------|
| `login.php` | ログインページ |
| `register.php` | 新規登録ページ |
| `thread_list.php` | スレッド一覧表示 |
| `new_thread.php` | 新規スレッド投稿フォーム |
| `thread.php` | スレッド詳細・返信表示 |
| `edit_post.php` | 投稿編集ページ |
| `profile_list.php` | **ユーザーのプロフィール一覧を閲覧** |
| `api.php` | 各種APIエンドポイント |
| `db/connection.php` | データベース接続設定 |

---

## ディレクトリ構成
chat-app/
├── api.php
├── db/
│ └── connection.php
├── edit_post.php
├── list.php
├── login.php
├── new_thread.php
├── register.php
├── thread.php
├── profile_list.php ← 今後実装予定
└── README.md

## 作者情報

Ikken Sakai

工科専門職大学 情報工学科 AI戦略コース

Email: example@example.com

GitHub: Ikken-Sakai

## ライセンス

このプロジェクトは学習・研究目的で作成されたものであり、
無断商用利用を禁止します。