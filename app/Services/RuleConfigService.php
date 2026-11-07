<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class RuleConfigService
{
    private static $cache = [];

    public static function yaml(string $defaultFile, string $customFile): array
    {
        return self::load($defaultFile, $customFile, 'yaml');
    }

    public static function json(string $defaultFile, string $customFile): array
    {
        return self::load($defaultFile, $customFile, 'json');
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function load(string $defaultFile, string $customFile, string $format): array
    {
        // 常驻进程也要看到模板的创建、删除和替换。
        clearstatcache(true);
        $file = file_exists($customFile) ? $customFile : $defaultFile;
        $contents = is_file($file) ? @file_get_contents($file) : false;
        if ($contents === false) {
            throw new RuntimeException("Unable to read rule template: {$file}");
        }

        $enabled = (int)config('v2board.rule_cache_enable', 1) === 1;
        $key = $format . ':' . $file;
        $version = $enabled ? hash('sha256', $contents) : null;
        if ($enabled && (self::$cache[$key]['version'] ?? null) === $version) {
            return self::$cache[$key]['data'];
        }

        try {
            $data = $format === 'yaml'
                ? Yaml::parse($contents)
                : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (ParseException $e) {
            $e->setParsedFile($file);
            throw $e;
        }
        if (!is_array($data)) {
            throw new RuntimeException("Invalid rule template structure: {$file}");
        }

        if ($enabled) {
            if (!isset(self::$cache[$key]) && count(self::$cache) >= 32) {
                self::flush();
            }
            self::$cache[$key] = ['version' => $version, 'data' => $data];
        }
        return $data;
    }
}
