<?php

namespace PanicBooking\EventSync;

class Normalizer {
    public static function canonicalNameKey(string $name): string {
        $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = strtolower(trim($decoded));
        if ($clean === '') {
            return '';
        }

        $clean = str_replace('&', ' and ', $clean);
        $clean = preg_replace('/^the\s+/i', '', $clean) ?? $clean;
        $clean = preg_replace('/[^a-z0-9]+/', ' ', $clean) ?? $clean;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
        return str_replace(' ', '', $clean);
    }

    public static function slugify(string $value): string {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strtolower(trim($value));
        if ($value === '') {
            return 'unknown-venue';
        }

        $value = str_replace(['&', '@'], [' and ', ' at '], $value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        $value = trim($value, '-');
        return $value === '' ? 'unknown-venue' : $value;
    }

    public static function cleanText(string $value): string {
        return trim((string)(preg_replace('/\s+/', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $value));
    }

    public static function normalizeTitle(string $title): string {
        $normalized = strtolower(self::cleanText($title));
        $normalized = preg_replace('/\b(with|w\/|feat\.?|featuring|special guests?)\b.*$/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        return trim((string)(preg_replace('/\s+/', ' ', $normalized) ?? $normalized));
    }

    public static function normalizeBandList(array $bands): array {
        $out = [];
        $seen = [];

        foreach ($bands as $band) {
            $clean = self::cleanText((string)$band);
            if ($clean === '') {
                continue;
            }
            $key = self::canonicalNameKey($clean);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $clean;
        }

        return $out;
    }

    public static function parseTimeTo24h(string $timeText): ?string {
        $timeText = strtolower(self::cleanText($timeText));
        if ($timeText === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeText, $m)) {
            return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
        }

        if (!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)$/i', $timeText, $m)) {
            return null;
        }

        $hour = (int)$m[1];
        $minute = isset($m[2]) ? (int)$m[2] : 0;
        $ampm = strtolower($m[3]);

        if ($hour === 12) {
            $hour = 0;
        }
        if ($ampm === 'pm') {
            $hour += 12;
        }

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    public static function combineDateTime(?string $eventDate, ?string $timeText, string $timezone): ?string {
        $eventDate = trim((string)$eventDate);
        if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            return null;
        }

        $time24 = self::parseTimeTo24h((string)$timeText);
        if ($time24 === null) {
            $time24 = '20:00:00';
        }

        try {
            $dt = new \DateTime($eventDate . ' ' . $time24, new \DateTimeZone($timezone));
        } catch (\Exception $e) {
            return null;
        }

        return $dt->format('Y-m-d H:i:s');
    }

    public static function inferBands(string $title, string $subtitle = ''): array {
        $bands = [];
        $title = self::cleanText($title);
        $subtitle = self::cleanText($subtitle);

        if ($title !== '') {
            $bands[] = $title;
        }

        if ($subtitle !== '') {
            $parts = preg_split('/\s*(?:,|\band\b|\+|\/)\s*/i', $subtitle) ?: [];
            foreach ($parts as $part) {
                $part = self::cleanText($part);
                if ($part !== '') {
                    $bands[] = $part;
                }
            }
        }

        return self::normalizeBandList($bands);
    }
}
