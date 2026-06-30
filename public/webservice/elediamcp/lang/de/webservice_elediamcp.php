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
 * German language strings for the MCP web service plugin.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2025 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['claude_config_file'] = 'Speicherort der Konfigurationsdatei';
$string['claude_config_file_help'] = 'Unter macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>. Unter Windows: <code>%APPDATA%\\Claude\\claude_desktop_config.json</code>. Erstellen Sie die Datei, falls sie nicht existiert.';
$string['claude_connect_heading'] = 'MCP-Client verbinden (Claude Desktop)';
$string['claude_connect_intro'] = 'Claude Desktop und andere reine stdio-MCP-Clients verbinden sich über die <a href="https://www.npmjs.com/package/mcp-remote" target="_blank" rel="noopener">mcp-remote</a>-Brücke mit diesem entfernten Server. Diese benötigt <a href="https://nodejs.org/" target="_blank" rel="noopener">Node.js</a> (für <code>npx</code>) auf dem Client-Rechner. Fügen Sie den folgenden Ausschnitt zu Ihrer <code>claude_desktop_config.json</code> hinzu, ersetzen Sie das Token und starten Sie Claude Desktop anschließend vollständig neu.';
$string['claude_connect_serverurl'] = 'MCP-Server-URL';
$string['claude_connect_snippet'] = 'Claude-Desktop-Konfiguration';
$string['claude_connect_snippet_withtoken'] = 'Einsatzbereite Claude-Desktop-Konfiguration (Ihr neues Token ist bereits eingetragen – kopieren Sie es jetzt)';
$string['claude_connect_tokenhint'] = 'Ersetzen Sie <code>{$a}</code> durch ein oben erstelltes Token.';
$string['configuration_error_invalid_origin'] = 'Jeder CORS-Origin muss ein absoluter http(s)-Origin sein, zum Beispiel https://app.example.com.';
$string['configuration_error_nonnegative'] = 'Geben Sie einen Wert von null oder höher ein.';
$string['configuration_error_wildcard_origin'] = 'Ein Platzhalter-CORS-Origin (*) ist hier nicht zulässig. Fügen Sie stattdessen explizite vertrauenswürdige Origins hinzu.';
$string['configuration_heading'] = 'MCP-Konfiguration';
$string['configuration_hint'] = 'Konfigurieren Sie externe Dienste, die Token-Richtlinie, Sicherheitsgrenzen und den MCP-Werkzeugkatalog.';
$string['configuration_saved'] = 'MCP-Konfiguration gespeichert.';
$string['configuration_security_heading'] = 'Sicherheit und Grenzen';
$string['configuration_services_heading'] = 'Dienste und Tokens';
$string['configuration_shell_link'] = 'MCP-Plugin-Shell öffnen';
$string['configuration_shell_link_desc'] = 'Öffnet die plugin-eigene MCP-Konfigurationsseite.';
$string['configuration_tag_mcp'] = 'MCP';
$string['configuration_tag_security'] = 'Sicherheit';
$string['configuration_tagline'] = 'Konfiguration';
$string['configuration_tools_heading'] = 'Werkzeugkatalog';
$string['disabled'] = 'deaktiviert';
$string['elediamcp:managetokens'] = 'Eigene MCP-Tokens erstellen und widerrufen';
$string['elediamcp:use'] = 'MCP-Webservice verwenden';
$string['elediamcp:viewcaps'] = 'Eigenen Fähigkeitsbaum über MCP anzeigen';
$string['err_emergency_disabled'] = 'Der MCP-Webservice ist vorübergehend durch die Website-Administration deaktiviert.';
$string['err_empty_request'] = 'Der Anfragetext ist leer';
$string['err_forbidden_origin'] = 'Origin durch die Website-Richtlinie nicht zugelassen';
$string['err_invalid_json'] = 'Ungültiges JSON';
$string['err_invalid_jsonrpc'] = 'Ungültige JSON-RPC-Version';
$string['err_invalid_protocol_version'] = 'Nicht unterstützte MCP-Protokollversion';
$string['err_missing_method'] = 'Methode fehlt';
$string['err_missing_tool_name'] = 'Werkzeugname fehlt';
$string['err_not_mcp_service'] = 'Dieses Token ist nicht für den MCP-Dienst autorisiert.';
$string['err_rate_limit_exceeded'] = 'Ratenbegrenzung überschritten. Erneuter Versuch nach {$a} Sekunden.';
$string['err_request_too_large'] = 'Der Anfragetext überschreitet die maximal zulässige Größe';
$string['err_token_in_query_disabled'] = 'Token im Query-String ist durch die Website-Richtlinie deaktiviert. Verwenden Sie stattdessen den Authorization-Header.';
$string['error_expiry_in_past'] = 'Das Ablaufdatum muss in der Zukunft liegen.';
$string['error_invalid_component'] = 'Unbekannte Komponente \'{$a}\'. MCP-Tokens können nur im Namen einer installierten Moodle-Komponente bereitgestellt werden.';
$string['error_label_required'] = 'Eine Token-Bezeichnung ist erforderlich.';
$string['error_service_disabled'] = 'Der ausgewählte Webservice ist deaktiviert.';
$string['error_service_not_mcp'] = 'Der ausgewählte Webservice ist nicht als MCP-Dienst konfiguriert. Tokens können nur für konfigurierte MCP-Dienste erstellt werden.';
$string['error_token_not_found'] = 'Das angeforderte MCP-Token existiert nicht.';
$string['error_token_not_owned_by_component'] = 'Dieses MCP-Token wurde nicht von der aufrufenden Komponente bereitgestellt und kann nicht über die interne API widerrufen werden.';
$string['event_context_verified'] = 'MCP-Benutzerkontext verifiziert';
$string['event_context_verified_desc'] = 'Die Person mit der ID \'{$a->userid}\' hat ihren MCP-Kontext verifiziert (Kursfilter: {$a->coursefilter}).';
$string['event_token_created'] = 'MCP-Token erstellt';
$string['event_token_created_desc'] = 'Die Person mit der ID \'{$a->userid}\' hat das MCP-Token \'{$a->label}\' für die Person mit der ID \'{$a->relateduserid}\' für den Dienst \'{$a->service}\' erstellt (über {$a->component}).';
$string['event_token_revoked'] = 'MCP-Token widerrufen';
$string['event_token_revoked_desc'] = 'Die Person mit der ID \'{$a->userid}\' hat das MCP-Token \'{$a->label}\' der Person mit der ID \'{$a->relateduserid}\' für den Dienst \'{$a->service}\' widerrufen.';
$string['event_tool_invoked'] = 'MCP-Werkzeug aufgerufen';
$string['event_tool_invoked_desc'] = 'Die Person mit der ID \'{$a->userid}\' hat das MCP-Werkzeug \'{$a->toolname}\' aufgerufen (isError: {$a->iserror}, Dauer: {$a->durationms} ms).';
$string['event_write_performed'] = 'MCP-Schreibaktion ausgeführt';
$string['event_write_performed_desc'] = 'Die Person mit der ID \'{$a->userid}\' hat über das MCP-Werkzeug \'{$a->toolname}\' eine Schreibaktion ausgeführt.';
$string['pluginname'] = 'Model Context Protocol';
$string['premium_status_active'] = 'Premium aktiv';
$string['premium_status_active_notice'] = 'Das Premium-Add-on schaltet den vollständigen kuratierten MCP-Werkzeugkatalog frei. Rohe Moodle-Webservice-Funktionen erfordern weiterhin die separate Einstellung unten.';
$string['premium_status_free'] = 'Kostenlose Version';
$string['premium_status_free_notice'] = 'Die kostenlose Version stellt nur die grundlegenden MCP-Werkzeuge bereit. Installieren und aktivieren Sie das Add-on „eLeDia.ai Tutor Premium“ mit der Funktion mcp_tools, um den vollständigen Katalog freizuschalten.';
$string['premium_status_free_tools'] = '{$a} kostenlose Werkzeuge';
$string['premium_status_heading'] = 'Edition und Werkzeugzugriff';
$string['premium_status_intro'] = '{$a->edition}: {$a->freecount} kostenlose Werkzeuge sind verfügbar. Premium fügt {$a->premiumcount} weitere kuratierte Werkzeuge hinzu.';
$string['premium_status_premium_tool_list'] = 'Premium-Werkzeuge';
$string['premium_status_premium_tools'] = '{$a} Premium-Werkzeuge';
$string['privacy:metadata:webservice_elediamcp_token'] = 'Metadaten zu MCP-Webservice-Tokens, die an eine Person oder in deren Namen ausgegeben wurden. Das Token-Geheimnis selbst wird hier niemals gespeichert.';
$string['privacy:metadata:webservice_elediamcp_token:component'] = 'Die Erstanbieter-Komponente, die das Token bereitgestellt hat, sofern vorhanden.';
$string['privacy:metadata:webservice_elediamcp_token:creatorid'] = 'Die Person, die das Token erstellt hat.';
$string['privacy:metadata:webservice_elediamcp_token:externalserviceid'] = 'Der externe Dienst, auf den das Token beschränkt ist.';
$string['privacy:metadata:webservice_elediamcp_token:lastaccess'] = 'Der Zeitpunkt der letzten Verwendung des Tokens.';
$string['privacy:metadata:webservice_elediamcp_token:name'] = 'Die Token-Bezeichnung.';
$string['privacy:metadata:webservice_elediamcp_token:revoked'] = 'Ob das Token widerrufen wurde.';
$string['privacy:metadata:webservice_elediamcp_token:revokedby'] = 'Die Person, die das Token widerrufen hat.';
$string['privacy:metadata:webservice_elediamcp_token:timecreated'] = 'Der Zeitpunkt, zu dem das Token erstellt wurde.';
$string['privacy:metadata:webservice_elediamcp_token:userid'] = 'Die Person, als die sich das Token authentifiziert.';
$string['privacy:metadata:webservice_elediamcp_token:validuntil'] = 'Der Ablaufzeitpunkt des Tokens.';
$string['server_instructions'] = 'Moodle-MCP-Server mit kuratierten KI-nativen Werkzeugen.

