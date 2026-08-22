# art-updater

Composer-библиотека штатных обновлений WordPress-плагинов. PHP `>=8.3`.

Первый источник обновлений — общий приватный GitHub Release со snapshot всех плагинов. Позже источник можно заменить на собственный update gateway, не меняя код плагинов.

Пакет предполагается как публичный GitHub-репозиторий `art/updater`. В Packagist публиковать не обязательно: подключение через Composer VCS.

Детали и исходные требования: [PROJECT_STATUS.md](PROJECT_STATUS.md).

## Статус

Проектирование закрыто по credentials и metadata. Есть Composer-пакет `art/updater` (PHP `>=8.3`), доменные объекты, `GitHubProvider` и `PluginUpdater`. PHPUnit-тестов в либе нет: проверка — живой WordPress (обновление и стабильная версия). Хуки WP и скачивание GitHub не мокаем. Dev-зависимости — только PHPCS / WPCS / PHPCompatibility.

Credentials для v1: GitHub PAT в конфиге сайта (`AUP_GITHUB_TOKEN`). Плагин токен не получает. Для публичного
репозитория токен не обязателен; для приватного без константы updater не стартует. Ротация на парке сайтов — шаг
развёртывания, не gateway и не синхронизация секретов. GitHub App / gateway можно добавить позже без смены API плагинов.

## Changelog

### 1.1.1

- из dev-зависимостей убраны PHPUnit, WP_Mock, Mockery, function-mocker
- проверка updater — живой WordPress, не юнит-тесты
- PHPCS / WPCS / PHPCompatibility остаются

### 1.1.0

- `Art\Updater\Snapshot`: ключи metadata по slug, поля `release` и `generated_at` (дата генерации релиза, не коммит файла плагина)
- `GitHubProvider::from_site()` — те же `AUP_*`, что у `PluginUpdater`
- `GitHubProvider::get_remote()` — `Update` из snapshot без фильтра «только новее»
- `GitHubProvider::get_snapshot()` / `clear_cache()` — публичный snapshot и сброс transient `aup_gh_*`
- `get_update()` не менялся: `null`, если remote не новее
- в кеш кладутся `plugins`, `assets`, `release`, `generated_at`
- `Config::constant_string()`, `Config::is_github_private()`; `PluginUpdater` создаёт провайдер через `from_site()`

### 1.0.0

- штатный updater: GitHub Release → `update-metadata.json` → хуки WP → скачивание ZIP

## Источник обновлений

Несколько плагинов живут в одном закрытом GitHub-репозитории. Каждый прогон workflow создаёт **один** Release на весь набор, не отдельный release на плагин.

```text
skl-plugins-YYYYMMDD-HHMMSS
├── update-metadata.json
├── skl-core.zip
├── skl-seo.zip
└── ...
```

- Tag сборки — идентификатор Release, не версия плагина. В runner skladchinaorg: `skl-plugins-YYYYMMDD-HHMMSS`.
- Имя ZIP = slug каталога (`skl-core` → `skl-core.zip`). ZIP содержит корневой каталог плагина.
- Версия плагина берётся из заголовка `Version` в главном PHP-файле (`1.4.2`).
- Если в новом общем Release версия конкретного плагина не изменилась, обновления для него нет.
- Metadata — snapshot **всех** плагинов Release. Скачивать ZIP ради `Version` нельзя.

Контракт v1 (без `requires` / `tested` / `changelog`):

```json
{
  "release": "plugins-20260819-002900",
  "generated_at": "2026-08-19T00:29:00Z",
  "plugins": {
    "skl-core": {
      "version": "1.4.2",
      "package": "skl-core.zip",
      "updated_at": "2026-08-18T21:42:15Z"
    }
  }
}
```

Файл: `update-metadata.json` в assets того же Release, что и ZIP. Ключ в `plugins` = slug = имя ZIP без `.zip`. Поле `release` — непрозрачная строка. `package` — имя asset, не URL.

