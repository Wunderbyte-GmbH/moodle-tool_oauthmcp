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

namespace tool_oauthmcp;

use tool_oauthmcp\local\oauth\vendor_loader;

/**
 * Regression test: OAuth redirect URIs must be built with '&', not Moodle's global '&amp;'.
 *
 * Moodle sets arg_separator.output to '&amp;' in lib/setup.php, which leaks into every bare
 * http_build_query() — including the two inside the vendored league/oauth2-server that build
 * authorization redirect URIs. Clients then receive "?code=X&amp;state=Y" in a Location header,
 * read the second parameter as "amp;state" and abandon the flow before the token endpoint.
 * vendor_loader::load() corrects the ini; this locks that behaviour down.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_oauthmcp\local\oauth\vendor_loader
 */
final class arg_separator_test extends \advanced_testcase {
    /**
     * load() must restore PHP's default separator even under Moodle's global override.
     *
     * @return void
     */
    public function test_load_restores_php_default_separator(): void {
        // Recreate the hostile premise exactly as lib/setup.php applies it on every request.
        // Setting it here (rather than trusting the phpunit bootstrap) keeps the test meaningful
        // even when an earlier test in this process already loaded the vendor: load() must
        // guarantee the separator on every call, not only the first.
        ini_set('arg_separator.output', '&amp;');

        vendor_loader::load();

        $this->assertSame('&', ini_get('arg_separator.output'));

        // The exact operation the library performs when building the authorization redirect
        // (AbstractAuthorizeGrant::makeRedirectUri()) and error redirects
        // (OAuthServerException::generateHttpResponse()): a bare http_build_query().
        $this->assertSame('code=X&state=Y', http_build_query(['code' => 'X', 'state' => 'Y']));
    }
}
