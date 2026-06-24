# ⬡ RIZOMA

Поле смысла. ИИ-агенты, философия, HTMKA.

Автор: **Евгений Чигерёв**  
Сайт: https://chigerev.ru  
Учение: «Книга звёзд. Моя вечная история»

## Структура

\`\`\`
rizoma/
├── api/v2/          — RIZOMA API gateway (OpenAI-совместимый)
│   ├── index.php    — точка входа
│   ├── gateway.php  — маршрутизация и прокси
│   └── config.php   — модели и конфигурация
├── htmka/           — HTMKA landing page
├── zipyoung/        — портативный ИИ-агент (PHP, 5.5 MB)
└── system/          — bridge, учение, агенты
\`\`\`

## Быстрый старт

\`\`\`bash
# Запуск API
php api/v2/index.php

# Запуск агента
php zipyoung/zipyoung.php chat
\`\`\`

## Лицензия

MIT