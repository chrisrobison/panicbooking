<?php

namespace PanicBooking\EventSync;

class VenueCatalog {
    /** @var array<int, array<string, mixed>> */
    private array $venues;
    /** @var array<string, array<string, mixed>> */
    private array $bySlug;
    /** @var array<string, string> */
    private array $aliasToSlug;

    /**
     * @param array<int, array<string, mixed>> $venues
     */
    public function __construct(array $venues) {
        $this->venues = $venues;
        $this->bySlug = [];
        $this->aliasToSlug = [];

        foreach ($venues as $venue) {
            $slug = (string)($venue['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $normalized = $this->withDefaults($venue);
            $this->bySlug[$slug] = $normalized;

            $names = array_merge(
                [(string)$normalized['display_name']],
                array_map('strval', (array)($normalized['aliases'] ?? []))
            );

            foreach ($names as $name) {
                $key = Normalizer::canonicalNameKey($name);
                if ($key !== '') {
                    $this->aliasToSlug[$key] = $slug;
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array {
        return array_values($this->bySlug);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBySlug(string $slug): ?array {
        return $this->bySlug[$slug] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveByName(string $venueName, string $city = 'San Francisco', string $state = 'CA'): array {
        $cleanName = Normalizer::cleanText($venueName);
        $key = Normalizer::canonicalNameKey($cleanName);

        if ($key !== '' && isset($this->aliasToSlug[$key])) {
            $slug = $this->aliasToSlug[$key];
            return $this->bySlug[$slug];
        }

        $slug = Normalizer::slugify($cleanName);
        $discovered = [
            'slug' => $slug,
            'display_name' => $cleanName === '' ? 'Unknown Venue' : $cleanName,
            'aliases' => [],
            'city' => $city,
            'state' => $state,
            'venue_type' => 'club',
            'capacity_estimate' => null,
            'prestige_weight' => 0.45,
            'activity_weight' => 0.60,
            'source_priority_default' => 40,
            'sync_enabled' => true,
            'official_calendar_url' => '',
            'adapter_class' => null,
            'is_core_venue' => false,
            'has_official_sync' => false,
            'notoriety_multiplier' => 1.0,
        ];

        return $this->withDefaults($discovered);
    }

    /**
     * @return array<string, mixed>
     */
    private function withDefaults(array $venue): array {
        return [
            'slug' => (string)($venue['slug'] ?? ''),
            'display_name' => (string)($venue['display_name'] ?? ''),
            'aliases' => array_values(array_map('strval', (array)($venue['aliases'] ?? []))),
            'city' => (string)($venue['city'] ?? 'San Francisco'),
            'state' => (string)($venue['state'] ?? 'CA'),
            'venue_type' => (string)($venue['venue_type'] ?? 'club'),
            'capacity_estimate' => isset($venue['capacity_estimate']) ? (int)$venue['capacity_estimate'] : null,
            'prestige_weight' => (float)($venue['prestige_weight'] ?? 0.5),
            'activity_weight' => (float)($venue['activity_weight'] ?? 0.7),
            'source_priority_default' => (int)($venue['source_priority_default'] ?? 60),
            'sync_enabled' => (bool)($venue['sync_enabled'] ?? true),
            'official_calendar_url' => (string)($venue['official_calendar_url'] ?? ''),
            'adapter_class' => $venue['adapter_class'] ?? null,
            'ticketmaster_venue_id' => (string)($venue['ticketmaster_venue_id'] ?? ''),
            'is_core_venue' => (bool)($venue['is_core_venue'] ?? false),
            'has_official_sync' => (bool)($venue['has_official_sync'] ?? false),
            'notoriety_multiplier' => (float)($venue['notoriety_multiplier'] ?? 1.0),
        ];
    }
}
