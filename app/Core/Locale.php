<?php

declare(strict_types=1);

namespace App\Core;

final class Locale
{
    public const SUPPORTED = ['ru', 'en'];

    private static ?string $current = null;
    private static ?array $english = null;
    private static bool $rendering = false;
    /** @var array<string,string> */
    private static array $protected = [];

    public static function bootstrap(): void
    {
        if (self::$current !== null) return;

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId > 0) {
            try {
                $query = \db()->pdo()->prepare('SELECT locale FROM users WHERE id=? AND deleted_at IS NULL');
                $query->execute([$userId]);
                $locale = $query->fetchColumn();
                if (is_string($locale) && self::valid($locale)) {
                    self::$current = $locale;
                    self::writeCookie($locale);
                    return;
                }
            } catch (\Throwable) {
                // Installations are allowed to run while the locale migration is pending.
            }
        }

        $cookie = (string) ($_COOKIE['rhythm_locale'] ?? '');
        if (self::valid($cookie)) {
            self::$current = $cookie;
            return;
        }

        self::$current = self::fromAcceptLanguage((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    }

    public static function current(): string
    {
        self::bootstrap();
        return self::$current ?? 'ru';
    }

    public static function set(string $locale): void
    {
        if (!self::valid($locale)) {
            throw new \InvalidArgumentException(self::translate('Выберите русский или английский язык.'));
        }
        self::$current = $locale;
        self::writeCookie($locale);
    }

    public static function valid(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    public static function translate(string $text, array $replace = [], ?string $locale = null): string
    {
        $locale ??= self::current();
        $translated = $locale === 'en' ? (self::english()[$text] ?? $text) : $text;
        foreach ($replace as $key => $value) {
            $translated = str_replace(':' . $key, (string) $value, $translated);
        }
        return $translated;
    }

    public static function translateMarkup(string $markup): string
    {
        if (self::current() === 'en') {
            $markup = strtr($markup, self::english());
        }
        if (self::$protected !== []) {
            $markup = strtr($markup, self::$protected);
        }
        self::$protected = [];
        self::$rendering = false;
        return $markup;
    }

    public static function beginRender(): void
    {
        self::$rendering = true;
        self::$protected = [];
    }

    public static function protect(string $escaped): string
    {
        if (!self::$rendering) return $escaped;
        $token = '@@RHYTHM_I18N_' . count(self::$protected) . '@@';
        self::$protected[$token] = $escaped;
        return $token;
    }

    public static function formatDate(string $value, bool $short = false): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) return '—';
        if (self::current() === 'ru') return date($short ? 'd.m.y' : 'd.m.Y', $timestamp);
        $months = $short
            ? [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec']
            : [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
        return (int) date('j', $timestamp) . ' ' . $months[(int) date('n', $timestamp)] . ' ' . date($short ? 'y' : 'Y', $timestamp);
    }

    public static function formatUtc(?string $utc, string $timezone, bool $withTime = true): string
    {
        if (!$utc) return '—';
        try {
            $date = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($timezone));
            $formatted = self::formatDate($date->format('Y-m-d'));
            return $withTime ? $formatted . ' ' . $date->format('H:i') : $formatted;
        } catch (\Throwable) {
            return '—';
        }
    }

    private static function fromAcceptLanguage(string $header): string
    {
        $candidates = [];
        foreach (explode(',', strtolower($header)) as $position => $part) {
            $segments = array_map('trim', explode(';', $part));
            $language = substr($segments[0] ?? '', 0, 2);
            if (!self::valid($language)) continue;
            $quality = 1.0;
            if (isset($segments[1]) && preg_match('/^q=([0-9.]+)$/', $segments[1], $match)) $quality = (float) $match[1];
            $candidates[] = ['locale' => $language, 'quality' => $quality, 'position' => $position];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['quality'] <=> $a['quality'] ?: $a['position'] <=> $b['position']);
        return $candidates[0]['locale'] ?? 'ru';
    }

    private static function writeCookie(string $locale): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (filter_var(\env('TRUST_PROXY', 'false'), FILTER_VALIDATE_BOOL) && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        setcookie('rhythm_locale', $locale, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['rhythm_locale'] = $locale;
    }

    /** @return array<string,string> */
    private static function english(): array
    {
        return self::$english ??= require APP_ROOT . '/app/I18n/en.php';
    }
}
