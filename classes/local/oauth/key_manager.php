<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Key material for the OAuth authorization server.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth;

/**
 * Generates and serves the RSA keypair and the Defuse encryption key.
 *
 * The RSA key signs/encrypts the library's internal artefacts (auth code
 * payloads); the Defuse key encrypts wire-format auth codes and refresh
 * tokens. Access tokens are opaque (DB-backed) and never leave the server
 * as signed material, so no public key distribution exists.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_manager {
    /**
     * Path of the private key file, generating the keypair on first use.
     *
     * @return string
     */
    public static function private_key_path(): string {
        global $CFG;

        $dir = $CFG->dataroot . '/tool_oauthmcp';
        $private = $dir . '/oauth_private.key';
        if (!file_exists($private)) {
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $resource = openssl_pkey_new([
                'private_key_bits' => 4096,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($resource === false) {
                throw new \moodle_exception('generalexceptionmessage', 'error', '', 'Cannot generate OAuth keypair');
            }
            openssl_pkey_export($resource, $privatepem);
            file_put_contents($private, $privatepem);
            chmod($private, 0600);
            $details = openssl_pkey_get_details($resource);
            file_put_contents($dir . '/oauth_public.key', $details['key']);
            chmod($dir . '/oauth_public.key', 0600);
        }
        return $private;
    }

    /**
     * The Defuse encryption key (generated once, stored in plugin config).
     *
     * @return string Key in Defuse ASCII-safe format.
     */
    public static function encryption_key(): string {
        vendor_loader::load();

        $key = (string)get_config('tool_oauthmcp', 'encryptionkey');
        if ($key === '') {
            $key = \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();
            set_config('encryptionkey', $key, 'tool_oauthmcp');
        }
        return $key;
    }
}
