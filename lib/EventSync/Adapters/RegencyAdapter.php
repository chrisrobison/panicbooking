<?php

namespace PanicBooking\EventSync\Adapters;

use PanicBooking\EventSync\BaseAdapter;
use PanicBooking\EventSync\HttpClient;
use PanicBooking\EventSync\Normalizer;

class RegencyAdapter extends BaseAdapter {
    public function key(): string {
        return 'regency';
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function fetchEvents(): array {
        $pageUrl = 'https://theregencyballroom.com/shows/';
        $html = HttpClient::fetch($pageUrl);
        if ($html === false) {
            return [];
        }

        $jsonUrl = '';
        if (preg_match('/data-file="([^"]+)"/i', $html, $m)) {
            $jsonUrl = $m[1];
            if (strpos($jsonUrl, 'http') !== 0) {
                $jsonUrl = 'https://theregencyballroom.com' . $jsonUrl;
            }
        }

        if ($jsonUrl === '') {
            foreach ([
                'https://aegwebprod.blob.core.windows.net/json/events/9/events.json',
                'https://theregencyballroom.com/shows/events.json',
                'https://theregencyballroom.com/wp-content/uploads/events.json',
            ] as $candidate) {
                $candidateRaw = HttpClient::fetch($candidate);
                if ($candidateRaw !== false && strlen($candidateRaw) > 64) {
                    $jsonUrl = $candidate;
                    break;
                }
            }
        }

        if ($jsonUrl === '') {
            return [];
        }

        $jsonRaw = HttpClient::fetch($jsonUrl);
        if ($jsonRaw === false) {
            return [];
        }

        $data = json_decode($jsonRaw, true);
        if (!is_array($data) || !is_array($data['events'] ?? null)) {
            return [];
        }

        $events = [];
        foreach ($data['events'] as $ev) {
            if (!is_array($ev) || empty($ev['active'])) {
                continue;
            }

            $title = Normalizer::cleanText((string)($ev['title']['headlinersText'] ?? ''));
            if ($title === '') {
                continue;
            }

            $bands = array_filter(array_map('trim', explode(',', $title)));
            $supporting = Normalizer::cleanText((string)($ev['title']['supportingText'] ?? ''));
            if ($supporting !== '') {
                $supporting = preg_replace('/^with\s+/i', '', $supporting) ?? $supporting;
                foreach (array_filter(array_map('trim', explode(',', $supporting))) as $s) {
                    $bands[] = $s;
                }
            }
            $bands = Normalizer::normalizeBandList($bands);
            if (empty($bands)) {
                $bands = [$title];
            }

            $startDt = (string)($ev['eventDateTime'] ?? '');
            $startTs = strtotime($startDt);
            if ($startTs === false) {
                continue;
            }

            $eventDate = date('Y-m-d', $startTs);
            $showTime = strtolower(date('g:ia', $startTs));

            $doorsTime = '';
            $doorDt = (string)($ev['doorDateTime'] ?? '');
            if ($doorDt !== '') {
                $doorTs = strtotime($doorDt);
                if ($doorTs !== false) {
                    $doorsTime = strtolower(date('g:ia', $doorTs));
                }
            }

            $priceLow = trim((string)($ev['ticketPriceLow'] ?? ''));
            $priceHigh = trim((string)($ev['ticketPriceHigh'] ?? ''));
            $price = '';
            if ($priceLow !== '' && $priceLow !== '$0' && $priceLow !== '$0.00') {
                $price = $priceHigh !== '' && $priceHigh !== $priceLow ? $priceLow . '-' . $priceHigh : $priceLow;
            }

            $statusId = (int)($ev['ticketing']['statusId'] ?? 1);
            $isSoldOut = $statusId === 3 ? 1 : 0;

            $sourceUrl = (string)($ev['ticketing']['ticketURL'] ?? ($ev['ticketing']['url'] ?? $pageUrl));
            $sourceEventId = (string)($ev['id'] ?? sha1($eventDate . '|' . $title . '|' . $sourceUrl));

            $events[] = [
                'source_event_id' => $sourceEventId,
                'source_url' => $sourceUrl,
                'ticket_url' => $sourceUrl,
                'venue_name' => 'Regency Ballroom',
                'venue_city' => 'San Francisco',
                'event_date' => $eventDate,
                'title' => $title,
                'subtitle' => count($bands) > 1 ? implode(', ', array_slice($bands, 1)) : '',
                'bands' => $bands,
                'show_time' => $showTime,
                'doors_time' => $doorsTime,
                'age_restriction' => (string)($ev['age'] ?? ''),
                'price' => $price,
                'is_sold_out' => $isSoldOut,
                'is_ticketed' => 1,
                'status' => $isSoldOut ? 'sold_out' : 'on_sale',
                'raw_payload' => $ev,
            ];
        }

        return $events;
    }
}
