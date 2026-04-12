<?php

namespace PanicBooking\EventSync\Adapters;

use DOMDocument;
use DOMNode;
use DOMXPath;
use PanicBooking\EventSync\BaseAdapter;
use PanicBooking\EventSync\HttpClient;
use PanicBooking\EventSync\Normalizer;

class FoopeeAdapter extends BaseAdapter {
    public function key(): string {
        return 'foopee';
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function fetchEvents(): array {
        $baseUrl = 'http://www.foopee.com/punk/the-list/';
        $totalPages = max(1, (int)($this->options['foopee_pages'] ?? 38));
        $events = [];

        for ($page = 1; $page <= $totalPages; $page++) {
            $url = $baseUrl . 'by-date.' . $page . '.html';
            $html = HttpClient::fetch($url);
            if ($html === false) {
                $this->logger->warn('foopee: failed page ' . $page);
                continue;
            }

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);

            $dateLis = $xpath->query('//li[a[@name]][b or a/b]');
            if ($dateLis === false) {
                continue;
            }

            foreach ($dateLis as $dateLi) {
                $eventDate = $this->parseDateFromNode($xpath, $dateLi);
                if ($eventDate === '') {
                    continue;
                }

                $showUls = $xpath->query('./ul', $dateLi);
                if ($showUls === false) {
                    continue;
                }

                foreach ($showUls as $showUl) {
                    $showLis = $xpath->query('./li', $showUl);
                    if ($showLis === false) {
                        continue;
                    }

                    foreach ($showLis as $showLi) {
                        $venueNodes = $xpath->query('./b/a | .//b//a', $showLi);
                        if ($venueNodes === false || $venueNodes->length === 0) {
                            continue;
                        }

                        $venueRaw = Normalizer::cleanText($this->nodeText($venueNodes->item(0)));
                        if ($venueRaw === '') {
                            continue;
                        }

                        $venueName = $venueRaw;
                        $venueCity = '';
                        $lastComma = strrpos($venueRaw, ', ');
                        if ($lastComma !== false) {
                            $venueName = trim(substr($venueRaw, 0, $lastComma));
                            $venueCity = trim(substr($venueRaw, $lastComma + 2));
                        }

                        if ($venueCity !== 'S.F.') {
                            continue;
                        }

                        $bandNodes = $xpath->query('.//a[contains(@href,"by-band")]', $showLi);
                        $bands = [];
                        if ($bandNodes !== false) {
                            foreach ($bandNodes as $bn) {
                                $name = Normalizer::cleanText($this->nodeText($bn));
                                if ($name !== '') {
                                    $bands[] = $name;
                                }
                            }
                        }
                        $bands = Normalizer::normalizeBandList($bands);

                        $liText = (string)$showLi->ownerDocument->saveHTML($showLi);
                        $liText = Normalizer::cleanText(strip_tags($liText));
                        $liText = str_replace($venueRaw, '', $liText);
                        foreach ($bands as $b) {
                            $liText = str_replace($b, '', $liText);
                        }
                        $rawMeta = Normalizer::cleanText((string)(preg_replace('/^[\s,;]+|[\s,;]+$/', '', $liText) ?? $liText));

                        $meta = $this->parseMeta($rawMeta);
                        $title = $bands[0] ?? Normalizer::cleanText((string)$rawMeta);
                        if ($title === '') {
                            continue;
                        }

                        $showTime = (string)$meta['show_time'];
                        $doorsTime = (string)$meta['doors_time'];
                        $sourceEventId = sha1($eventDate . '|' . Normalizer::canonicalNameKey($venueName) . '|' . Normalizer::normalizeTitle($title));

                        $events[] = [
                            'source_event_id' => $sourceEventId,
                            'source_url' => $url,
                            'venue_name' => $venueName,
                            'venue_city' => 'San Francisco',
                            'event_date' => $eventDate,
                            'title' => $title,
                            'subtitle' => count($bands) > 1 ? implode(', ', array_slice($bands, 1)) : '',
                            'bands' => $bands,
                            'show_time' => $showTime,
                            'doors_time' => $doorsTime,
                            'age_restriction' => (string)$meta['age_restriction'],
                            'price' => (string)$meta['price'],
                            'is_ticketed' => (int)$meta['is_ticketed'],
                            'is_sold_out' => (int)$meta['is_sold_out'],
                            'notes' => (string)$meta['notes'],
                            'raw_meta' => $rawMeta,
                            'raw_payload' => [
                                'source' => 'foopee',
                                'venue_raw' => $venueRaw,
                                'raw_meta' => $rawMeta,
                                'bands' => $bands,
                            ],
                        ];
                    }
                }
            }

            usleep(100000);
        }

        return $events;
    }

