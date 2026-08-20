# PROJECT_STATUS.md

## Проект

Библиотека автоматических обновлений WordPress-плагинов через штатный механизм обновлений WordPress.

Рабочая концепция: отдельная Composer-библиотека из публичного GitHub-репозитория. На первом этапе источник обновлений —
приватный GitHub-репозиторий с релизами; архитектура должна позволять позднее заменить GitHub на собственный update
gateway без изменения интеграции плагинов.

## Исходные данные

- В закрытом GitHub-репозитории находится несколько WordPress-плагинов.
- При каждом push/workflow создаётся единый GitHub Release для всего набора плагинов.
- Release — это snapshot всех плагинов, а не отдельный release каждого плагина.
- Release tag не является версией плагина: он генерируется динамически по дате/времени. В runner skladchinaorg:
  `skl-plugins-YYYYMMDD-HHMMSS`.
- В Release создаются ZIP всех плагинов и `update-metadata.json`.
- Имя ZIP определяется slug каталога плагина:
    - `skl-core` → `skl-core.zip`
    - `skl-seo` → `skl-seo.zip`
    - и т. д.
- ZIP содержит корневой каталог самого плагина.
- Версия конкретного плагина берётся из `Version` в основном PHP-файле плагина и имеет формат `1.4.2`.
- Если в новом общем Release версия конкретного плагина не изменилась, обновления для него нет.
- Текущий runner уже собирает список плагинов и их версии, определяя `Version` из главного PHP-файла. Он формирует
  `VERSIONS_JSON`, список изменённых плагинов и `update-metadata.json` со snapshot всех плагинов.

## Текущие цели

1. Создать переиспользуемую Composer-библиотеку для штатного WordPress Plugin Updater.
2. Подключать библиотеку в каждый собственный плагин через Composer из публичного GitHub-репозитория библиотеки; пакет
   не обязан размещаться в Packagist.
3. Реализовать на первом этапе GitHub provider для приватного общего репозитория.
4. Не передавать GitHub credentials отдельно каждому плагину.
5. Заложить абстракцию источника обновлений, чтобы позже добавить собственный gateway.
6. Использовать стандартные WordPress механизмы:
    - `pre_set_site_transient_update_plugins`
    - `plugins_api`
    - `upgrader_pre_download`
    - `upgrader_post_install`

## Завершённые задачи / зафиксированные решения

### 1. Идентификация плагина

Дополнительный `asset_name` в конфигурации не нужен.

Правило:

`plugin slug == ZIP filename`

Slug можно получить из `plugin_basename( __FILE__ )`, взяв первую часть:

`skl-core/skl-core.php` → `skl-core` → `skl-core.zip`

Это должно быть автоматическим, чтобы плагины не дублировали конфигурацию.

### 2. Версия

Версия сравнивается не с GitHub Release tag.

Источник истины:

`Version` в главном PHP-файле конкретного плагина.

Пример:

- установленный `skl-core`: `1.4.2`
- последний Release tag: `plugins-20260812-...`
- `skl-core` в metadata: `1.4.2`
- результат: обновления нет.

Если metadata показывает `1.4.3`, появляется штатное обновление.

### 3. Общий Release

Release содержит все текущие ZIP:

```text
plugins-YYYYMMDD-HHMMSS
├── skl-core.zip
├── skl-seo.zip
├── skl-stealth.zip
└── ...
```

Даже если изменился только один плагин, остальные могут присутствовать в Release с прежними версиями.

### 4. Metadata

Для проверки обновлений нельзя полагаться только на Release tag или скачивание ZIP.

Контракт v1 зафиксирован. Поля `requires`, `tested`, `changelog` в metadata нет.

