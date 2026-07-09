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
 * German language strings for tool_oauthmcp.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['accesstokenttl'] = 'Gültigkeitsdauer des Access-Tokens';
$string['accesstokenttl_desc'] = 'Gültigkeitsdauer der ausgestellten OAuth-Access-Tokens.';
$string['alloworigins'] = 'Zusätzlich erlaubte Origins';
$string['alloworigins_desc'] = 'Ein Origin pro Zeile (schema://host[:port]). Anfragen mit einem Origin-Header müssen dem Origin der Website oder einem dieser Einträge entsprechen. Leer lassen, um nur den Origin der Website zuzulassen.';
$string['authmode'] = 'Authentifizierungsmodus';
$string['authmode_both'] = 'Beide';
$string['authmode_desc'] = 'Wie sich MCP-Clients gegenüber dem Endpunkt authentifizieren. „Webservice-Token" akzeptiert Moodle-Webservice-Tokens als Bearer-Tokens. „OAuth 2.1" akzeptiert Tokens, die vom Autorisierungsserver dieses Plugins ausgestellt wurden. „Beide" akzeptiert jeweils beides.';
$string['authmode_oauth'] = 'OAuth 2.1';
$string['authmode_wstoken'] = 'Webservice-Token';
$string['client_add'] = 'Client manuell registrieren';
$string['client_authmethod'] = 'Authentifizierung am Token-Endpunkt';
$string['client_authmethod_desc'] = '„None" registriert einen öffentlichen Client (nur PKCE, der Normalfall für MCP-Clients); die Secret-Methoden registrieren einen vertraulichen Client.';
$string['client_enabled'] = 'Aktiviert';
$string['client_name'] = 'Client-Name';
$string['client_redirecturis'] = 'Redirect-URIs';
$string['client_redirecturis_desc'] = 'Eine exakte URI pro Zeile. Es werden nur https-URIs akzeptiert (sowie http auf localhost, sofern für die dynamische Registrierung erlaubt).';
$string['client_redirecturis_invalid'] = 'Ungültige Redirect-URI: {$a}';
$string['client_secret_notice'] = 'Client-Secret (wird nur einmal angezeigt, jetzt speichern): {$a}';
$string['clients'] = 'OAuth-Clients';
$string['clients_created'] = 'Erstellt';
$string['clients_dcr'] = 'DCR';
$string['clients_delete_confirm'] = 'Diesen Client samt aller seiner Tokens und Einwilligungen löschen?';
$string['clients_desc'] = 'OAuth-Clients, die dynamisch (RFC 7591) oder manuell registriert wurden. Das Deaktivieren eines Clients blockiert sofort alle seine Tokens.';
$string['clients_tokens'] = 'Aktive Tokens';
$string['clients_type'] = 'Typ';
$string['clients_type_confidential'] = 'Vertraulich';
$string['clients_type_public'] = 'Öffentlich';
$string['consent_approve'] = 'Zugriff erlauben';
$string['consent_deny'] = 'Ablehnen';
$string['consent_intro'] = 'Die Anwendung „{$a}" fordert in Ihrem Namen Zugriff auf diese Website mit den folgenden Berechtigungen an:';
$string['consent_nocapability'] = 'Ihr Konto ist auf dieser Website nicht für den MCP-Zugriff freigeschaltet. Bitten Sie Ihre Administratorin oder Ihren Administrator um die Berechtigung „Über MCP mit dieser Website verbinden".';
$string['consent_remember'] = 'Diese Entscheidung für diese Anwendung merken';
$string['consent_title'] = 'Anwendungszugriff autorisieren';
$string['consent_tools'] = 'Diese Freigabe schaltet derzeit {$a} Werkzeuge frei:';
$string['consentpolicy'] = 'Einwilligungsrichtlinie';
$string['consentpolicy_allow_remember'] = 'Nutzer/innen dürfen ihre Entscheidung merken';
$string['consentpolicy_always_ask'] = 'Einwilligungsbildschirm immer anzeigen';
$string['consentpolicy_desc'] = 'Ob Nutzer/innen den Einwilligungsbildschirm für bereits genehmigte Anwendungen überspringen dürfen.';
$string['dcrallowlocalhost'] = 'localhost-Redirect-URIs zulassen';
$string['dcrallowlocalhost_desc'] = 'http://localhost- und http://127.0.0.1-Redirect-URIs bei der dynamischen Client-Registrierung akzeptieren. Erforderlich für lokale Clients wie Claude Code, die für den OAuth-Callback auf einem Loopback-Port lauschen.';
$string['dcrenabled'] = 'Dynamische Client-Registrierung';
$string['dcrenabled_desc'] = 'Anonyme Client-Registrierung gemäß RFC 7591 erlauben. Erforderlich für claude.ai-Custom-Connectors. Registrierungen sind pro IP ratenbegrenzt und durch das untenstehende Kontingent gedeckelt.';
$string['dcrquota'] = 'Kontingent für dynamische Registrierung';
$string['dcrquota_desc'] = 'Maximale Anzahl aktiver dynamisch registrierter Clients. Weitere Registrierungen werden abgelehnt, bis veraltete entfernt wurden.';
$string['diag_dcr'] = 'Endpunkt für dynamische Client-Registrierung';
$string['diag_enabled'] = 'MCP-Server aktiviert';
$string['diag_fail'] = 'FEHLER';
$string['diag_https'] = 'Website über HTTPS ausgeliefert';
$string['diag_ok'] = 'OK';
$string['diag_server401'] = 'MCP-Endpunkt sendet eine RFC-9728-Challenge';
$string['diag_warn'] = 'PRÜFEN';
$string['diag_wellknown_as'] = 'Webroot-Alias /.well-known/oauth-authorization-server';
$string['diag_wellknown_hint'] = 'Die Webroot-Aliase benötigen Rewrite-Regeln außerhalb von Moodle; siehe die Snippets in .well-known-snippets/ und die README. Ohne sie können claude.ai-Custom-Connectors den Autorisierungsserver nicht finden; header-authentifizierte Clients sind nicht betroffen.';
$string['diag_wellknown_prm'] = 'Webroot-Alias /.well-known/oauth-protected-resource';
$string['diagnostics'] = 'MCP-Verbindungsprüfung';
$string['enabled'] = 'MCP-Server aktivieren';
$string['enabled_desc'] = 'Hauptschalter. Solange deaktiviert, beantwortet der MCP-Endpunkt jede Anfrage mit HTTP 503.';
$string['event_auth_failed'] = 'MCP-Authentifizierung fehlgeschlagen';
$string['event_client_registered'] = 'OAuth-Client registriert';
$string['event_consent_given'] = 'OAuth-Einwilligung erteilt';
$string['event_token_issued'] = 'OAuth-Token ausgestellt';
$string['event_token_refused'] = 'OAuth-Token-Anfrage abgelehnt';
$string['event_token_revoked'] = 'OAuth-Token widerrufen';
$string['event_tool_called'] = 'MCP-Werkzeug aufgerufen';
$string['exposedservices'] = 'Bereitgestellte Webservice-Funktionen';
$string['exposedservices_info'] = 'Die externen Funktionen, die dem dedizierten Service <strong>MCP server</strong> zugeordnet sind, werden als MCP-Werkzeuge veröffentlicht. Um weitere Funktionen bereitzustellen, ordnen Sie sie diesem Service auf dem üblichen Weg unter „Externe Services" zu: <a href="{$a}">Funktionen des MCP-Service verwalten</a>. Jede bereitgestellte Funktion ist beim Aufruf weiterhin durch ihre eigenen erforderlichen Rechte abgesichert, und einzelne Werkzeuge lassen sich auf der Werkzeug-Governance-Seite deaktivieren. Werkzeuge, die Plugins nativ beisteuern (etwa Agent-Skills), werden automatisch bereitgestellt und sind hier nicht aufgeführt.';
$string['mcp_disabled'] = 'Der MCP-Server ist auf dieser Website deaktiviert.';
$string['mcp_error_rate_limited'] = 'Ratenlimit überschritten. Bitte später erneut versuchen.';
$string['mcp_error_scope_denied'] = 'Das Werkzeug „{$a}" erfordert den Scope mcp:write, den dieses Token nicht besitzt.';
$string['mcp_error_tool_disabled'] = 'Das Werkzeug „{$a}" wurde von der Administration deaktiviert.';
$string['mcp_error_tool_failed'] = 'Werkzeugausführung fehlgeschlagen: {$a}';
$string['mcp_error_unknown_tool'] = 'Unbekanntes Werkzeug: {$a}';
$string['mcp_instructions'] = 'Dieser Server stellt Funktionen der Moodle-Website als Werkzeuge bereit. Alle Aufrufe laufen als die/der authentifizierte Moodle-Nutzer/in mit deren/dessen üblichen Berechtigungen. Als destruktiv markierte Werkzeuge ändern Website-Daten; rufen Sie sie erst auf, nachdem die/der Nutzer/in die konkrete Aktion bestätigt hat.';
$string['mcpsessionttl'] = 'Lebensdauer der MCP-Sitzung';
$string['mcpsessionttl_desc'] = 'Untätige MCP-Protokollsitzungen werden nach diesem Zeitraum verworfen und der Client muss sich neu initialisieren.';
$string['oauthheading'] = 'OAuth-2.1-Autorisierungsserver';
$string['oauthheading_desc'] = 'Einstellungen des integrierten Autorisierungsservers, der es entfernten MCP-Clients (einschließlich claude.ai-Custom-Connectors) ermöglicht, sich per OAuth zu verbinden. Nutzer/innen authentifizieren sich mit dem normalen Moodle-Login; Tokens sind undurchsichtig (opaque) und können auf der Profilseite „Verbundene MCP-Apps" widerrufen werden.';
$string['oauthmcp:connect'] = 'Über MCP mit dieser Website verbinden';
$string['oauthmcp:manageclients'] = 'OAuth-/MCP-Client-Registrierungen verwalten';
$string['pluginname'] = 'MCP-Server (OAuth 2.1)';
$string['privacy:metadata:core_event'] = 'Das Plugin schreibt OAuth- und Werkzeugaufruf-Audit-Ereignisse in den Standard-Logspeicher.';
$string['privacy:metadata:tool_oauthmcp_authcode'] = 'Kurzlebige OAuth-Autorisierungscodes, die der/dem Nutzer/in während des Login-Ablaufs ausgestellt werden.';
$string['privacy:metadata:tool_oauthmcp_authcode:clientdbid'] = 'Der Client, dem der Code ausgestellt wurde.';
$string['privacy:metadata:tool_oauthmcp_authcode:timecreated'] = 'Wann der Code ausgestellt wurde.';
$string['privacy:metadata:tool_oauthmcp_authcode:userid'] = 'Die/der Nutzer/in, für die/den der Code ausgestellt wurde.';
$string['privacy:metadata:tool_oauthmcp_client'] = 'OAuth-Clients, die manuell von einer Administratorin oder einem Administrator erstellt wurden (die erstellende Person wird gespeichert).';
$string['privacy:metadata:tool_oauthmcp_client:createdby'] = 'Die Administratorin oder der Administrator, die/der den Client erstellt hat.';
$string['privacy:metadata:tool_oauthmcp_client:name'] = 'Der Client-Name.';
$string['privacy:metadata:tool_oauthmcp_client:registrationip'] = 'Die IP-Adresse, von der aus der Client registriert wurde.';
$string['privacy:metadata:tool_oauthmcp_client:timecreated'] = 'Wann der Client erstellt wurde.';
$string['privacy:metadata:tool_oauthmcp_consent'] = 'Vermerkt, dass eine/ein Nutzer/in einem OAuth-Client in ihrem/seinem Namen Zugriff auf die Website gewährt hat.';
$string['privacy:metadata:tool_oauthmcp_consent:clientdbid'] = 'Der Client, dem die Einwilligung erteilt wurde.';
$string['privacy:metadata:tool_oauthmcp_consent:scopes'] = 'Die Scopes, in die die/der Nutzer/in eingewilligt hat.';
$string['privacy:metadata:tool_oauthmcp_consent:timecreated'] = 'Wann die Einwilligung erstmals erteilt wurde.';
$string['privacy:metadata:tool_oauthmcp_consent:userid'] = 'Die/der Nutzer/in, die/der die Einwilligung erteilt hat.';
$string['privacy:metadata:tool_oauthmcp_refresh'] = 'OAuth-Refresh-Tokens, die der/dem Nutzer/in ausgestellt wurden.';
$string['privacy:metadata:tool_oauthmcp_refresh:clientdbid'] = 'Der Client, dem das Token ausgestellt wurde.';
$string['privacy:metadata:tool_oauthmcp_refresh:timecreated'] = 'Wann das Token ausgestellt wurde.';
$string['privacy:metadata:tool_oauthmcp_refresh:userid'] = 'Die/der Nutzer/in, für die/den das Token ausgestellt wurde.';
$string['privacy:metadata:tool_oauthmcp_session'] = 'Aktive MCP-Protokollsitzungen der/des Nutzer/in.';
$string['privacy:metadata:tool_oauthmcp_session:lastseen'] = 'Wann die Sitzung zuletzt verwendet wurde.';
$string['privacy:metadata:tool_oauthmcp_session:timecreated'] = 'Wann die Sitzung geöffnet wurde.';
$string['privacy:metadata:tool_oauthmcp_session:userid'] = 'Die/der Nutzer/in, zu der/dem die Sitzung gehört.';
$string['privacy:metadata:tool_oauthmcp_token'] = 'OAuth-Access-Tokens, die der/dem Nutzer/in ausgestellt wurden (gehasht gespeichert).';
$string['privacy:metadata:tool_oauthmcp_token:clientdbid'] = 'Der Client, dem das Token ausgestellt wurde.';
$string['privacy:metadata:tool_oauthmcp_token:lastused'] = 'Wann das Token zuletzt verwendet wurde.';
$string['privacy:metadata:tool_oauthmcp_token:scopes'] = 'Die Scopes, die das Token trägt.';
$string['privacy:metadata:tool_oauthmcp_token:timecreated'] = 'Wann das Token ausgestellt wurde.';
$string['privacy:metadata:tool_oauthmcp_token:userid'] = 'Die/der Nutzer/in, für die/den das Token ausgestellt wurde.';
$string['ratelimitregister'] = 'Ratenlimit für Registrierungen';
$string['ratelimitregister_desc'] = 'Maximale dynamische Client-Registrierungen pro IP pro Stunde.';
$string['ratelimittoken'] = 'Ratenlimit für Token-Endpunkt';
$string['ratelimittoken_desc'] = 'Maximale Token-Anfragen pro IP pro Minute.';
$string['ratelimittools'] = 'Ratenlimit für Werkzeugaufrufe';
$string['ratelimittools_desc'] = 'Maximale Anzahl an MCP-Werkzeugaufrufen pro Nutzer/in pro Minute. 0 deaktiviert das Limit.';
$string['refreshtokenttl'] = 'Gültigkeitsdauer des Refresh-Tokens';
$string['refreshtokenttl_desc'] = 'Gültigkeitsdauer der ausgestellten OAuth-Refresh-Tokens. Refresh-Tokens rotieren bei jeder Verwendung; die Wiederverwendung eines rotierten Tokens widerruft die gesamte Freigabe.';
$string['scope_read_desc'] = 'Informationen der Website in Ihrem Namen lesen (mcp:read)';
$string['scope_write_desc'] = 'Website-Daten in Ihrem Namen ändern (mcp:write)';
$string['settingspage'] = 'MCP-Server-Einstellungen';
$string['task_purge_expired'] = 'Abgelaufene MCP-Sitzungen und Tokens bereinigen';
$string['toolgovernance'] = 'MCP-Werkzeug-Governance';
$string['toolgovernance_desc'] = 'Alle Werkzeuge, die der MCP-Server derzeit veröffentlicht. Deaktivierte Werkzeuge verschwinden aus den Auflistungen und verweigern Aufrufe. Als schreibgeschützt markierte Werkzeuge stehen Tokens zur Verfügung, die nur den Scope mcp:read tragen.';
$string['toolgovernance_enabled'] = 'Aktiviert';
$string['toolgovernance_name'] = 'Werkzeug';
$string['toolgovernance_readonly'] = 'Schreibgeschützt';
$string['toolgovernance_source'] = 'Quelle';
$string['toolgovernance_updated'] = 'Werkzeugeinstellungen aktualisiert.';
$string['userapps'] = 'Verbundene MCP-Apps';
$string['userapps_client'] = 'Anwendung';
$string['userapps_first'] = 'Erstmals autorisiert';
$string['userapps_lastused'] = 'Zuletzt verwendet';
$string['userapps_none'] = 'Mit Ihrem Konto sind keine Anwendungen verbunden.';
$string['userapps_revoke'] = 'Zugriff widerrufen';
$string['userapps_revoked'] = 'Zugriff widerrufen.';
$string['userapps_scopes'] = 'Berechtigungen';
$string['wstokenanyservice'] = 'Tokens jedes Webservice akzeptieren';
$string['wstokenanyservice_desc'] = 'Standardmäßig werden nur Tokens akzeptiert, die für den plugin-eigenen Service „MCP server (tool_oauthmcp)" ausgestellt wurden. Aktivieren Sie diese Option, um gültige Tokens jedes aktivierten Webservice zu akzeptieren (die Verbinden-Berechtigung ist weiterhin erforderlich).';
