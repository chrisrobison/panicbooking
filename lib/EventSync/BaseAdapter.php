<?php

namespace PanicBooking\EventSync;

abstract class BaseAdapter {
    protected array $options;
    protected ConsoleLogger $logger;

    public function __construct(ConsoleLogger $logger, array $options = []) {
        $this->logger = $logger;
        $this->options = $options;
    }

    abstract public function key(): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    abstract public function fetchEvents(): array;

    public function option(string $key, mixed $default = null): mixed {
        return $this->options[$key] ?? $default;
    }

    protected function timezone(): string {
        return (string)($this->options['timezone'] ?? 'America/Los_Angeles');
    }
}
