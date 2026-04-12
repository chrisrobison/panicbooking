<?php

namespace PanicBooking\EventSync\Adapters;

use PanicBooking\EventSync\BaseAdapter;
use PanicBooking\EventSync\HttpClient;
use PanicBooking\EventSync\Normalizer;

class TicketmasterAdapter extends BaseAdapter {
    public function key(): string {
        return 'ticketmaster';
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function fetchEvents(): array {
        $apiKey = trim((string)(getenv('TM_API_KEY') ?: getenv('TM_CONSUMER_KEY') ?: ''));
        if ($apiKey === '') {
            $this->logger->warn('ticketmaster: TM_API_KEY not configured');
            return [];
        }

        $venueId = trim((string)($this->options['ticketmaster_venue_id'] ?? ''));
        $venueName = trim((string)($this->options['display_name'] ?? 'Bill Graham Civic Auditorium'));
        if ($venueId === '') {
            return [];
        }

        $endpoint = 'https://app.ticketmaster.com/discovery/v2/events.json';
        $page = 0;
        $totalPages = 1;
        $events = [];

        do {
            $params = http_build_query([
                'apikey' => $apiKey,
                'venueId' => $venueId,
                'classificationName' => 'Music',
                'size' => 100,
                'sort' => 'date,asc',
                'countryCode' => 'US',
                'page' => $page,
            ]);

            $raw = HttpClient::fetch($endpoint . '?' . $params);
            if ($raw === false) {
                break;
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                break;
            }

            $totalPages = max(1, (int)($payload['page']['totalPages'] ?? 1));
            $pageEvents = $payload['_embedded']['events'] ?? [];
            if (!is_array($pageEvents)) {
                $page++;
                continue;
            }

            foreach ($pageEvents as $ev) {
                if (!is_array($ev)) {
                    continue;
                }

                $title = Normalizer::cleanText((string)($ev['name'] ?? ''));
                $eventDate = trim((string)($ev['dates']['start']['localDate'] ?? ''));
                if ($title === '' || $eventDate === '') {
                    continue;
                }

                $bands = [];
                $attractions = $ev['_embedded']['attractions'] ?? [];
                if (is_array($attractions)) {
                    foreach ($attractions as $attraction) {
                        $attName = Normalizer::cleanText((string)($attraction['name'] ?? ''));
                        if ($attName !== '') {
                            $bands[] = $attName;
                        }
                    }
                }
                if (empty($bands)) {
                    $bands = [$title];
                }
                $bands = Normalizer::normalizeBandList($bands);

                $showTime = '';
                $localTime = trim((string)($ev['dates']['start']['localTime'] ?? ''));
                if ($localTime !== '' && preg_match('/^(\d{2}):(\d{2})/', $localTime, $tm)) {
                    $hour = (int)$tm[1];
                    $minute = (int)$tm[2];
                    $suffix = $hour >= 12 ? 'pm' : 'am';
                    $h12 = $hour % 12;
                    if ($h12 === 0) {
                        $h12 = 12;
                    }
                    $showTime = $minute === 0 ? ($h12 . $suffix) : sprintf('%d:%02d%s', $h12, $minute, $suffix);
                }

                $price = '';
                $priceRanges = $ev['priceRanges'] ?? [];
                if (is_array($priceRanges) && !empty($priceRanges)) {
                    $range = $priceRanges[0];
                    $min = isset($range['min']) ? '$' . (string)(float)$range['min'] : '';
                    $max = isset($range['max']) ? '$' . (string)(float)$range['max'] : '';
                    if ($min !== '' && $max !== '') {
                        $price = $min === $max ? $min : $min . '-' . $max;
                    } elseif ($min !== '') {
                        $price = $min;
                    }
                }

                $statusCode = strtolower((string)($ev['dates']['status']['code'] ?? $ev['statusCode'] ?? ''));
                $isSoldOut = $statusCode === 'offsale' ? 1 : 0;
                $sourceUrl = Normalizer::cleanText((string)($ev['url'] ?? ''));

                $events[] = [
                    'source_event_id' => (string)($ev['id'] ?? sha1($eventDate . '|' . $title . '|' . $sourceUrl)),
                    'source_url' => $sourceUrl,
                    'ticket_url' => $sourceUrl,
                    'venue_name' => $venueName,
                    'venue_city' => 'San Francisco',
                    'event_date' => $eventDate,
                    'title' => $title,
                    'subtitle' => count($bands) > 1 ? implode(', ', array_slice($bands, 1)) : '',
                    'bands' => $bands,
                    'show_time' => strtolower($showTime),
                    'price' => $price,
                    'is_sold_out' => $isSoldOut,
                    'is_ticketed' => 1,
                    'status' => $isSoldOut ? 'sold_out' : 'on_sale',
                    'raw_payload' => $ev,
                ];
            }

            $page++;
            usleep(120000);
        } while ($page < $totalPages);

        return $events;
    }
}
