<?php

namespace App\Helpers;

class UserAgentParser
{
    /**
     * Parse the given User-Agent string.
     *
     * @param string|null $userAgent
     * @return array{browser:string, device:string, operating_system:string, is_bot:bool}
     */
    public static function parse(?string $userAgent): array
    {
        $userAgent = $userAgent ?? '';
        
        $browser = self::getBrowser($userAgent);
        $device = self::getDevice($userAgent);
        $os = self::getOS($userAgent);
        $isBot = self::checkIsBot($userAgent);

        return [
            'browser' => $browser,
            'device' => $device,
            'operating_system' => $os,
            'is_bot' => $isBot,
        ];
    }

    /**
     * Detect browser name.
     */
    private static function getBrowser(string $ua): string
    {
        if (preg_match('/(edge|edg)\//i', $ua)) {
            return 'Edge';
        }
        if (preg_match('/firefox/i', $ua)) {
            return 'Firefox';
        }
        if (preg_match('/chrome/i', $ua)) {
            return 'Chrome';
        }
        if (preg_match('/safari/i', $ua)) {
            return 'Safari';
        }
        if (preg_match('/opera|opr/i', $ua)) {
            return 'Opera';
        }
        if (preg_match('/msie|trident/i', $ua)) {
            return 'Internet Explorer';
        }
        
        return 'Unknown Browser';
    }

    /**
     * Detect device type.
     */
    private static function getDevice(string $ua): string
    {
        $uaLower = strtolower($ua);

        if (preg_match('/tablet|ipad|playbook|silk/i', $uaLower)) {
            return 'tablet';
        }
        if (preg_match('/mobile|iphone|ipod|android|blackberry|opera mini|iemobile|phone/i', $uaLower)) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Detect Operating System.
     */
    private static function getOS(string $ua): string
    {
        if (preg_match('/windows|win32/i', $ua)) {
            return 'Windows';
        }
        if (preg_match('/android/i', $ua)) {
            return 'Android';
        }
        if (preg_match('/iphone|ipad|ipod/i', $ua)) {
            return 'iOS';
        }
        if (preg_match('/macintosh|mac os x/i', $ua)) {
            return 'macOS';
        }
        if (preg_match('/linux/i', $ua)) {
            return 'Linux';
        }

        return 'Unknown OS';
    }

    /**
     * Check if user agent belongs to a bot.
     */
    private static function checkIsBot(string $ua): bool
    {
        $botSignatures = config('analytics.bot_signatures', []);
        
        if (empty($ua)) {
            return true;
        }

        foreach ($botSignatures as $signature) {
            if (stripos($ua, $signature) !== false) {
                return true;
            }
        }

        return false;
    }
}
