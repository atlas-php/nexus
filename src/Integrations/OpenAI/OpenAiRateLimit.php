<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\OpenAI;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Class OpenAiRateLimit
 *
 * Represents a normalized OpenAI account limit paired with current usage details for reporting.
 */
class OpenAiRateLimit
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $limit,
        public readonly ?int $usage,
        public readonly ?int $remaining,
        public readonly ?Carbon $resetsAt,
        public readonly ?string $window,
        public readonly ?string $scope,
        public readonly ?string $status,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): ?self
    {
        $name = self::stringValue(self::valueFrom($attributes, ['name', 'type', 'metric', 'scope', 'group']));

        if ($name === null) {
            return null;
        }

        $limit = self::intValue(self::valueFrom($attributes, ['limit', 'value']));
        $usage = self::intValue(self::valueFrom($attributes, ['usage', 'used']));
        $remaining = self::intValue(self::valueFrom($attributes, ['remaining']));

        if ($remaining === null && $limit !== null && $usage !== null) {
            $remaining = max(0, $limit - $usage);
        }

        $resetsAt = self::carbonValue(self::valueFrom($attributes, ['resets_at', 'reset_at', 'resetsAt', 'reset_time']));

        return new self(
            $name,
            $limit,
            $usage,
            $remaining,
            $resetsAt,
            self::stringValue(self::valueFrom($attributes, ['window', 'interval', 'period'])),
            self::stringValue(self::valueFrom($attributes, ['scope', 'group', 'bucket'])),
            self::stringValue(self::valueFrom($attributes, ['status'])),
        );
    }

    public function describe(): string
    {
        $parts = [
            'limit='.($this->limit ?? 'unknown'),
            'usage='.($this->usage ?? 'unknown'),
            'remaining='.($this->remaining ?? 'unknown'),
            'resets_at='.($this->resetsAt?->toIso8601String() ?? 'unknown'),
        ];

        if ($this->window !== null) {
            $parts[] = 'window='.$this->window;
        }

        if ($this->scope !== null) {
            $parts[] = 'scope='.$this->scope;
        }

        if ($this->status !== null) {
            $parts[] = 'status='.$this->status;
        }

        return sprintf('%s(%s)', $this->name, implode(', ', $parts));
    }

    private static function stringValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    private static function intValue(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function carbonValue(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $keys
     */
    private static function valueFrom(array $attributes, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $attributes)) {
                return $attributes[$key];
            }
        }

        return null;
    }
}
