<?php
declare(strict_types=1);

return [
    'storage' => [
        // Optional markdown snapshot path.
        'data_dir' => __DIR__ . '/data',
    ],
    'db' => [
        'host' => getenv('EXLIBRIS_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('EXLIBRIS_DB_PORT') ?: 5432),
        'name' => getenv('EXLIBRIS_DB_NAME') ?: 'exlibris',
        'user' => getenv('EXLIBRIS_DB_USER') ?: 'postgres',
        'pass' => getenv('EXLIBRIS_DB_PASS') ?: '',
        'schema' => getenv('EXLIBRIS_DB_SCHEMA') ?: 'public',
    ],
    'security' => [
        // If set, mutating APIs require header: X-Admin-Token
        'admin_token' => getenv('EXLIBRIS_ADMIN_TOKEN') ?: '',
    ],
    'ai' => [
        'chat_model' => getenv('EXLIBRIS_OPENAI_CHAT_MODEL') ?: 'gpt-4o-mini',
        'reader_model' => getenv('EXLIBRIS_OPENAI_READER_MODEL') ?: (getenv('EXLIBRIS_OPENAI_CHAT_MODEL') ?: 'gpt-4o-mini'),
        'embedding_model' => getenv('EXLIBRIS_OPENAI_EMBED_MODEL') ?: 'text-embedding-3-small',
    ],
];