    private function parseDateFromNode(DOMXPath $xpath, \DOMNode $dateLi): string {
        $bNodes = $xpath->query('.//a[@name]//b | .//b', $dateLi);
        if ($bNodes === false) {
            return '';
        }

        $dateText = '';
        foreach ($bNodes as $bn) {
            $t = Normalizer::cleanText($bn->textContent);
            if (preg_match('/^[A-Z][a-z]{2}\s+[A-Z][a-z]{2}\s+\d{1,2}$/', $t)) {
                $dateText = $t;
                break;
            }
        }

        if ($dateText === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $dateText) ?: [];
        if (count($parts) < 3) {
            return '';
        }

        $monMap = [
            'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
            'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
        ];

        $month = $monMap[$parts[1]] ?? 0;
        $day = (int)$parts[2];
        if ($month === 0 || $day === 0) {
            return '';
        }

        $year = (int)date('Y');
        $currentMonth = (int)date('n');
        if ($month < $currentMonth - 2) {
            $year++;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function nodeText(DOMNode $node): string {
        return html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return array<string,mixed>
     */
    private function parseMeta(string $meta): array {
        $result = [
            'age_restriction' => '',
            'price' => '',
            'doors_time' => '',
            'show_time' => '',
            'is_ticketed' => 0,
            'is_sold_out' => 0,
            'notes' => '',
        ];

        if (str_contains($meta, '#')) {
            $result['is_ticketed'] = 1;
            $meta = str_replace('#', '', $meta);
        }

        $meta = str_replace(['@', '^'], '', $meta);

        $notes = [];
        $meta = preg_replace_callback('/\(([^)]*)\)/i', static function (array $m) use (&$notes): string {
            $notes[] = Normalizer::cleanText((string)$m[1]);
            return ' ';
        }, $meta) ?? $meta;

        foreach ($notes as $np) {
            if (stripos($np, 'sold out') !== false) {
                $result['is_sold_out'] = 1;
            }
        }
        $result['notes'] = implode('; ', array_filter($notes));

        if (stripos($meta, 'sold out') !== false) {
            $result['is_sold_out'] = 1;
            $meta = preg_replace('/sold\s+out/i', '', $meta) ?? $meta;
        }

        $meta = Normalizer::cleanText($meta);

        if (preg_match('/^(a\/a|all\s+ages|21\+|18\+|16\+|12\+|5\+|\?\/\?)/i', $meta, $m)) {
            $result['age_restriction'] = strtolower(Normalizer::cleanText((string)$m[1]));
            $meta = Normalizer::cleanText(substr($meta, strlen((string)$m[0])));
        }

        if (preg_match('/(\d{1,2}(?::\d{2})?(?:am|pm))\/(\d{1,2}(?::\d{2})?(?:am|pm))/i', $meta, $m)) {
            $result['doors_time'] = strtolower($m[1]);
            $result['show_time'] = strtolower($m[2]);
            $meta = str_replace($m[0], '', $meta);
        } elseif (preg_match('/(\d{1,2}(?::\d{2})?(?:am|pm))/i', $meta, $m)) {
            $result['show_time'] = strtolower($m[1]);
            $meta = str_replace($m[0], '', $meta);
        }

        $meta = Normalizer::cleanText($meta);

        if (preg_match('/\bfree\b/i', $meta)) {
            $result['price'] = 'free';
        } elseif (preg_match('/(\$[\d.]+(?:\/\$[\d.]+)?)/i', $meta, $m)) {
            $result['price'] = $m[1];
        }

        return $result;
    }
}
