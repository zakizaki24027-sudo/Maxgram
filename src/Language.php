<?php
declare(strict_types=1);

namespace TeleMaker;

use PDO;

class Language
{
    public const SUPPORTED_LANGUAGES = [
        'ar' => ['name' => 'العربية', 'flag' => '🇸🇦', 'dir' => 'rtl'],
        'en' => ['name' => 'English', 'flag' => '🇬🇧', 'dir' => 'ltr'],
        'fr' => ['name' => 'Français', 'flag' => '🇫🇷', 'dir' => 'ltr'],
        'tr' => ['name' => 'Türkçe',  'flag' => '🇹🇷', 'dir' => 'ltr'],
        'de' => ['name' => 'Deutsch', 'flag' => '🇩🇪', 'dir' => 'ltr'],
    ];

    public const DEFAULT_LANGUAGE = 'ar';

    private static array $translationsCache = [];

    public static function sanitizeLang(?string $lang): string
    {
        if ($lang === null) {
            return self::DEFAULT_LANGUAGE;
        }

        $code = strtolower(substr(trim($lang), 0, 2));
        return array_key_exists($code, self::SUPPORTED_LANGUAGES) ? $code : self::DEFAULT_LANGUAGE;
    }

    public static function loadDictionary(string $lang): array
    {
        $lang = self::sanitizeLang($lang);
        if (!isset(self::$translationsCache[$lang])) {
            $path = dirname(__DIR__) . "/lang/{$lang}.php";
            if (file_exists($path)) {
                self::$translationsCache[$lang] = require $path;
            } else {
                $fallbackPath = dirname(__DIR__) . "/lang/" . self::DEFAULT_LANGUAGE . ".php";
                self::$translationsCache[$lang] = file_exists($fallbackPath) ? require $fallbackPath : [];
            }
        }

        return self::$translationsCache[$lang];
    }

    public static function trans(string $key, array $params = [], ?string $lang = null): string
    {
        $lang = self::sanitizeLang($lang);
        $dict = self::loadDictionary($lang);

        $text = $dict[$key] ?? null;

        if ($text === null && $lang !== self::DEFAULT_LANGUAGE) {
            $defaultDict = self::loadDictionary(self::DEFAULT_LANGUAGE);
            $text = $defaultDict[$key] ?? $key;
        }

        if ($text === null) {
            $text = $key;
        }

        foreach ($params as $paramKey => $val) {
            $text = str_replace('{' . $paramKey . '}', (string)$val, $text);
        }

        return $text;
    }

    public static function getUserLanguage(PDO $pdo, int $userId): string
    {
        try {
            $stmt = $pdo->prepare("SELECT language_code FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row && !empty($row['language_code'])) {
                return self::sanitizeLang($row['language_code']);
            }
        } catch (\Exception $e) {}

        return self::DEFAULT_LANGUAGE;
    }

    public static function setUserLanguage(PDO $pdo, int $userId, string $lang): void
    {
        $safeLang = self::sanitizeLang($lang);
        $stmt = $pdo->prepare("UPDATE users SET language_code = ? WHERE user_id = ?");
        $stmt->execute([$safeLang, $userId]);
    }
}
