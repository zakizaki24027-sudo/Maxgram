<?php
declare(strict_types=1);

return [
    'bot_token'          => '8890492028:AAEfY1t-uzn0vhZDe1VxlPrB7KKnIzVlAT4',
    'bot_username'       => 'Maxgram_bot',
    'support_bot_user'   => 'Tawassolmax_bot',
    'support_bot_url'    => 'https://t.me/Tawassolmax_bot',
    'force_sub_channel'  => 'Glow_digitali',
    'force_sub_url'      => 'https://t.me/Glow_digitali',
    'admin_ids'          => array_filter(array_map('intval', explode(',', getenv('ADMIN_IDS') ?: '123456789,987654321'))),
    'db_path'            => getenv('DB_PATH') ?: __DIR__ . '/data/bot.sqlite',
    'trial_max_channels' => 3,
    'pricing_plans'      => [
        '1_month' => [
            'id'          => '1_month',
            'label'       => 'شهر (1 Month)',
            'price_text'  => '250 ألف (250,000 DA)',
            'price_num'   => 250000,
            'duration'    => 30,
        ],
        '2_months' => [
            'id'          => '2_months',
            'label'       => 'شهرين (2 Months)',
            'price_text'  => '500 ألف دج (500,000 DA)',
            'price_num'   => 500000,
            'duration'    => 60,
        ],
    ],
];
