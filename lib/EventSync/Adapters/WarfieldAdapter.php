<?php

namespace PanicBooking\EventSync\Adapters;

use DOMDocument;
use DOMXPath;
use PanicBooking\EventSync\BaseAdapter;
use PanicBooking\EventSync\HttpClient;
use PanicBooking\EventSync\Normalizer;

class WarfieldAdapter extends BaseAdapter {
    public function key(): string {
        return 'warfield';
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function fetchEvents(): array {
        $url = 'https://www.thewarfieldtheatre.com/events';
        $html = HttpClient::fetch($url);
        if ($html === false) {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $items = $xpath->query('//div[contains(@class,"event-item")]');
        if ($items === false) {
            return [];
        }

        $monthMap = [
            'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
            'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
            'January' => 1, 'February' => 2, 'March' => 3, 'April' => 4, 'June' => 6,
            'July' => 7, 'August' => 8, 'September' => 9, 'October' => 10,
            'November' => 11, 'December' => 12,
        ];

        $events = [];
        foreach ($items as $item) {
            $titleNodes = $xpath->query('.//h3//a', $item);
            if ($titleNodes === false || $titleNodes->length === 0) {
                continue;
            }

            $title = Normalizer::cleanText($titleNodes->item(0)->textContent);
            if ($title === '') {
                continue;
            }

            $sourceUrl = $titleNodes->item(0)->getAttribute('href') ?: $url;

            $bands = [$title];
            $supportNodes = $xpath->query('.//h4', $item);
            if ($supportNodes !== false && $supportNodes->length > 0) {
                $support = preg_replace('/^with\s+/i', '', Normalizer::cleanText($supportNodes->item(0)->textContent)) ?? '';
                foreach (preg_split('/\s*[,&]\s*/', $support) ?: [] as $s) {
                    $s = Normalizer::cleanText($s);
                    if ($s !== '') {
                        $bands[] = $s;
                    }
                }
            }
            $bands = Normalizer::normalizeBandList($bands);

            $eventDate = '';
            $showTime = '';

            $pNodes = $xpath->query('.//p', $item);
            if ($pNodes === false) {
                continue;
            }

            foreach ($pNodes as $pNode) {
                $pText = Normalizer::cleanText($pNode->textContent);

                if (preg_match('/[A-Za-z]{2,4},\s+([A-Za-z]+)\s+(\d{1,2}),\s+(\d{4})\s+Show\s+(\d{1,2}:\d{2}\s*[AP]M)/i', $pText, $m)) {
                    $month = $monthMap[$m[1]] ?? 0;
                    if ($month > 0) {
                        $eventDate = sprintf('%04d-%02d-%02d', (int)$m[3], $month, (int)$m[2]);
                        $showTime = strtolower((string)(preg_replace('/\s+/', '', $m[4]) ?? $m[4]));
                        break;
                    }
                }
            }

            if ($eventDate === '') {
                continue;
            }

            $isSoldOut = 0;
            $links = $xpath->query('.//a', $item);
            if ($links !== false) {
                foreach ($links as $link) {
                    if (stripos(Normalizer::cleanText($link->textContent), 'sold out') !== false) {
                        $isSoldOut = 1;
                        break;
                    }
                }
            }

            $events[] = [
                'source_event_id' => sha1($eventDate . '|' . $title . '|' . $sourceUrl),
                'source_url' => $sourceUrl,
                'ticket_url' => $sourceUrl,
                'venue_name' => 'The Warfield',
                'venue_city' => 'San Francisco',
                'event_date' => $eventDate,
                'title' => $title,
                'subtitle' => count($bands) > 1 ? implode(', ', array_slice($bands, 1)) : '',
                'bands' => $bands,
                'show_time' => $showTime,
                'is_sold_out' => $isSoldOut,
                'is_ticketed' => 1,
                'status' => $isSoldOut ? 'sold_out' : 'on_sale',
                'raw_payload' => [
                    'source' => 'warfield',
                    'title' => $title,
                    'event_date' => $eventDate,
                    'source_url' => $sourceUrl,
                ],
            ];
        }

        return $events;
    }
}
