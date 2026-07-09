Vendored OAuth 2.1 stack for tool_oauthmcp
==========================================

Contents: league/oauth2-server ^9.4 and its dependency tree, installed with
Composer (see composer.json / composer.lock in this directory for the exact
pinned versions) and committed wholesale including the generated autoloader.

PSR interface packages are shipped even though Moodle core vendors some of
them (lib/psr/*): the classes are interface-identical, autoloaders only load
what is not already declared, and shipping them keeps this tree installable
on every supported Moodle version.

Update procedure:
1. mkdir /tmp/oauthmcp-vendor && cd /tmp/oauthmcp-vendor
2. cp <plugin>/vendor-oauth2/composer.json .
3. composer update --update-no-dev
4. rm -rf <plugin>/vendor-oauth2 && cp -R vendor <plugin>/vendor-oauth2
5. rm -rf <plugin>/vendor-oauth2/bin
6. cp composer.json composer.lock <plugin>/vendor-oauth2/
7. Restore this file; update versions in ../thirdpartylibs.xml.
