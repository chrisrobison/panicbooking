<?php

namespace PanicBooking\EventSync\Adapters;

use DOMDocument;
use DOMXPath;
use PanicBooking\EventSync\BaseAdapter;
use PanicBooking\EventSync\HttpClient;
use PanicBooking\EventSync\Normalizer;

class GamhAdapter extends BaseAdapter {
    public function key(): string {
        return 'gamh';
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function fetchEvents(): array {
        $calUrl = 'https://gamh.com/calendar/';
        $html = HttpClient::fetch($calUrl);
        if ($html === false) {
            return [];
        }

        $nonce = '';
        $ajaxUrl = 'https://gamh.com/wp-admin/admin-ajax.php';
        if (preg_match('/seetickets_ajax_obj\s*=\s*\{[^}]*"ajax_url"\s*:\s*"([^"]+)"[^}]*"nonce"\s*:\s*"([^"]+)"/s', $html, $m)) {
            $ajaxUrl = $m[1];
            $nonce = $m[2];
        } elseif (preg_match('/seetickets_ajax_obj\s*=\s*\{[^}]*"nonce"\s*:\s*"([^"]+)"/s', $html, $m)) {
            $nonce = $m[1];
        }

        $totalPages = 1;
        if (preg_match('/data-see-total-pages=["\'](\d+)["\']/', $html, $pm)) {
            $totalPages = max(1, (int)$pm[1]);
        }

        $chunks = [$html];
        for ($page = 2; $page <= $totalPages; $page++) {
            $pageUrl = $ajaxUrl
                . '?action=get_seetickets_events'
                . '&nonce=' . urlencode($nonce)
                . '&seeAjaxPage=' . $page
                . '&listType=list';
            $chunk = HttpClient::fetch($pageUrl);
            if ($chunk !== false && strlen($chunk) > 32) {
                $chunks[] = $chunk;
            }
            usleep(100000);
        }

        $events = [];
        foreach ($chunks as $chunk) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $chunk . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();

            $xpath = new DOMXPath($dom);
            $containers = $xpath->query('//div[contains(@class,"seetickets-list-event-container")]');
            if ($containers === false) {
                continue;
            }

            foreach ($containers as $container) {
                $titleNodes = $xpath->query('.//p[contains(@class,"event-title")]//a | .//h2[contains(@class,"event-title")]//a', $container);
                if ($titleNodes === false || $titleNodes->length === 0) {
                    continue;
                }

                $title = Normalizer::cleanText($titleNodes->item(0)->textContent);
                if ($title === '') {
                    continue;
                }

                $ticketUrl = $titleNodes->item(0)->getAttribute('href') ?: $calUrl;

                $bands = [];
                $headNodes = $xpath->query('.//p[contains(@class,"headliners")]', $container);
                if ($headNodes !== false && $headNodes->length > 0) {
                    $headText = Normalizer::cleanText($headNodes->item(0)->textContent);
                    foreach (array_filter(array_map('trim', explode(',', $headText))) as $b) {
                        $bands[] = $b;
                    }
                }

                $supportNodes = $xpath->query('.//p[contains(@class,"supporting-talent")]', $container);
                if ($supportNodes !== false && $supportNodes->length > 0) {
                    $supportText = preg_replace('/^\s*with\s+/i', '', Normalizer::cleanText($supportNodes->item(0)->textContent)) ?? '';
                    foreach (array_filter(array_map('trim', explode(',', $supportText))) as $b) {
                        $bands[] = $b;
                    }
                }
                $bands = Normalizer::normalizeBandList($bands);
                if (empty($bands)) {
                    $bands = [$title];
                }

                $dateNodes = $xpath->query('.//p[contains(@class,"event-date")]', $container);
                if ($dateNodes === false || $dateNodes->length === 0) {
                    continue;
                }
                $eventDate = $this->parseShortDate($dateNodes->item(0)->textContent);
                if ($eventDate === '') {
                    continue;
                }

                $doorsTime = '';
                $showTime = '';

                $doorNode = $xpath->query('.//*[contains(@class,"see-doortime")]', $container);
                if ($doorNode !== false && $doorNode->length > 0) {
                    $doorsTime = strtolower(Normalizer::cleanText($doorNode->item(0)->textContent));
                }

                $showNode = $xpath->query('.//*[contains(@class,"see-showtime")]', $container);
                if ($showNode !== false && $showNode->length > 0) {
                    $showTime = strtolower(Normalizer::cleanText($showNode->item(0)->textContent));
                }

                $price = '';
                $priceNodes = $xpath->query('.//span[contains(@class,"price")]', $container);
                if ($priceNodes !== false && $priceNodes->length > 0) {
                    $price = Normalizer::cleanText($priceNodes->item(0)->textContent);
                }

                $ageRestriction = '';
                $headerNodes = $xpath->query('.//p[contains(@class,"event-header")]', $container);
                if ($headerNodes !== false && $headerNodes->length > 0) {
                    $headerText = Normalizer::cleanText($headerNodes->item(0)->textContent);
                    if (preg_match('/all\s*ages|18\+|21\+/i', $headerText, $am)) {
                        $ageRestriction = strtolower($am[0]);
                    }
                }

                $isSoldOut = 0;
                $btnNodes = $xpath->query('.//a[contains(@class,"seetickets-buy-btn")]', $container);
                if ($btnNodes !== false && $btnNodes->length > 0) {
                    $isSoldOut = stripos(Normalizer::cleanText($btnNodes->item(0)->textContent), 'sold out') !== false ? 1 : 0;
                }

                $sourceEventId = sha1($eventDate . '|' . $title . '|' . $ticketUrl);
                $events[] = [
                    'source_event_id' => $sourceEventId,
                    'source_url' => $ticketUrl,
                    'ticket_url' => $ticketUrl,
                    'venue_name' => 'Great American Music Hall',
                    'venue_city' => 'San Francisco',
                    'event_date' => $eventDate,
                    'title' => $title,
                    'subtitle' => count($bands) > 1 ? implode(', ', array_slice($bands, 1)) : '',
                    'bands' => $bands,
                    'show_time' => $showTime,
                    'doors_time' => $doorsTime,
                    'age_restriction' => $ageRestriction,
                    'price' => $price,
                    'is_sold_out' => $isSoldOut,
                    'is_ticketed' => 1,
                    'status' => $isSoldOut ? 'sold_out' : 'on_sale',
                    'raw_payload' => [
                        'source' => 'gamh',
                        'title' => $title,
                        'event_date' => $eventDate,
                        'ticket_url' => $ticketUrl,
                    ],
                ];
            }
        }

        return $events;
    }

    private function parseShortDate(string $value): string {
        $value = Normalizer::cleanText($value);
        if (!preg_match('/([A-Za-z]{3})\s+(\d{1,2})/', $value, $m)) {
            return '';
        }

        $months = [
            'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
            'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
        ];

        $month = $months[ucfirst(strtolower($m[1]))] ?? 0;
        $day = (int)$m[2];
        if ($month === 0 || $day === 0) {
            return '';
        }

        $currentMonth = (int)date('n');
        $year = (int)date('Y');
        if ($month < $currentMonth && $currentMonth >= 10) {
            $year++;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
