<?php

namespace PanicBooking\EventSync\Adapters;

use PanicBooking\EventSync\BaseAdapter;
use PanicBooking\EventSync\HttpClient;
use PanicBooking\EventSync\Normalizer;

class FillmoreAdapter extends BaseAdapter {
    public function key(): string {
        return 'fillmore';
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function fetchEvents(): array {
        $url = 'https://www.thefillmore.com/shows';
        $html = HttpClient::fetch($url);
        if ($html === false) {
            return [];
        }

        $events = [];

        preg_match_all('/self\.__next_f\.push\(\[\d+,"((?:[^"\\\\]|\\\\.)*)"\]\)/s', $html, $chunks);
        foreach ($chunks[1] as $raw) {
            $decoded = json_decode('"' . $raw . '"');
            if (!is_string($decoded) || strpos($decoded, 'start_date_local') === false) {
                continue;
            }

            if (preg_match('/"data"\s*:\s*(\[.+\])/s', $decoded, $m)) {
                $arr = json_decode($m[1], true);
                if (is_array($arr) && !empty($arr)) {
                    $events = array_merge($events, $arr);
                    continue;
                }
            }

            preg_match_all(
                '/"name"\s*:\s*"([^"\\\\](?:[^"\\\\]|\\\\.)*)"\s*,\s*'
                . '"slug"\s*:\s*"[^"]*"\s*,\s*'
                . '"url"\s*:\s*"([^"\\\\](?:[^"\\\\]|\\\\.)*?)"\s*,\s*'
                . '"type"\s*:\s*"[^"]*"\s*,\s*'
                . '"start_date_local"\s*:\s*"(\d{4}-\d{2}-\d{2})"\s*,\s*'
                . '"start_time_local"\s*:\s*"(\d{2}:\d{2}:\d{2})"\s*,\s*'
                . '"timezone"\s*:\s*"[^"]*"\s*,\s*'
                . '"start_datetime_utc"\s*:\s*"[^"]*"\s*,\s*'
                . '"status_code"\s*:\s*"([^"]*)"/',
                $decoded,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $events[] = [
                    'name' => json_decode('"' . $match[1] . '"') ?? $match[1],
                    'url' => json_decode('"' . $match[2] . '"') ?? $match[2],
                    'start_date_local' => $match[3],
                    'start_time_local' => $match[4],
                    'status_code' => $match[5],
                ];
            }
        }

        if (empty($events)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }

            $title = Normalizer::cleanText((string)($ev['name'] ?? ''));
            $eventDate = (string)($ev['start_date_local'] ?? '');
            if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
                continue;
            }

            $dedupeKey = $eventDate . '|' . $title;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $showTime = '';
            if (!empty($ev['start_time_local']) && preg_match('/^(\d{2}):(\d{2})/', (string)$ev['start_time_local'], $tm)) {
                $hour = (int)$tm[1];
                $minute = (int)$tm[2];
                $suffix = $hour >= 12 ? 'pm' : 'am';
                $h12 = $hour % 12;
                if ($h12 === 0) {
                    $h12 = 12;
                }
                $showTime = $minute === 0 ? ($h12 . $suffix) : sprintf('%d:%02d%s', $h12, $minute, $suffix);
            }

            $statusCode = strtolower((string)($ev['status_code'] ?? 'onsale'));
            $isSoldOut = ($statusCode === 'offsale' || $statusCode === 'cancelled') ? 1 : 0;
            $sourceUrl = Normalizer::cleanText((string)($ev['url'] ?? $url));

            $bands = $this->parseBands($title);
            $out[] = [
                'source_event_id' => (string)($ev['id'] ?? sha1($eventDate . '|' . $title . '|' . $sourceUrl)),
                'source_url' => $sourceUrl,
                'ticket_url' => $sourceUrl,
                'venue_name' => 'The Fillmore',
                'venue_city' => 'San Francisco',
                'event_date' => $eventDate,
                'title' => $title,
                'subtitle' => count($bands) > 1 ? implode(', ', array_slice($bands, 1)) : '',
                'bands' => $bands,
                'show_time' => strtolower($showTime),
                'is_sold_out' => $isSoldOut,
                'is_ticketed' => 1,
                'status' => $isSoldOut ? 'sold_out' : 'on_sale',
                'raw_payload' => $ev,
            ];
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function parseBands(string $name): array {
        if (preg_match('/^(.+?)\s+(?:with(?:\s+special\s+guests?)?|w\/)\s+(.+)$/i', $name, $m)) {
            $bands = [trim($m[1])];
            foreach (preg_split('/\s*[,&]\s*/', trim($m[2])) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $bands[] = $part;
                }
            }
            return Normalizer::normalizeBandList($bands);
        }

        return Normalizer::normalizeBandList([$name]);
    }
}
