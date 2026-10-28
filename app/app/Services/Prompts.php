<?php

namespace App\Services;

/**
 * Loads versioned model prompts and JSON schemas from repo-root prompts/.
 *
 * Texts live as prompts/<name>.md (legacy: prompts/<name>_system.txt);
 * schemas live as prompts/schemas/<name>.json. Results cache in memory
 * for the request.
 */
final class Prompts
{
    /** @var array<string, string|array> */
    private static array $cache = [];

    public static function text(string $name): string
    {
        self::guard($name);

        return self::$cache["text:{$name}"] ??= trim(self::read(self::textPath($name)));
    }

    public static function schema(string $name): array
    {
        self::guard($name);

        return self::$cache["schema:{$name}"]
            ??= json_decode(self::read(self::schemaPath($name)), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function textPath(string $name): string
    {
        foreach (["{$name}.md", "{$name}_system.txt"] as $file) {
            $path = self::dir().'/'.$file;
            if (is_file($path)) {
                return $path;
            }
        }

        throw new \RuntimeException("Prompt [{$name}] not found in prompts/.");
    }

    private static function schemaPath(string $name): string
    {
        $path = self::dir()."/schemas/{$name}.json";
        if (! is_file($path)) {
            throw new \RuntimeException("Schema [{$name}] not found in prompts/schemas/.");
        }

        return $path;
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Could not read [{$path}].");
        }

        return $content;
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 3).'/prompts';
    }

    private static function guard(string $name): void
    {
        if ($name === '' || str_contains($name, '..') || strpbrk($name, '/\\') !== false) {
            throw new \InvalidArgumentException("Invalid prompt name [{$name}].");
        }
    }
}