Runner уже генерирует этот файл и дублирует assets на `skl-plugins-latest`. Какой Release читает библиотека — вопрос `GitHubProvider`.

## Зафиксированные решения

- **Slug.** Дополнительный `asset_name` не нужен. `plugin_basename(__FILE__)` → первая часть пути: `skl-core/skl-core.php` → `skl-core` → `skl-core.zip`.
- **Версия.** Сравнивается установленный `Version` с записью в metadata, не с GitHub tag.
- **Provider.** WordPress-слой и источник данных разделены. GitHub-структуры не протекают в updater. Provider отдаёт нормализованный `Update`.
- **Домен.** `readonly` `Plugin`, `Update`, `Snapshot`; `UpdateProviderInterface` в `src/php/`. PHP `>=8.3`.
- **Регистрация.** Только `__FILE__`. Slug, basename и `Version` читаются из файла, не из массива в вызове.
- **Конфиг.** Все константы в `Art\Updater\Config`: имена `AUP_*`, `METADATA_ASSET`, GitHub API, кеш, коды `WP_Error`.
- **Credentials.** GitHub PAT через `AUP_GITHUB_TOKEN` на сайте, не в плагине и не в `wp_options`. Один PAT не означает доступ к любому чужому приватному репо. Готовность источника — при инициализации: публичный GitHub без токена допустим; без репозитория или `AUP_GITHUB_PRIVATE` без токена — updater молчит.
- **Metadata.** Контракт v1 — `update-metadata.json` в assets Release. Полный snapshot, без `requires` / `tested` / `changelog`.
- **Версии пакета.** Git tag (сейчас `1.1.1`), в плагинах `^1.0`. Коммит тег не пушит. `1.x` не ломает `PluginUpdater(__FILE__)`, `AUP_*` и интерфейс provider. Мажор — только вместе с бампом `Version` всех плагинов, которые вендорят либу.
- **Проверка.** PHPUnit-тестов в либе нет. Живой WordPress: обновление и сценарий стабильной версии. Хуки updater и скачивание GitHub не мокаем. Dev-зависимости — PHPCS, WPCS, PHPCompatibility.

Не выбрано:

- массив slug/version в конструктор вместо `__FILE__`;
- `dev-master` как долговременный пин версии либы;
- token в каждом плагине или в его настройках;
- рантайм-синхронизация токена между сайтами;
- GitHub App или gateway как способ ротации PAT в v1;
- сравнение с Release tag;
- скачивание ZIP только чтобы прочитать версию;
- жёсткая привязка всей библиотеки к GitHub API.

## Подключение

Сайт, `wp-config.php` (или обвязка развёртывания):

```php
define( 'AUP_GITHUB_TOKEN', '...' );
define( 'AUP_GITHUB_REPOSITORY', 'owner/repo' );
define( 'AUP_GITHUB_RELEASE_TAG', 'skl-plugins-latest' ); // опционально
define( 'AUP_GITHUB_PRIVATE', true ); // опционально
```

Без `AUP_GITHUB_REPOSITORY` хуки не регистрируются. `AUP_GITHUB_PRIVATE` + пустой токен — тоже.

Плагин, `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/artikus11/art-updater.git"
    }
  ],
  "require": {
    "php": ">=8.3",
    "art/updater": "^1.0"
  }
}
```

Packagist не нужен. Версия пакета — git tag в этом репозитории, не поле `version` в `composer.json` и не `dev-master`. Коммит тег не создаёт: выпуск это `git tag 1.0.1` и `git push origin 1.0.1`. Пока тег не на GitHub, `^1.0` не резолвится. Не каждый коммит — новый тег. Текущий релиз: `1.1.1`.

В линейке `1.x` не меняются: `new PluginUpdater( __FILE__ )`, имена `AUP_*`, `UpdateProviderInterface`. Ломающий API — тег `2.0.0`. Тогда в одном общем Release поднимают `Version` **всех** плагинов, которые вендорят updater: на сайте не должно оказаться двух мажоров одного класса `Art\Updater\…`. Версию в код не копировать; какая либа в ZIP — `composer.lock`.

