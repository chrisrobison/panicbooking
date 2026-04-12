<?php

namespace PanicBooking\EventSync;

class SourcePriority {
    private array $map;

    public function __construct(array $map) {
        $this->map = $map;
    }

    public function forSource(string $source, int $fallback = 60): int {
        $source = strtolower(trim($source));
        if ($source === '') {
            return $fallback;
        }

        if (isset($this->map[$source])) {
            return (int)$this->map[$source];
        }

        return (int)($this->map['default'] ?? $fallback);
    }
}
