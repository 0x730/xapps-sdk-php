<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

final class BackendSupport
{
    public static function readRecord(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public static function readString(mixed $value, string $fallback = ''): string
    {
        return is_string($value) ? $value : $fallback;
    }

    public static function readList(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return array<int,array{key:string,label:string}> */
    public static function normalizeHostModes(mixed $value): array
    {
        $items = self::readList($value);
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim(self::readString($item['key'] ?? null));
            $label = trim(self::readString($item['label'] ?? null));
            if ($key === '' || $label === '') {
                continue;
            }
            $result[] = ['key' => $key, 'label' => $label];
        }
        return $result;
    }
}
