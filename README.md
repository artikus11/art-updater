# art-updater

Composer-библиотека штатных обновлений WordPress-плагинов.

Первый источник обновлений — общий приватный GitHub Release со snapshot всех плагинов. Позже источник можно заменить на собственный update gateway, не меняя код плагинов.

Пакет предполагается как публичный GitHub-репозиторий `art/updater`. В Packagist публиковать не обязательно: подключение через Composer VCS.

Детали и исходные требования: [PROJECT_STATUS.md](PROJECT_STATUS.md).

## Статус

Проектирование закрыто по credentials и metadata. Есть Composer-пакет `art/updater`, доменные объекты, `GitHubProvider` и `PluginUpdater`. Цикл на живом WordPress ещё не прогоняли.

Credentials для v1: GitHub PAT в конфиге сайта (`ART_UPDATER_GITHUB_TOKEN`). Плагин токен не получает. Для публичного
репозитория токен не обязателен; для приватного без константы updater не стартует. Ротация на парке сайтов — шаг
развёртывания, не gateway и не синхронизация секретов. GitHub App / gateway можно добавить позже без смены API плагинов.

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
- **Домен.** `Plugin`, `Update`, `UpdateProviderInterface` в `src/php/`.
- **Регистрация.** Только `__FILE__`. Slug, basename и `Version` читаются из файла, не из массива в вызове.
- **Credentials.** GitHub PAT через `ART_UPDATER_GITHUB_TOKEN` на сайте, не в плагине и не в `wp_options`. Один PAT не означает доступ к любому чужому приватному репо. Готовность источника — при инициализации: публичный GitHub без токена допустим; без репозитория или `ART_UPDATER_GITHUB_PRIVATE` без токена — updater молчит.
- **Metadata.** Контракт v1 — `update-metadata.json` в assets Release. Полный snapshot, без `requires` / `tested` / `changelog`.

Не выбрано:

- массив slug/version в конструктор вместо `__FILE__`;
- token в каждом плагине или в его настройках;
- рантайм-синхронизация токена между сайтами;
- GitHub App или gateway как способ ротации PAT в v1;
- сравнение с Release tag;
- скачивание ZIP только чтобы прочитать версию;
- жёсткая привязка всей библиотеки к GitHub API.

## Подключение

Сайт, `wp-config.php` (или обвязка развёртывания):

```php
define( 'ART_UPDATER_GITHUB_TOKEN', '...' );
define( 'ART_UPDATER_GITHUB_REPOSITORY', 'owner/repo' );
define( 'ART_UPDATER_GITHUB_RELEASE_TAG', 'skl-plugins-latest' ); // опционально
define( 'ART_UPDATER_GITHUB_PRIVATE', true ); // опционально
```

Без `ART_UPDATER_GITHUB_REPOSITORY` хуки не регистрируются. `ART_UPDATER_GITHUB_PRIVATE` + пустой токен — тоже.

Плагин, `composer.json`:

```json
{
  "require": {
    "art/updater": "^1.0"
  }
}
```

Репозиторий пакета подключается как Composer VCS, Packagist не обязателен.

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
- Пакет `art/updater`, namespace `Art\Updater\`.
- Доменные объекты: `Plugin`, `Update`, `UpdateProviderInterface`.
- `GitHubProvider`: Release → `update-metadata.json` → slug → `Update`; кеш transient; `package_url` = API URL asset.
- `PluginUpdater`: хуки WP, авторизованное скачивание GitHub asset, путь после установки = slug.
- Передаточный статус в [PROJECT_STATUS.md](PROJECT_STATUS.md).

### Дальше

1. Подключить к одному тестовому плагину и пройти цикл обновления.
2. Сценарий «новый Release, версия плагина та же → обновления нет».

### Позже

3. `GatewayProvider` за тем же `UpdateProviderInterface`. API плагинов не меняется.

Сейчас следующий шаг — проверка на тестовом плагине.

## Открытые вопросы

1. Цикл обновления на живом WordPress (админка, ZIP, путь после `Plugin_Upgrader`).
2. Сценарий стабильной версии при новом общем Release.
3. Совместимость версий самой updater-библиотеки.