```json
{
  "release": "plugins-20260819-002900",
  "generated_at": "2026-08-19T00:29:00Z",
  "plugins": {
    "skl-core": {
      "version": "1.4.2",
      "package": "skl-core.zip",
      "updated_at": "2026-08-18T21:42:15Z"
    },
    "skl-seo": {
      "version": "2.3.1",
      "package": "skl-seo.zip",
      "updated_at": "2026-08-12T14:18:03Z"
    }
  }
}
```

Правила:

- Snapshot всех плагинов Release, не только изменённых.
- Ключ в `plugins` = slug = имя ZIP без `.zip`.
- `release` — непрозрачная строка (tag сборки), не версия плагина. В примере префикс `plugins-…`; runner skladchinaorg пишет `skl-plugins-YYYYMMDD-HHMMSS`. Для контракта это одно поле.
- `package` — имя asset в том же Release, не URL.

Место хранения: asset того же GitHub Release, что и ZIP. Имя файла: `update-metadata.json`.

Генерация уже есть в `skladchinaorg/.github/workflows/release-skl-plugins.yml`: тот же JSON кладётся в Release вместе с ZIP и на alias `skl-plugins-latest`. Workflow не меняем.

Какой Release читает библиотека (`skl-plugins-latest` vs список тегов) — вопрос реализации `GitHubProvider`, не контракта.

### 5. Архитектура provider

WordPress-интеграция и источник обновлений должны быть разделены.

Концептуально:

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

GitHub-специфичные структуры не должны распространяться по остальной библиотеке.

Provider должен преобразовывать источник данных в нормализованный объект `Update`:

- `version`, `package` (имя ZIP) — обязательны, из metadata;
- `package_url` — URL скачивания, его заполняет provider;
- `changelog`, `requires`, `tested`, `updated_at` — опциональны; в metadata v1 есть только `updated_at`.

