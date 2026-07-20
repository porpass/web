<?php
/**
 * Version.php — App and runtime version helper.
 *
 * Version::app() returns the web application's own version, derived from
 * the installed package version (the deployed git tag) via Composer's
 * runtime API. Version::runtime() returns the daemon-published runtime
 * manifest at {PORPASS_STORAGE_PATH}/runtime.json, falling back to
 * 'unknown' values when the file is missing or the storage path is unset.
 */

namespace porpass;

use Composer\InstalledVersions;

final class Version
{
    /**
     * Web application version, derived from the installed package version.
     *
     * On a deployed tag this is the release version (e.g. 0.1.0-alpha.4).
     * On a branch checkout there is no tag to derive from, so Composer
     * reports a dev version (e.g. dev-develop), returned as-is.
     */
    public static function app(): string
    {
        static $cached = null;
        if ($cached === null) {
            $version = InstalledVersions::getRootPackage()['pretty_version'] ?? 'unknown';
            $cached  = ltrim($version, 'v');
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
