<?php

namespace App\Support;

class TrustedProxyConfig
{
    private const DEFAULT_TRUSTED_PROXIES = '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';

    /**
     * @return list<string>
     */
    public static function fromConfig(): array
    {
        return self::fromString(config('trustedproxy.proxies', self::DEFAULT_TRUSTED_PROXIES));
    }

    /**
     * @return list<string>
     */
    public static function fromString(string|array|null $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = explode(',', (string) $value);
        }

        $proxies = array_values(array_filter(
            array_map(fn ($item) => trim((string) $item), $items),
            fn (string $item) => $item !== ''
        ));

        return $proxies !== [] ? $proxies : self::fromString(self::DEFAULT_TRUSTED_PROXIES);
    }
}
