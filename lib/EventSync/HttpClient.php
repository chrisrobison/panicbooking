<?php

namespace PanicBooking\EventSync;

class HttpClient {
    public static function fetch(string $url, int $timeoutSeconds = 30): string|false {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: PanicBooking-EventSync/1.0\r\n",
                'timeout' => $timeoutSeconds,
            ],
        ];

        $ctx = stream_context_create($opts);
        return @file_get_contents($url, false, $ctx);
    }
}
