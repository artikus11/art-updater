# art-updater

Composer-библиотека штатных обновлений WordPress-плагинов.

Первый источник обновлений — общий приватный GitHub Release со snapshot всех плагинов. Позже источник можно заменить на собственный update gateway, не меняя код плагинов.

Пакет предполагается как публичный GitHub-репозиторий `art/updater`. В Packagist публиковать не обязательно: подключение через Composer VCS.

Детали и исходные требования: [PROJECT_STATUS.md](PROJECT_STATUS.md).

## Статус

Проектирование закрыто по credentials и metadata. Есть Composer-пакет `art/updater` и доменные объекты. GitHubProvider и хуки WordPress ещё не написаны.

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
- **Регистрация.** Плагин не передаёт slug и имя ZIP вручную, если их можно взять из `__FILE__`. Инфраструктурная конфигурация — на сайте, не в каждом плагине.
- **Credentials.** GitHub PAT через `ART_UPDATER_GITHUB_TOKEN` на сайте, не в плагине и не в `wp_options`. Один PAT не означает доступ к любому чужому приватному репо. Готовность источника — при инициализации: публичный GitHub без токена допустим, приватный без токена — updater молчит.
- **Metadata.** Контракт v1 — `update-metadata.json` в assets Release. Полный snapshot, без `requires` / `tested` / `changelog`.

Не выбрано:

- token в каждом плагине или в его настройках;
- рантайм-синхронизация токена между сайтами;
- GitHub App или gateway как способ ротации PAT в v1;
- сравнение с Release tag;
- скачивание ZIP только чтобы прочитать версию;
- жёсткая привязка всей библиотеки к GitHub API.

## Планируемый API

Целевой вызов в плагине:

```php
new PluginUpdater(
    __FILE__
);
```

На сайте:

```php
define( 'ART_UPDATER_GITHUB_TOKEN', '...' );
```

Слои:

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

Минимальные доменные объекты уже есть: `Art\Updater\Plugin`, `Art\Updater\Update`, `Art\Updater\UpdateProviderInterface`.

Хуки WordPress:

- `pre_set_site_transient_update_plugins`
- `plugins_api`
- `upgrader_pre_download`
- `upgrader_post_install`

Пакет в плагины подключается Composer'ом как `art/updater`. Сборка плагина уже умеет `composer install --no-dev --optimize-autoloader`.

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
- Передаточный статус в [PROJECT_STATUS.md](PROJECT_STATUS.md).

### Дальше

1. **GitHubProvider.** Release → `update-metadata.json` → запись по slug → сравнение версии → нормализованный `Update` и данные для скачивания приватного asset.
2. **Интеграция WordPress.** Хуки выше, поведение как у штатного updater.
3. **Чтение credentials.** Константа на сайт; для приватного GitHub без токена не стартовать; плагины токен не передают.

### Проверка

4. Один тестовый плагин: Composer → регистрация → новая версия в админке → детали → скачивание приватного ZIP → установка → путь и активация после `Plugin_Upgrader`.
5. Новый общий Release, версия конкретного плагина не изменилась → обновления для него нет.

### Позже

6. `GatewayProvider` за тем же `UpdateProviderInterface`. API плагинов не меняется.

Сейчас следующий шаг — `GitHubProvider`.

## Открытые вопросы

1. Реализация GitHubProvider.
2. Кеш ответа GitHub, чтобы не бить API на каждую проверку обновлений.
3. Скачивание приватных Release assets.
4. Безопасная передача credentials в HTTP-запросах WordPress.
5. Минимальный набор данных для `plugins_api` и страницы деталей.
6. Требования к `requires`, `tested`, changelog и прочим полям updater.
7. Обновление активного плагина и сохранение пути после `Plugin_Upgrader`.
8. Совместимость версий самой updater-библиотеки.