Identität & Kontext: Rufen Sie zuerst moodle_me auf, um die Identität zu bestätigen, anschließend moodle_verify_user_context für die vollständige Prüfung von Rollen/Gruppen/Fähigkeiten.

Personensuche: moodle_find_user löst ein freies Namensfragment (z. B. "erika") in eine Liste anschreibbarer Moodle-Nutzer/innen mit konkreten IDs auf. Verwenden Sie es VOR moodle_send_message, wann immer Sie die genaue Benutzer-ID der empfangenden Person noch nicht kennen.

Kurssuche: moodle_my_courses listet eingeschriebene Kurse auf; moodle_search_courses durchsucht den öffentlichen Katalog; moodle_course_contents zählt Abschnitte/Aktivitäten eines Kurses auf; moodle_get_resource gibt den Inhalt einer Seite/eines Buchkapitels/einer Textseite/einer URL/einer Datei anhand der cmid zurück.

Feeds & Fortschritt: moodle_get_announcements für die neuesten Beiträge im Nachrichtenforum; moodle_calendar_upcoming für Fristen; moodle_my_assignments für den Abgabestatus; moodle_my_grades für Kursendnoten (oder pro Element mit include_items + course_id).

Schreibwerkzeuge: moodle_send_message akzeptiert to_user_id (bevorzugt), to_username (exakte Übereinstimmung) oder to_query (unscharf, nur bei eindeutiger Übereinstimmung). Zweistufiger Ablauf – rufen Sie es einmal ohne confirm auf, um eine Vorschau zu erhalten, und rufen Sie es dann erneut mit confirm=true und der ausdrücklichen Zustimmung der Person auf, um tatsächlich zu senden. Hartes Limit: 4000 Zeichen.

