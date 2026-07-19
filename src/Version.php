<?php
/**
 * Version.php — App and runtime version helper.
 *
 * Version::app() returns the web application's own version, read from
 * composer.json. Version::runtime() returns the daemon-published runtime
 * manifest at {PORPASS_STORAGE_PATH}/runtime.json, falling back to
 * 'unknown' values when the file is missing or the storage path is unset.
 */

namespace porpass;

final class Version
{
    /**
     * Web application version, read from composer.json.
     */
    public static function app(): string
    {
        static $cached = null;
        if ($cached === null) {
            $data   = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            $cached = $data['version'];
        }
        return $cached;
    }

    /**
     * Runtime manifest published by the daemon.
     *
     * Returns keys 'daemon', 'grasp', 'published_at'. Missing storage path
     * or missing runtime.json yields the 'unknown' default so callers can
     * render the footer without conditional guards.
     */
    public static function runtime(): array
    {
        static $cached = null;
        if ($cached === null) {
            $default = ['daemon' => 'unknown', 'grasp' => 'unknown', 'published_at' => null];
            $storage = $_ENV['PORPASS_STORAGE_PATH'] ?? '';
            $json    = $storage === ''
                ? false
                : @file_get_contents(rtrim($storage, '/') . '/runtime.json');
            $data    = $json === false ? null : json_decode($json, true);
            $cached  = is_array($data) ? $data + $default : $default;
        }
        return $cached;
    }
}
