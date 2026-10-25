<?php

return [
    // Время ввода и напоминаний; в базе всё хранится как timestamptz.
    'timezone' => 'Asia/Tbilisi',

    'currencies' => ['USD', 'GEL'],

    // Надёжность клиента; по умолчанию — unverified.
    'reliability' => ['unverified', 'reliable', 'unreliable'],

    'task_statuses' => ['open', 'done', 'cancelled'],

    // Через сколько дней без движения карточка считается застывшей.
    'stale_days' => 7,
];