Главный файл плагина:

```php
<?php
/**
 * Plugin Name: Example
 * Version: 1.4.2
 */

require __DIR__ . '/vendor/autoload.php';

new \Art\Updater\PluginUpdater( __FILE__ );
```

Slug и ZIP библиотека берёт из пути файла (`example/example.php` → `example` → `example.zip`), версию — из заголовка `Version`. Токен и репозиторий в плагин не передаются.

Второй аргумент `PluginUpdater` — опциональный `UpdateProviderInterface`, для тестов или будущего gateway.

## API

```text
WordPress Updater
        │
        ▼
UpdateProviderInterface
        │
        ├── GitHubProvider       ← первый этап
        │
        └── GatewayProvider      ← позже
```

`GitHubProvider` берёт `owner/repo` и опциональный tag из констант сайта. Для skladchina tag — `skl-plugins-latest`, потому что `make_latest: false`.

`UpdateProviderInterface::get_update()` по-прежнему `null`, если remote не новее установленной. Для таблицы версий (Skl Core) не этот метод:

```php
$provider = \Art\Updater\GitHubProvider::from_site();

if ( null === $provider ) {
    // нет AUP_GITHUB_REPOSITORY или приват без токена
}

$snapshot = $provider->get_snapshot(); // все slug из metadata, release, generated_at
$remote   = $provider->get_remote( new \Art\Updater\Plugin( 'skl-core', '1.6.0', 'skl-core/skl-core.php' ) );
$provider->clear_cache(); // сброс transient aup_gh_*
```

`get_remote()` отдаёт `Update` и при равных версиях. `from_site()` — те же правила готовности, что у `PluginUpdater`. Snapshot и апдейты делят один кеш: один fetch metadata на набор плагинов.

Хуки WordPress:

- `pre_set_site_transient_update_plugins`
- `plugins_api`
- `upgrader_pre_download`
- `upgrader_post_install`

Сборка плагина уже умеет `composer install --no-dev --optimize-autoloader`.

## Роадмап

### Сделано

- Требования и модель общего Release.
- Правило slug = имя ZIP.
- Версия из `Version` плагина, не из tag.
- Направление credentials закрыто: PAT на сайте, инструкция развёртывания, не gateway в v1.
- Правило активации: публичный GitHub без токена допустим; приватный без токена — updater выключен.
- Контракт metadata v1 и генерация `update-metadata.json` в runner.
- Пакет `art/updater`, PHP `>=8.3`, namespace `Art\Updater\`.
- Константы в `Art\Updater\Config`, префикс сайта `AUP_`.
- Доменные объекты: `readonly` `Plugin` и `Update`, `UpdateProviderInterface`.
- `GitHubProvider`: Release → `update-metadata.json` → slug → `Update`; кеш transient; `package_url` = API URL asset.
- `get_remote()`, `get_snapshot()`, `clear_cache()`, `from_site()` для статуса версий без смены `get_update()`.
- `PluginUpdater`: хуки WP, авторизованное скачивание GitHub asset, путь после установки = slug.
- Живой цикл обновления на тестовом плагине.
- Новый общий Release при неизменной `Version` плагина → обновления нет.
- SemVer через git tag (`1.1.1`), в плагинах `^1.0`, не `dev-master`. Тег публикуется отдельно от коммита.
- PHPUnit в либе нет; проверка на живом WP. Dev — PHPCS/WPCS/PHPCompatibility.
- Передаточный статус в [PROJECT_STATUS.md](PROJECT_STATUS.md).

### Позже

1. `GatewayProvider` за тем же `UpdateProviderInterface`. API плагинов не меняется.

Следующее в skladchinaorg — вкладка версий в `skl-core` на этом API. Gateway — когда понадобится свой endpoint вместо GitHub.

## Открытые вопросы

Нет.
