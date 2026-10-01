<?php
return [
    'smtp' => [
        'host'      => getenv('SMTP_HOST')     ?: 'smtp.gmail.com',
        'username'  => getenv('SMTP_USER')     ?: 'digi.nymous@gmail.com',
        'password'  => getenv('SMTP_PASS')     ?: 'gimksblhnqeanvxy',
        'port'      => (int)(getenv('SMTP_PORT') ?: 587),
        'from_name' => getenv('SMTP_FROM_NAME') ?: 'StudentFLOW',
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'name' => getenv('DB_NAME') ?: 'student_portal_db',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],
];