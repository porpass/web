<?php
/**
 * StatusChecker.php — Checks connectivity to external data sources.
 *
 * Uses HTTP HEAD requests with a short timeout to test reachability of the
 * PDS, DARTS, and Planetary Maps (USGS Astrogeology) services. Results are
 * cached to a JSON file with a configurable TTL (default 5 minutes) so that
 * page loads are never blocked by slow or down services.
 */

namespace porpass;

class StatusChecker
{
    /** @var int Cache time-to-live in seconds. */
    private int $ttl;

    /** @var string Path to the JSON cache file. */
    private string $cache_file;

    /** @var int HTTP timeout per service in seconds. */
    private int $timeout;

    /** @var array<string, array{name: string, url: string}> Services to check. */
    private const SERVICES = [
        'pds' => [
            'name' => 'PDS Geosciences Node',
            'url'  => 'https://pds-geosciences.wustl.edu/',
        ],
        'darts' => [
            'name' => 'DARTS (JAXA)',
            'url'  => 'https://data.darts.isas.jaxa.jp/pub/pds3/',
        ],
        'astrogeo' => [
            'name' => 'Planetary Maps (USGS)',
            'url'  => 'https://planetarymaps.usgs.gov/',
        ],
    ];

    /**
     * @param int    $ttl        Cache TTL in seconds (default 300 = 5 min).
     * @param string $cache_file Path to cache file.
     * @param int    $timeout    HTTP timeout per request in seconds.
     */
    public function __construct(
        int    $ttl        = 300,
        string $cache_file = '/tmp/porpass_status_cache.json',
        int    $timeout    = 5
    ) {
        $this->ttl        = $ttl;
        $this->cache_file = $cache_file;
        $this->timeout    = $timeout;
    }

    /**
     * Return the cached status array, refreshing if stale.
     *
     * @return array{services: array, checked_at: string, cached: bool}
     */
    public function getStatus(): array
    {
        $cached = $this->readCache();

        if ($cached !== null) {
            $cached['cached'] = true;
            return $cached;
        }

        return $this->refresh();
    }

    /**
     * Force a fresh check of all services and update the cache.
     *
     * @return array{services: array, checked_at: string, cached: bool}
     */
    public function refresh(): array
    {
        $results = $this->checkAll();

        $payload = [
            'services'   => $results,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'cached'     => false,
        ];

        $this->writeCache($payload);

        return $payload;
    }

    /**
     * Check all services in parallel using curl_multi.
     *
     * @return array<string, array{name: string, url: string, up: bool, http_code: int, response_time_ms: int}>
     */
    private function checkAll(): array
    {
        $multi   = curl_multi_init();
        $handles = [];

        foreach (self::SERVICES as $key => $svc) {
            $ch = curl_init($svc['url']);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,   // HEAD request
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'PORPASS-StatusChecker/1.0',
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        // Execute all requests in parallel.
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi);
            }
        } while ($active && $status === CURLM_OK);

        // Collect results.
        $results = [];
        foreach (self::SERVICES as $key => $svc) {
            $ch        = $handles[$key];
            $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $time_ms   = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $error     = curl_error($ch);

            $results[$key] = [
                'name'             => $svc['name'],
                'url'              => $svc['url'],
                'up'               => $http_code >= 200 && $http_code < 400,
                'http_code'        => $http_code,
                'response_time_ms' => $time_ms,
            ];

            if ($error) {
                $results[$key]['error'] = $error;
            }

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $results;
    }

    /**
     * Read the cache file if it exists and is not stale.
     *
     * @return array|null Cached payload, or null if stale/missing.
     */
    private function readCache(): ?array
    {
        if (!file_exists($this->cache_file)) {
            return null;
        }

        $mtime = filemtime($this->cache_file);
        if ($mtime === false || (time() - $mtime) > $this->ttl) {
            return null;
        }

        $json = file_get_contents($this->cache_file);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Write a payload to the cache file.
     */
    private function writeCache(array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->cache_file, $json, LOCK_EX);
    }
}