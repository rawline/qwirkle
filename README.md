# Qwirkle Online (React + PHP + PostgreSQL)

Минимальный полнофункциональный каркас приложения для онлайн-версии Qwirkle.

## Структура проекта

```
/frontend
  package.json
  vite.config.js
  index.html
  /src
    App.jsx
    main.jsx
    styles.css
    /api
      api.js
    /pages
      LoginPage.jsx
      GameListPage.jsx
      GameBoardPage.jsx
/backend
  /.env.example
  /api
    _bootstrap.php
    auth.php
    get_game_state.php
    games_list.php
    create_game.php
```

## Бэкенд (PHP, PostgreSQL)

Подключение к удалённой базе реализовано так же, как в `db_test.php` (через PDO, DSN `pgsql:`). Параметры берутся из `.env` (если файла нет — используются значения по умолчанию).

Создайте файл `backend/.env` на основе примера:

```
DB_HOST=pg
DB_PORT=5432
DB_NAME=studs
DB_USER=s373445
DB_PASS=ВАШ_ПАРОЛЬ
```

Запуск встроенного сервера PHP:

```powershell
# из корня репозитория
php -S localhost:8000 -t backend/api
```

Доступные эндпоинты:
- `POST /auth` — вызывает SQL-функцию `auth(p_login, p_pass)` и возвращает JSON.
- `GET  /get_game_state?p_token=...&p_player_id=...` — вызывает `get_game_state(p_token, p_player_id)` и возвращает JSON.
- `GET  /games_list` — требует токен (заголовок `X-Auth-Token`), список игр пользователя.
- `POST /create_game` — требует токен, создаёт запись в `games` и добавляет игрока в `players`.

Все ответы в JSON. CORS включён. Токен можно передавать заголовком `X-Auth-Token` или параметром `p_token`.

## Фронтенд (Vite + React)

Установите зависимости и запустите дев-сервер Vite:

```powershell
cd frontend
npm install
npm run dev
```

Vite проксирует
- `/api/*` → `http://localhost:8000/*`

### Хранение токена
Токен сохраняется в `localStorage` (`token`, `login`).

### Периодическое обновление состояния
`GameBoardPage.jsx` опрашивает `/get_game_state` каждые 2 секунды через `setInterval` в `useEffect`.

## Пример запроса из React

```js
const res = await fetch('/api/get_game_state?p_token=12345&p_player_id=7');
const data = await res.json();
console.log(data);
```

## Краткая архитектура

- React-приложение (страницы: Login, Game List, Game Board) общается с PHP API в формате JSON.
- Авторизация вызывает SQL-функцию `auth`, возвращающую JSON с `token` или `error`.
- Список игр и создание игры опираются на таблицы `tokens`, `players`, `games`.
- Состояние игры возвращает SQL-функция `get_game_state`.

Если потребуется — легко расширить роутинг и вводить новые операции (ходы, добор фишек и т.д.).