Schreibgeschützte Werkzeuge können sicher automatisch aufgerufen werden; Schreibwerkzeuge erfordern ein ausdrückliches Argument „confirm“.';
$string['servername'] = 'Moodle MCP Server';
$string['setting_allow_token_in_query'] = 'Token im Query-String zulassen';
$string['setting_allow_token_in_query_desc'] = 'Wenn aktiviert, akzeptiert der MCP-Endpunkt das Token über den Query-Parameter <code>?wstoken=</code>. Standardmäßig deaktiviert, da Tokens in URLs in Webserver-Protokolle, den Browserverlauf und HTTP-Referer-Header gelangen. Clients sollten den <code>Authorization: Bearer</code>-Header verwenden.';
$string['setting_allow_token_in_query_help'] = 'Wenn aktiviert, akzeptiert der MCP-Endpunkt das Token über den Query-Parameter <code>?wstoken=</code>. Standardmäßig deaktiviert, da Tokens in URLs in Webserver-Protokolle, den Browserverlauf und HTTP-Referer-Header gelangen. Clients sollten den <code>Authorization: Bearer</code>-Header verwenden.';
$string['setting_allowed_origins'] = 'Zulässige CORS-Origins';
$string['setting_allowed_origins_desc'] = 'Ein Origin pro Zeile (z. B. <code>https://app.example.com</code>). Verwenden Sie <code>*</code>, um jeden Origin zuzulassen (nicht empfohlen). Wenn leer, werden nur Anfragen vom selben Origin akzeptiert. Der MCP-Server prüft den <code>Origin</code>-Header bei allen Anfragen und gibt für jeden Origin, der nicht auf dieser Liste steht, HTTP 403 zurück.';
$string['setting_allowed_origins_help'] = 'Ein Origin pro Zeile (z. B. <code>https://app.example.com</code>). Verwenden Sie <code>*</code>, um jeden Origin zuzulassen (nicht empfohlen). Wenn leer, werden nur Anfragen vom selben Origin akzeptiert. Der MCP-Server prüft den <code>Origin</code>-Header bei allen Anfragen und gibt für jeden Origin, der nicht auf dieser Liste steht, HTTP 403 zurück.';
$string['setting_emergency_disable'] = 'Notabschaltung';
$string['setting_emergency_disable_desc'] = 'Wenn aktiviert, gibt jede Anfrage an den MCP-Endpunkt HTTP 503 Service Unavailable zurück. Verwenden Sie dies als vorübergehenden Notausschalter während der Reaktion auf einen Vorfall.';
$string['setting_emergency_disable_help'] = 'Wenn aktiviert, gibt jede Anfrage an den MCP-Endpunkt HTTP 503 Service Unavailable zurück. Verwenden Sie dies als vorübergehenden Notausschalter während der Reaktion auf einen Vorfall.';
$string['setting_enforce_mcp_service'] = 'Endpunkt auf MCP-Dienste beschränken';
$string['setting_enforce_mcp_service_desc'] = 'Wenn aktiviert (Standard), akzeptiert der MCP-Endpunkt nur Tokens, die zu einem konfigurierten externen MCP-Dienst gehören. Dadurch wird die MCP-Dienstliste zur Zugriffsgrenze für den Endpunkt, nicht nur für die Token-Ausgabe. Deaktivieren Sie dies nur, wenn Sie Tokens vorlegen müssen, die für andere Webservices erstellt wurden.';
$string['setting_enforce_mcp_service_help'] = 'Wenn aktiviert (Standard), akzeptiert der MCP-Endpunkt nur Tokens, die zu einem konfigurierten externen MCP-Dienst gehören. Dadurch wird die MCP-Dienstliste zur Zugriffsgrenze für den Endpunkt, nicht nur für die Token-Ausgabe. Deaktivieren Sie dies nur, wenn Sie Tokens vorlegen müssen, die für andere Webservices erstellt wurden.';
$string['setting_expose_raw_functions'] = 'Rohe Moodle-Webservice-Funktionen bereitstellen';
$string['setting_expose_raw_functions_desc'] = 'Wenn aktiviert, wird jede dem authentifizierten Dienst zugewiesene externe Funktion zusätzlich zu den kuratierten KI-nativen Werkzeugen als MCP-Werkzeug bereitgestellt. Wenn Sie dies deaktivieren, wird die Oberfläche auf den KI-nativen Werkzeugsatz beschränkt, was für produktive KI-Agenten empfohlen wird.';
$string['setting_expose_raw_functions_help'] = 'Wenn aktiviert, wird jede dem authentifizierten Dienst zugewiesene externe Funktion zusätzlich zu den kuratierten KI-nativen Werkzeugen als MCP-Werkzeug bereitgestellt. Wenn Sie dies deaktivieren, wird die Oberfläche auf den KI-nativen Werkzeugsatz beschränkt, was für produktive KI-Agenten empfohlen wird.';
$string['setting_expose_raw_functions_warning'] = 'Sicherheitswarnung: Das Aktivieren roher Webservice-Funktionen erweitert die für KI-Clients bereitgestellte Werkzeugoberfläche erheblich. Verwenden Sie dies nur für kontrollierte Administrationstests, nicht als Standard für produktive Agenten.';
$string['setting_max_request_size'] = 'Maximale Größe des Anfragetexts (Bytes)';
$string['setting_max_request_size_desc'] = 'Anfragen mit einem Text, der größer als dieser Wert ist, werden mit HTTP 413 abgelehnt. Standard: 1 MiB.';
$string['setting_max_request_size_help'] = 'Anfragen mit einem Text, der größer als dieser Wert ist, werden mit HTTP 413 abgelehnt. Standard: 1 MiB.';
$string['setting_rate_limit_per_hour'] = 'Ratenbegrenzung pro Stunde (pro Token)';
$string['setting_rate_limit_per_hour_desc'] = 'Maximale Anzahl von Anfragen pro Stunde für ein einzelnes Token. Standard: 600.';
$string['setting_rate_limit_per_hour_help'] = 'Maximale Anzahl von Anfragen pro Stunde für ein einzelnes Token. Standard: 600.';
$string['setting_rate_limit_per_minute'] = 'Ratenbegrenzung pro Minute (pro Token)';
$string['setting_rate_limit_per_minute_desc'] = 'Maximale Anzahl von Anfragen pro Minute für ein einzelnes Token. Standard: 60.';
$string['setting_rate_limit_per_minute_help'] = 'Maximale Anzahl von Anfragen pro Minute für ein einzelnes Token. Standard: 60.';
$string['setting_services'] = 'Externe MCP-Dienste';
$string['setting_services_desc'] = 'Die externen Dienste, die MCP-Tokens ausgeben dürfen. Nur hier ausgewählte Dienste können in der Selbstbedienungs-Token-Oberfläche gewählt oder über die interne Token-API angesprochen werden. Erstellen Sie die Dienste zuerst unter <em>Website-Administration → Server → Webservices → Externe Dienste</em> und aktivieren Sie sie anschließend hier.';
$string['setting_services_help'] = 'Die externen Dienste, die MCP-Tokens ausgeben dürfen. Nur hier ausgewählte Dienste können in der Selbstbedienungs-Token-Oberfläche gewählt oder über die interne Token-API angesprochen werden. Erstellen Sie die Dienste zuerst unter <em>Website-Administration → Server → Webservices → Externe Dienste</em> und aktivieren Sie sie anschließend hier.';
$string['setting_token_retention_days'] = 'Aufbewahrung widerrufener Tokens (Tage)';
$string['setting_token_retention_days_desc'] = 'Wie viele Tage der Audit-Datensatz eines widerrufenen MCP-Tokens aufbewahrt wird, bevor die geplante Aufräumaufgabe ihn löscht. Vom Connector bereitgestellte Tokens werden regelmäßig neu erstellt, sodass sich ihre widerrufenen Datensätze ansammeln können. Setzen Sie den Wert auf 0, um jeden widerrufenen Token-Datensatz unbegrenzt aufzubewahren.';
$string['setting_token_retention_days_help'] = 'Wie viele Tage der Audit-Datensatz eines widerrufenen MCP-Tokens aufbewahrt wird, bevor die geplante Aufräumaufgabe ihn löscht. Vom Connector bereitgestellte Tokens werden regelmäßig neu erstellt, sodass sich ihre widerrufenen Datensätze ansammeln können. Setzen Sie den Wert auf 0, um jeden widerrufenen Token-Datensatz unbegrenzt aufzubewahren.';
$string['setting_tools_page_size'] = 'Standard-Seitengröße für tools/list';
$string['setting_tools_page_size_desc'] = 'Maximale Anzahl von Werkzeugen, die pro <code>tools/list</code>-Antwort zurückgegeben werden. Größere Mengen werden über das Feld <code>nextCursor</code> paginiert.';
$string['setting_tools_page_size_help'] = 'Maximale Anzahl von Werkzeugen, die pro <code>tools/list</code>-Antwort zurückgegeben werden. Größere Mengen werden über das Feld <code>nextCursor</code> paginiert.';
$string['shell_help_label'] = 'Hilfe zu Model Context Protocol';
$string['shell_settings_label'] = 'Einstellungen für Model Context Protocol';
$string['task_prune_revoked_tokens'] = 'Alte widerrufene MCP-Tokens bereinigen';
$string['token_actions'] = 'Aktionen';
$string['token_create'] = 'Token erstellen';
$string['token_created'] = 'Erstellt';
$string['token_created_once'] = 'Ihr neues Token wurde erstellt. Kopieren Sie es jetzt – aus Sicherheitsgründen wird es nicht erneut angezeigt.';
$string['token_label'] = 'Bezeichnung';
$string['token_label_help'] = 'Ein Name, der Ihnen hilft, dieses Token später wiederzuerkennen, zum Beispiel das Gerät oder die Anwendung, von der es verwendet wird.';
$string['token_lastused'] = 'Zuletzt verwendet';
$string['token_never'] = 'Nie';
$string['token_revoke'] = 'Widerrufen';
$string['token_revoke_confirm'] = 'Möchten Sie das Token "{$a}" wirklich widerrufen? Jede Anwendung, die es verwendet, verliert sofort den Zugriff. Dies kann nicht rückgängig gemacht werden.';
$string['token_revoked_notice'] = 'Das Token wurde widerrufen.';
$string['token_service'] = 'Dienst';
$string['token_service_help'] = 'Der MCP-Dienst, zu dem dieses Token Zugriff gewährt. Es werden nur Dienste aufgeführt, die Ihre Administration für MCP aktiviert hat und die Sie verwenden dürfen.';
$string['token_status'] = 'Status';
$string['token_status_active'] = 'Aktiv';
$string['token_status_expired'] = 'Abgelaufen';
$string['token_status_revoked'] = 'Widerrufen';
$string['token_validuntil'] = 'Läuft ab';
$string['token_validuntil_help'] = 'Ein optionales Datum, nach dem das Token nicht mehr funktioniert. Lassen Sie es deaktiviert für ein Token, das nie abläuft.';
$string['tokens_activate_service_button'] = 'MCP-Dienst aktivieren';
$string['tokens_activate_service_success'] = 'Der MCP-Dienst wurde aktiviert. Sie können nun Tokens erstellen.';
$string['tokens_existing_heading'] = 'Vorhandene Tokens';
$string['tokens_heading'] = 'MCP-Tokens';
$string['tokens_intro'] = 'Mit Tokens können MCP-Clients und KI-Agenten in Ihrem Namen auf Moodle zugreifen. Behandeln Sie jedes Token wie ein Passwort.';
$string['tokens_navlabel'] = 'MCP-Tokens';
$string['tokens_no_services_configured'] = 'Auf dieser Website wurde noch kein MCP-Dienst konfiguriert. Aktivieren Sie den Standard-MCP-Dienst, um die Token-Erstellung zu ermöglichen.';
$string['tokens_no_services_permitted'] = 'Auf dieser Website sind MCP-Dienste konfiguriert, Sie sind jedoch derzeit nicht berechtigt, einen davon zu verwenden. Das bedeutet in der Regel, dass der Dienst auf autorisierte Personen beschränkt ist; wenden Sie sich an Ihre Administration, um Zugriff zu erhalten.';
$string['tokens_none'] = 'Sie haben noch keine MCP-Tokens erstellt.';
