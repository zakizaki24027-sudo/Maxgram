<?php
declare(strict_types=1);

namespace TeleMaker;

class Keyboards
{
    public static function getLanguageSelectionMenu(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🇸🇦 العربية (Arabic)', 'callback_data' => 'set_lang:ar'],
                    ['text' => '🇬🇧 English', 'callback_data' => 'set_lang:en'],
                ],
                [
                    ['text' => '🇫🇷 Français (French)', 'callback_data' => 'set_lang:fr'],
                    ['text' => '🇹🇷 Türkçe (Turkish)', 'callback_data' => 'set_lang:tr'],
                ],
                [
                    ['text' => '🇩🇪 Deutsch (German)', 'callback_data' => 'set_lang:de'],
                ],
            ],
        ];
    }

    public static function getMainMenu(bool $isAdmin = false, string $lang = 'ar'): array
    {
        $keyboard = [
            [
                ['text' => Language::trans('btn_create_bot', [], $lang), 'callback_data' => 'menu:create_bot'],
                ['text' => Language::trans('btn_my_bots', [], $lang), 'callback_data' => 'menu:my_bots'],
            ],
            [
                ['text' => Language::trans('btn_baridimob_pricing', [], $lang), 'callback_data' => 'menu:baridimob_pricing'],
                ['text' => Language::trans('btn_contact_support', [], $lang), 'url' => 'https://t.me/Tawassolmax_bot'],
            ],
            [
                ['text' => Language::trans('btn_channel_updates', [], $lang), 'url' => 'https://t.me/Glow_digitali'],
                ['text' => Language::trans('btn_change_lang', [], $lang), 'callback_data' => 'menu:language'],
            ],
        ];

        if ($isAdmin) {
            $keyboard[] = [
                ['text' => Language::trans('btn_admin_dashboard', [], $lang), 'callback_data' => 'admin:dashboard'],
            ];
        }

        return ['inline_keyboard' => $keyboard];
    }

    public static function getForceSubMenu(string $channelUrl, string $lang = 'ar'): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => Language::trans('btn_subscribe_channel', [], $lang), 'url' => $channelUrl],
                ],
                [
                    ['text' => Language::trans('btn_verify_sub', [], $lang), 'callback_data' => 'check:force_sub'],
                ],
                [
                    ['text' => Language::trans('btn_change_lang', [], $lang), 'callback_data' => 'menu:language'],
                ],
            ],
        ];
    }

    public static function getBotManageMenu(int $botId, bool $isTrial, string $lang = 'ar'): array
    {
        $keyboard = [
            [
                ['text' => Language::trans('btn_add_channel', [], $lang), 'callback_data' => 'bot:add_chan:' . $botId],
                ['text' => Language::trans('btn_list_channels', [], $lang), 'callback_data' => 'bot:list_chan:' . $botId],
            ],
        ];

        if ($isTrial) {
            $keyboard[] = [
                ['text' => Language::trans('btn_upgrade_baridimob', [], $lang), 'callback_data' => 'pay:baridimob:' . $botId],
            ];
        }

        $keyboard[] = [
            ['text' => Language::trans('btn_main_menu', [], $lang), 'callback_data' => 'menu:main'],
        ];

        return ['inline_keyboard' => $keyboard];
    }

    public static function getBaridiMobPricingMenu(int $botId, string $lang = 'ar'): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => Language::trans('plan_1_month_btn', [], $lang), 'callback_data' => 'plan_select:' . $botId . ':1_month'],
                ],
                [
                    ['text' => Language::trans('plan_2_months_btn', [], $lang), 'callback_data' => 'plan_select:' . $botId . ':2_months'],
                ],
                [
                    ['text' => Language::trans('btn_contact_tawassol', [], $lang), 'url' => 'https://t.me/Tawassolmax_bot'],
                ],
                [
                    ['text' => Language::trans('btn_submit_receipt', [], $lang), 'callback_data' => 'submit_receipt:' . $botId],
                ],
                [
                    ['text' => Language::trans('btn_back', [], $lang), 'callback_data' => 'menu:my_bots'],
                ],
            ],
        ];
    }

    public static function getAdminApprovalMenu(int $ticketNumber, int $botId, int $userId): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => '✅ تفعيل (1 شهر)',
                        'callback_data' => 'approve_1m:' . $ticketNumber . ':' . $botId,
                    ],
                    [
                        'text' => '✅ تفعيل (شهرين)',
                        'callback_data' => 'approve_2m:' . $ticketNumber . ':' . $botId,
                    ],
                ],
                [
                    [
                        'text' => '❌ رفض',
                        'callback_data' => 'reject_pay:' . $ticketNumber . ':' . $botId,
                    ],
                    [
                        'text' => '✉️ رد سريع',
                        'callback_data' => 'admin_reply:' . $userId,
                    ],
                ],
            ],
        ];
    }
}
