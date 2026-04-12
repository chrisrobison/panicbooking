<?php

namespace PanicBooking\EventSync;

class SyncReport {
    public int $rawFetched = 0;
    public int $ingestionInserted = 0;
    public int $ingestionUpdated = 0;
    public int $canonicalInserted = 0;
    public int $canonicalUpdated = 0;
    public int $duplicateMerges = 0;
    public int $errors = 0;

    /** @var array<string, array<string, mixed>> */
    public array $perVenue = [];

    /** @var list<string> */
    public array $errorMessages = [];

    public function addVenueStat(string $slug, string $field, int $amount = 1): void {
        if (!isset($this->perVenue[$slug])) {
            $this->perVenue[$slug] = [
                'fetched' => 0,
                'inserted' => 0,
                'updated' => 0,
                'merged' => 0,
                'errors' => 0,
            ];
        }
        $this->perVenue[$slug][$field] = (int)($this->perVenue[$slug][$field] ?? 0) + $amount;
    }

    public function addError(string $message): void {
        $this->errors++;
        $this->errorMessages[] = $message;
    }
}
