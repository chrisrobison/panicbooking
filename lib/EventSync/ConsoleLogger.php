<?php

namespace PanicBooking\EventSync;

class ConsoleLogger {
    private bool $verbose;

    public function __construct(bool $verbose = false) {
        $this->verbose = $verbose;
    }

    public function verbose(string $message): void {
        if ($this->verbose) {
            $this->line($message);
        }
    }

    public function info(string $message): void {
        $this->line($message);
    }

    public function warn(string $message): void {
        $this->line('WARN: ' . $message);
    }

    public function error(string $message): void {
        $this->line('ERROR: ' . $message);
    }

    private function line(string $message): void {
        $stamp = date('Y-m-d H:i:s');
        echo '[' . $stamp . '] ' . $message . PHP_EOL;
    }
}