Доменные объекты лежат в `src/php/` (`Art\Updater\`): `Plugin`, `Update`, `UpdateProviderInterface`. GitHub-структуры в них не входят.

### 6. Регистрация плагинов

Плагин не должен вручную передавать slug и имя ZIP, если их можно определить из `__FILE__`.

Целевая идея API:

```php
new PluginUpdater(
    __FILE__
);
```

Общая инфраструктурная конфигурация updater должна быть централизованной на сайте.

### 7. Composer

Пакет: `art/updater`. Подключается как VCS dependency, Packagist не обязателен.

Каждый плагин получает библиотеку через Composer и при сборке runner выполняет:

```bash
composer install --no-dev --optimize-autoloader
```

Текущий runner уже поддерживает Composer-зависимости плагинов.

## Зафиксированное решение по credentials

Библиотека универсальная: GitHub provider не привязан к одной конкретной репе. Для v1 источник credentials — GitHub PAT
на уровне сайта.

```php
define( 'ART_UPDATER_GITHUB_TOKEN', '...' );
```

Правила:

- Токен задаётся в конфиге сайта (wp-config / обвязка развёртывания), не в плагине и не в `wp_options`.
- Плагин в updater передаёт только `__FILE__`. Токен в конструктор плагина не передаётся: библиотека читает сайт.
- Ротация PAT на 20–30 сайтах не решается рантайм-синхронизацией и не тянет gateway в v1. Это декларативный шаг
  развёртывания сайта: поставить WP, подключить обвязку, задать константу токена.
- Один PAT на сайт покрывает приватные репозитории того аккаунта/организации, которому выдан токен. Это не обещание
  «один токен — любой приватный GitHub».
- GitHub App и update gateway остаются возможными позже. Provider не должен требовать смены API плагинов при смене
  механизма аутентификации.

## Зафиксированное решение по активации updater

Готовность источника проверяется при инициализации updater, не только внутри отдельных callback. Если источник не готов,
хуки не регистрируются и запросов к GitHub нет.

Для GitHubProvider:

- публичный репозиторий: токен не обязателен;
- приватный репозиторий: нет `ART_UPDATER_GITHUB_TOKEN` или значение пустое → updater для этого источника выключен;
- непустое значение константы → credentials для GitHub считаются заданными.

Проверка «источник готов» отделена от GitHub-специфики, чтобы позже `GatewayProvider` мог иметь свои критерии.

## Текущий runner / исходная реализация

По предоставленному workflow:

- список плагинов задаётся в `ALL_PLUGINS`;
- для каждого плагина ищется PHP-файл с `Plugin Name:`;
- из него извлекается `Version`;
- версии складываются в `VERSIONS_JSON`;
- определяется список изменённых плагинов;
- release tag создаётся как `skl-plugins-YYYYMMDD-HHMMSS`;
- каждый plugin build выполняет Composer install и, если есть `package.json`, frontend build;
- ZIP создаётся как `<plugin>.zip` и содержит каталог `<plugin>`;
- release job собирает ZIP всех plugin artifacts и `update-metadata.json` (полный snapshot);
- release создаётся через `softprops/action-gh-release@v2`; тот же набор assets вешается на `skl-plugins-latest`.

Текущий workflow также формирует release notes с таблицей всех включённых плагинов, их версиями и статусом `Updated`/
`Stable`.

## Изменённые основные файлы

- `composer.json` — пакет `art/updater`, PSR-4 `Art\\Updater\\` → `src/php/`
- `src/php/Plugin.php`
- `src/php/Update.php`
- `src/php/GitHubProvider.php`
- `README.md`
- `PROJECT_STATUS.md`

Workflow репозитория плагинов не менялся.

## Результаты тестирования и проверки

Функциональная реализация WordPress updater ещё не начата, рабочих тестов обновления WordPress пока нет.

`GitHubProvider` добавлен; phpcs по `src/php/` проходит.

Проверено на уровне требований и существующего runner:

- общий Release действительно содержит ZIP нескольких плагинов;
- ZIP именуются по slug;
- версия каждого плагина доступна из его главного PHP-файла;
- Release tag не подходит для сравнения версий плагинов;
- runner уже получает версии всех плагинов;
- runner уже генерирует `update-metadata.json` и кладёт его в assets Release;
- при неизменившейся версии конкретного плагина обновление для него не должно появляться.

## Известные проблемы / открытые вопросы

1. Нужно реализовать WordPress integration (хуки updater, в т.ч. авторизованное скачивание приватного ZIP).
2. Нужно определить минимальный набор данных для `plugins_api` и страницы деталей обновления.
3. Нужно определить требования к `requires`, `tested`, changelog и другим полям WordPress updater.
4. Нужно проверить установку/обновление активного плагина и сохранение его корректного пути после `Plugin_Upgrader`.
5. Нужно определить стратегию совместимости версий самой updater-библиотеки.

## Опробованные, но не выбранные подходы

### Передавать GitHub token каждому плагину

Не выбран.

Причина: при ротации token пришлось бы менять конфигурацию во всех плагинах.

### Хранить отдельный token в настройках каждого плагина

Не выбран.

Причина: та же проблема централизации и жизненного цикла credentials; token является инфраструктурным секретом сайта, а
не настройкой конкретного плагина.

### Сравнивать установленную версию с GitHub Release tag

Не выбран.

Причина: Release tag — timestamp/build identifier, а не версия конкретного плагина.

### Скачивать ZIP для определения версии

Не выбран.

Причина: проверка обновлений должна быть дешёвой и не должна скачивать пакет только ради получения `Version`.

### Жёстко привязать всю библиотеку к GitHub API

Не выбран.

Причина: позже планируется собственный gateway. GitHub должен быть реализацией provider, а не частью основного updater
API.

### GitHub App или gateway как способ ротации токена в v1

Не выбран.

Причина: библиотека универсальная, первый источник — GitHub. PAT в конфиге сайта проще всего. Ротация на парке сайтов
закрывается инструкцией развёртывания, не отдельным сервисом секретов.

### Синхронизировать GitHub token между сайтами в рантайме

Не выбран.

Причина: это второй секрет и лишняя поверхность утечки. Либа не является control plane парка сайтов.

## Следующая последовательность разработки

### Шаг 1. Зафиксировать контракт metadata

Сделано. Контракт v1 — JSON выше, файл `update-metadata.json` в assets Release. Поля `requires` / `tested` / `changelog` в v1 не входят.

### Шаг 2. Генерация metadata в GitHub Actions

Сделано в репозитории плагинов (`release-skl-plugins.yml`), не в этом репо. Workflow не меняем.

### Шаг 3. Создать публичный Composer repository библиотеки

Сделано в этом репозитории. Пакет: `art/updater`, namespace `Art\Updater\`, PSR-4 → `src/php/`.

### Шаг 4. Спроектировать доменные объекты библиотеки

Сделано:

```text
Art\Updater\Plugin
Art\Updater\Update
Art\Updater\UpdateProviderInterface
```

`Plugin`: slug, установленная версия, plugin basename (`skl-core/skl-core.php`).
`Update`: version, package, package_url, опционально changelog / requires / tested / updated_at.
`UpdateProviderInterface::get_update( Plugin ): ?Update` — `null`, если плагина нет в источнике или версия не новее.

GitHub response внутрь этих типов не протаскивается.

### Шаг 5. Реализовать GitHubProvider

Сделано: `Art\Updater\GitHubProvider`.

```php
new GitHubProvider( 'owner/repo', 'skl-plugins-latest' );
```

Пустой второй аргумент → `GET /releases/latest`. Для skladchina `make_latest: false`, нужен tag alias (`skl-plugins-latest`).

Поведение: Release → asset `update-metadata.json` → запись по slug → `version_compare` → `Update` с `package_url` = GitHub API URL asset (`Accept: application/octet-stream`). Токен из `ART_UPDATER_GITHUB_TOKEN` (Bearer), если задан. Ответ кешируется transient'ом (успех 6 часов, ошибка 15 минут, фильтр `art_updater_github_cache_ttl`).

Скачивание ZIP через `Plugin_Upgrader` ещё не подключено: URL уже нормализован, заголовок авторизации на download — шаг WP-интеграции.

### Шаг 6. Реализовать WordPress integration

Подключить:

```text
pre_set_site_transient_update_plugins
plugins_api
upgrader_pre_download
upgrader_post_install
```

Поведение должно соответствовать штатному WordPress updater.

### Шаг 7. Подключить чтение credentials с сайта

Решение уже зафиксировано: `ART_UPDATER_GITHUB_TOKEN`, без `wp_options`, без передачи токена из плагина.

На этом шаге только реализация: библиотека читает константу, для приватного GitHub без токена не инициализируется,
для публичного работает без него.

### Шаг 8. Подключить библиотеку к одному тестовому плагину

Проверить полный цикл:

```text
Composer
→ регистрация updater
→ обнаружение новой версии
→ отображение в WordPress
→ просмотр деталей
→ скачивание приватного ZIP
→ установка
→ сохранение/активация плагина
```

### Шаг 9. Проверить сценарий стабильного плагина

Создать новый общий Release, в котором версия конкретного плагина не изменилась.

Ожидаемый результат:

```text
новый Release существует
+
plugin version прежняя
=
обновление для этого plugin отсутствует
```

### Шаг 10. Добавить GatewayProvider позже

После стабилизации GitHub implementation:

```text
GitHubProvider
GatewayProvider
       │
       ▼
UpdateProviderInterface
       │
       ▼
WordPress Updater
```

При этом API, используемый самими плагинами, менять не должен.

## Текущая точка продолжения

Доменные объекты и `GitHubProvider` есть. Credentials для v1 — GitHub PAT через `ART_UPDATER_GITHUB_TOKEN`.

Следующий этап — интеграция WordPress (хуки updater и скачивание приватного ZIP).
