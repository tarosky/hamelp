# Hamelp プロジェクト設定

## 概要

HamelpはWordPress用のFAQジェネレータープラグインです。

- **PHP要件**: 7.4以上
- **WordPress要件**: 6.6以上
- **ライセンス**: GPL-3.0

## 開発環境

### wp-env

Docker環境での開発には `@wordpress/env` を使用しています。

```bash
# 起動
npm start

# 更新して起動
npm run update

# 停止
npm run stop

# WP-CLI実行
npm run cli -- plugin list
npm run cli:test -- plugin list  # テスト用コンテナ
```

### 依存関係のインストール

```bash
# Node.js (22以上が必要)
npm install

# PHP
composer install
```

## ビルドシステム

### JS/CSS ビルド

- **JS**: `@kunoichi/grab-deps` でバンドル
- **CSS**: `sass` + `postcss` + `autoprefixer`

```bash
# 全てビルド
npm run package

# 個別
npm run build:js
npm run build:css

# 監視モード
npm run watch
```

### grab-deps ヘッダー形式

JSファイルには以下のヘッダーを記述:

```javascript
/*!
 * @handle hamelp-incsearch
 * @deps jquery
 * @strategy defer
 */
```

ビルド結果は `wp-dependencies.json` に出力され、PHPから自動読み込みされます。

## コード品質

### PHP

```bash
# 構文チェック
composer lint

# 自動修正
composer fix
```

PHPCS設定は `phpcs.ruleset.xml` にあります。以下を許可:
- PSR-0ファイル命名（オートローダー用）
- Short array syntax `[]`
- Short ternary `?:`

### JavaScript / SCSS

```bash
# 全てチェック
npm run lint

# 個別
npm run lint:js
npm run lint:css

# 自動修正
npm run fix
```

## テスト

### PHPUnit

```bash
# wp-env内で実行
npm test
```

### Git Hooks (husky + lint-staged)

コミット時に自動でlintが実行されます:

- `src/**/*.js` → `npm run lint:js`
- `src/**/*.scss` → `npm run lint:css`
- `*.php`, `app/**/*.php` → `composer lint`

## CI/CD (GitHub Actions)

| ワークフロー | トリガー | 内容 |
|-------------|---------|------|
| `test.yml` | PR / push to master | PHP/JS/CSS lint, PHPUnit |
| `release-drafter.yml` | push to master | リリースドラフト更新 |
| `wordpress.yml` | release published | WordPress.orgへデプロイ |
| `demo.yml` | push to master | デモ環境（さくらレンタルサーバー）へ rsync デプロイ。バージョンはコミットハッシュ |
| `wp-outdated.yml` | 月次 (5日) | WPバージョンチェック、Issue作成 |

### リリースフロー

1. PRをmasterにマージ → リリースドラフト自動更新
2. GitHubでリリースを公開 → WordPress.orgに自動デプロイ

### 必要なSecrets

- `WP_ORG_USERNAME`: WordPress.org SVNユーザー名
- `WP_ORG_PASSWORD`: WordPress.org SVNパスワード
- `KUNOICHI_DEMO_SAKURA_SSH`: デモ環境（さくら）デプロイ用SSH秘密鍵

### デモ環境

- master へ push されるたびに `demo.yml` がビルドして rsync デプロイ
- 接続先: `kunoichi-demo@kunoichi-demo.sakura.ne.jp:/home/kunoichi-demo/www/demo.kunoichiwp.com/pubplafaq/wp-content/plugins/hamelp`
- URL: https://demo.kunoichiwp.com/pubplafaq/ （environment: staging）
- バージョン表記はコミットハッシュ（最新版の「予告」として機能）
- 初回デプロイ後、プラグインの有効化は手動で1回必要

## ディレクトリ構造

```
hamelp/
├── app/                    # PHPクラス（PSR-0オートロード）
│   └── Hametuha/Hamelp/
├── assets/                 # ビルド済みアセット
│   ├── css/
│   └── js/
├── bin/                    # ビルドスクリプト
├── src/                    # ソースファイル
│   ├── js/
│   └── scss/
├── .github/workflows/      # GitHub Actions
├── .husky/                 # Git hooks
├── hamelp.php              # メインプラグインファイル
├── phpcs.ruleset.xml       # PHPCS設定
├── wp-dependencies.json    # アセット依存関係（自動生成）
└── package.json
```

## 注意事項

- `wp-dependencies.json` はビルド成果物だが、`.distignore` には入れない（PHPから読み込むため）
- `composer.lock` はコミットする（依存関係の再現性のため。`composer.json` を変更したら必ず `composer install` で更新して一緒にコミットする）
- `package-lock.json` はコミットする（アセットビルドの一貫性のため）
- スクリーンショットなどの一時ファイルは `tmp/` に保存してください
