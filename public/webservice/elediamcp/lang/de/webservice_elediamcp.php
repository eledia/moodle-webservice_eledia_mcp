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

// Audit events.
$string['event_context_verified'] = 'MCP-Nutzerkontext verifiziert';
$string['event_context_verified_desc'] = 'Der Nutzer mit der ID \'{$a->userid}\' hat seinen MCP-Kontext verifiziert (Kursfilter: {$a->coursefilter}).';
$string['event_token_created'] = 'MCP-Token erstellt';
$string['event_token_created_desc'] = 'Der Nutzer mit der ID \'{$a->userid}\' hat den MCP-Token \'{$a->label}\' für den Nutzer mit der ID \'{$a->relateduserid}\' für den Dienst \'{$a->service}\' erstellt (über {$a->component}).';
$string['event_token_revoked'] = 'MCP-Token widerrufen';
$string['event_token_revoked_desc'] = 'Der Nutzer mit der ID \'{$a->userid}\' hat den MCP-Token \'{$a->label}\' des Nutzers mit der ID \'{$a->relateduserid}\' für den Dienst \'{$a->service}\' widerrufen.';
$string['event_tool_invoked'] = 'MCP-Tool aufgerufen';
$string['event_tool_invoked_desc'] = 'Der Nutzer mit der ID \'{$a->userid}\' hat das MCP-Tool \'{$a->toolname}\' aufgerufen (isError: {$a->iserror}, Dauer: {$a->durationms} ms).';
$string['event_write_performed'] = 'MCP-Schreibaktion ausgeführt';
$string['event_write_performed_desc'] = 'Der Nutzer mit der ID \'{$a->userid}\' hat über das MCP-Tool \'{$a->toolname}\' eine Schreibaktion ausgeführt.';

// Errors.
$string['error_expiry_in_past'] = 'Das Ablaufdatum muss in der Zukunft liegen.';
$string['error_invalid_component'] = 'Unbekannte Komponente \'{$a}\'. MCP-Tokens können nur im Namen einer installierten Moodle-Komponente bereitgestellt werden.';
$string['error_label_required'] = 'Eine Token-Bezeichnung ist erforderlich.';
$string['error_service_disabled'] = 'Der ausgewählte Webservice ist deaktiviert.';
$string['error_service_not_mcp'] = 'Der ausgewählte Webservice ist nicht als MCP-Dienst konfiguriert. Tokens können nur für konfigurierte MCP-Dienste erstellt werden.';
$string['error_token_not_found'] = 'Der angeforderte MCP-Token existiert nicht.';
$string['error_token_not_owned_by_component'] = 'Dieser MCP-Token wurde nicht von der aufrufenden Komponente bereitgestellt und kann nicht über die interne API widerrufen werden.';
$string['err_emergency_disabled'] = 'Der MCP-Webservice wurde vom Administrator der Website vorübergehend deaktiviert.';
$string['err_empty_request'] = 'Der Anfragetext ist leer';
$string['err_forbidden_origin'] = 'Origin durch die Website-Richtlinie nicht erlaubt';
$string['err_invalid_json'] = 'Ungültiges JSON';
$string['err_invalid_jsonrpc'] = 'Ungültige JSON-RPC-Version';
$string['err_invalid_protocol_version'] = 'Nicht unterstützte MCP-Protokollversion';
$string['err_missing_method'] = 'Methode fehlt';
$string['err_missing_tool_name'] = 'Tool-Name fehlt';
$string['err_rate_limit_exceeded'] = 'Ratenbegrenzung überschritten. Erneut versuchen in {$a} Sekunden.';
$string['err_request_too_large'] = 'Der Anfragetext überschreitet die maximal zulässige Größe';
$string['err_token_in_query_disabled'] = 'Token im Query-String ist durch die Website-Richtlinie deaktiviert. Verwenden Sie stattdessen den Authorization-Header.';

// Capabilities.
$string['elediamcp:managetokens'] = 'Eigene MCP-Tokens erstellen und widerrufen';
$string['elediamcp:use'] = 'MCP-Webservice verwenden';
$string['elediamcp:viewcaps'] = 'Eigenen Berechtigungsbaum über MCP ansehen';

// Generic.
$string['disabled'] = 'deaktiviert';

// Plugin metadata.
$string['pluginname'] = 'Model Context Protocol';
$string['privacy:metadata:webservice_elediamcp_token'] = 'Metadaten zu MCP-Webservice-Tokens, die an einen Nutzer oder in seinem Namen ausgegeben wurden. Das Token-Geheimnis selbst wird hier niemals gespeichert.';
$string['privacy:metadata:webservice_elediamcp_token:component'] = 'Die Erstanbieter-Komponente, die den Token bereitgestellt hat, sofern vorhanden.';
$string['privacy:metadata:webservice_elediamcp_token:creatorid'] = 'Der Nutzer, der den Token erstellt hat.';
$string['privacy:metadata:webservice_elediamcp_token:externalserviceid'] = 'Der externe Dienst, auf den der Token beschränkt ist.';
$string['privacy:metadata:webservice_elediamcp_token:lastaccess'] = 'Der Zeitpunkt der letzten Verwendung des Tokens.';
$string['privacy:metadata:webservice_elediamcp_token:name'] = 'Die Token-Bezeichnung.';
$string['privacy:metadata:webservice_elediamcp_token:revoked'] = 'Ob der Token widerrufen wurde.';
$string['privacy:metadata:webservice_elediamcp_token:revokedby'] = 'Der Nutzer, der den Token widerrufen hat.';
$string['privacy:metadata:webservice_elediamcp_token:timecreated'] = 'Der Zeitpunkt, zu dem der Token erstellt wurde.';
$string['privacy:metadata:webservice_elediamcp_token:userid'] = 'Der Nutzer, als der sich der Token authentifiziert.';
$string['privacy:metadata:webservice_elediamcp_token:validuntil'] = 'Die Ablaufzeit des Tokens.';

// Token management UI.
$string['claude_config_file'] = 'Speicherort der Konfigurationsdatei';
$string['claude_config_file_help'] = 'Unter macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>. Unter Windows: <code>%APPDATA%\\Claude\\claude_desktop_config.json</code>. Erstellen Sie die Datei, falls sie nicht existiert.';
$string['claude_connect_heading'] = 'Einen MCP-Client verbinden (Claude Desktop)';
$string['claude_connect_intro'] = 'Claude Desktop und andere reine stdio-MCP-Clients verbinden sich über die <a href="https://www.npmjs.com/package/mcp-remote" target="_blank" rel="noopener">mcp-remote</a>-Brücke mit diesem entfernten Server, die <a href="https://nodejs.org/" target="_blank" rel="noopener">Node.js</a> (für <code>npx</code>) auf dem Client-Rechner erfordert. Fügen Sie den untenstehenden Ausschnitt zu Ihrer <code>claude_desktop_config.json</code> hinzu, ersetzen Sie den Token und starten Sie Claude Desktop anschließend vollständig neu.';
$string['claude_connect_serverurl'] = 'MCP-Server-URL';
$string['claude_connect_snippet'] = 'Claude-Desktop-Konfiguration';
$string['claude_connect_snippet_withtoken'] = 'Sofort einsatzbereite Claude-Desktop-Konfiguration (Ihr neuer Token ist bereits eingetragen – kopieren Sie ihn jetzt)';
$string['claude_connect_tokenhint'] = 'Ersetzen Sie <code>{$a}</code> durch einen oben erstellten Token.';
$string['token_actions'] = 'Aktionen';
$string['token_create'] = 'Token erstellen';
$string['token_created'] = 'Erstellt';
$string['token_created_once'] = 'Ihr neuer Token wurde erstellt. Kopieren Sie ihn jetzt – aus Sicherheitsgründen wird er nicht erneut angezeigt.';
$string['token_label'] = 'Bezeichnung';
$string['token_label_help'] = 'Ein Name, der Ihnen hilft, diesen Token später wiederzuerkennen, z. B. das Gerät oder die Anwendung, von der er verwendet wird.';
$string['token_lastused'] = 'Zuletzt verwendet';
$string['token_never'] = 'Nie';
$string['token_revoke'] = 'Widerrufen';
$string['token_revoke_confirm'] = 'Möchten Sie den Token „{$a}“ wirklich widerrufen? Jede Anwendung, die ihn verwendet, verliert sofort den Zugriff. Dies kann nicht rückgängig gemacht werden.';
$string['token_revoked_notice'] = 'Der Token wurde widerrufen.';
$string['token_service'] = 'Dienst';
$string['token_service_help'] = 'Der MCP-Dienst, zu dem dieser Token Zugriff gewährt. Es werden nur Dienste aufgeführt, die Ihr Administrator für MCP aktiviert hat und die Sie verwenden dürfen.';
$string['token_status'] = 'Status';
$string['token_status_active'] = 'Aktiv';
$string['token_status_expired'] = 'Abgelaufen';
$string['token_status_revoked'] = 'Widerrufen';
$string['token_validuntil'] = 'Läuft ab';
$string['token_validuntil_help'] = 'Ein optionales Datum, nach dem der Token nicht mehr funktioniert. Deaktiviert lassen für einen Token, der nie abläuft.';
$string['tokens_heading'] = 'MCP-Tokens';
$string['tokens_existing_heading'] = 'Vorhandene Tokens';
$string['tokens_intro'] = 'Mit Tokens können MCP-Clients und KI-Agenten in Ihrem Namen auf Moodle zugreifen. Behandeln Sie jeden Token wie ein Passwort.';
$string['tokens_navlabel'] = 'MCP-Tokens';
$string['tokens_no_services_configured'] = 'Auf dieser Website wurden noch keine MCP-Dienste konfiguriert. Ein Administrator muss unter Website-Administration → Plugins → Webservices → Model Context Protocol → „MCP-externe Dienste“ einen oder mehrere externe Dienste auswählen, bevor Tokens erstellt werden können.';
$string['tokens_no_services_permitted'] = 'Auf dieser Website sind MCP-Dienste konfiguriert, aber Sie sind derzeit nicht berechtigt, einen davon zu verwenden. Das bedeutet in der Regel, dass der Dienst auf autorisierte Nutzer beschränkt ist; wenden Sie sich an Ihren Administrator, um Zugriff zu erhalten.';
$string['tokens_none'] = 'Sie haben noch keine MCP-Tokens erstellt.';

// Settings.
$string['configuration_error_invalid_origin'] = 'Jede CORS-Origin muss eine absolute http(s)-Origin sein, zum Beispiel https://app.example.com.';
$string['configuration_error_nonnegative'] = 'Geben Sie einen Wert ab 0 ein.';
$string['configuration_error_wildcard_origin'] = 'Die Wildcard-Origin (*) ist hier nicht erlaubt. Tragen Sie stattdessen explizite vertrauenswürdige Origins ein.';
$string['configuration_heading'] = 'MCP-Konfiguration';
$string['configuration_hint'] = 'Konfigurieren Sie externe Dienste, Token-Richtlinie, Sicherheitslimits und den MCP-Tool-Katalog.';
$string['configuration_saved'] = 'MCP-Konfiguration gespeichert.';
$string['configuration_security_heading'] = 'Sicherheit und Limits';
$string['configuration_services_heading'] = 'Dienste und Token';
$string['configuration_shell_link'] = 'MCP Plugin Shell öffnen';
$string['configuration_shell_link_desc'] = 'Öffnet die plugin-eigene MCP-Konfigurationsseite.';
$string['configuration_tag_mcp'] = 'MCP';
$string['configuration_tag_security'] = 'Sicherheit';
$string['configuration_tagline'] = 'Konfiguration';
$string['configuration_tools_heading'] = 'Tool-Katalog';
$string['setting_allow_token_in_query'] = 'Token im Query-String erlauben';
$string['setting_allow_token_in_query_desc'] = 'Wenn aktiviert, akzeptiert der MCP-Endpunkt den Token über den Query-Parameter <code>?wstoken=</code>. Standardmäßig deaktiviert, da Tokens in URLs in Webserver-Protokolle, den Browserverlauf und HTTP-Referer-Header gelangen. Clients sollten den <code>Authorization: Bearer</code>-Header verwenden.';
$string['setting_allow_token_in_query_help'] = $string['setting_allow_token_in_query_desc'];
$string['setting_allowed_origins'] = 'Erlaubte CORS-Origins';
$string['setting_allowed_origins_desc'] = 'Eine Origin pro Zeile (z. B. <code>https://app.example.com</code>). Verwenden Sie <code>*</code>, um jede Origin zu erlauben (nicht empfohlen). Wenn leer, werden nur Anfragen derselben Origin akzeptiert. Der MCP-Server validiert den <code>Origin</code>-Header bei allen Anfragen und gibt für jede nicht aufgeführte Origin HTTP 403 zurück.';
$string['setting_allowed_origins_help'] = $string['setting_allowed_origins_desc'];
$string['setting_emergency_disable'] = 'Notabschaltung';
$string['setting_emergency_disable_desc'] = 'Wenn aktiviert, gibt jede Anfrage an den MCP-Endpunkt HTTP 503 Service Unavailable zurück. Verwenden Sie dies als vorübergehenden Notausschalter bei der Reaktion auf Vorfälle.';
$string['setting_emergency_disable_help'] = $string['setting_emergency_disable_desc'];
$string['setting_expose_raw_functions'] = 'Rohe Moodle-Webservice-Funktionen verfügbar machen';
$string['setting_expose_raw_functions_desc'] = 'Wenn aktiviert, wird jede dem authentifizierten Dienst zugewiesene externe Funktion zusätzlich zu den kuratierten KI-nativen Tools als MCP-Tool verfügbar gemacht. Das Deaktivieren beschränkt die Oberfläche auf den KI-nativen Tool-Satz, was für produktive KI-Agenten empfohlen wird.';
$string['setting_expose_raw_functions_help'] = $string['setting_expose_raw_functions_desc'];
$string['setting_expose_raw_functions_warning'] = 'Sicherheitswarnung: Rohe Webservice-Funktionen vergrößern die Tool-Oberfläche für KI-Clients erheblich. Verwenden Sie dies nur für kontrollierte Administrator-Tests, nicht als Standard für produktive Agenten.';
$string['setting_max_request_size'] = 'Maximale Größe des Anfragetexts (Bytes)';
$string['setting_max_request_size_desc'] = 'Anfragen mit einem Text, der größer als dieser Wert ist, werden mit HTTP 413 abgelehnt. Standard 1 MiB.';
$string['setting_max_request_size_help'] = $string['setting_max_request_size_desc'];
$string['setting_rate_limit_per_hour'] = 'Ratenbegrenzung pro Stunde (pro Token)';
$string['setting_rate_limit_per_hour_desc'] = 'Maximale Anzahl von Anfragen pro Stunde für einen einzelnen Token. Standard 600.';
$string['setting_rate_limit_per_hour_help'] = $string['setting_rate_limit_per_hour_desc'];
$string['setting_rate_limit_per_minute'] = 'Ratenbegrenzung pro Minute (pro Token)';
$string['setting_rate_limit_per_minute_desc'] = 'Maximale Anzahl von Anfragen pro Minute für einen einzelnen Token. Standard 60.';
$string['setting_rate_limit_per_minute_help'] = $string['setting_rate_limit_per_minute_desc'];
$string['setting_token_retention_days'] = 'Aufbewahrung widerrufener Tokens (Tage)';
$string['setting_token_retention_days_desc'] = 'Wie viele Tage der Auditeintrag eines widerrufenen MCP-Tokens aufbewahrt wird, bevor die geplante Bereinigungsaufgabe ihn löscht. Vom Connector bereitgestellte Tokens werden regelmäßig neu erzeugt, sodass sich ihre widerrufenen Einträge ansammeln können. Auf 0 setzen, um alle Einträge widerrufener Tokens unbegrenzt aufzubewahren.';
$string['setting_token_retention_days_help'] = $string['setting_token_retention_days_desc'];
$string['setting_services'] = 'MCP-externe Dienste';
$string['setting_services_desc'] = 'Die externen Dienste, die MCP-Tokens ausstellen dürfen. Nur hier ausgewählte Dienste können in der Self-Service-Token-Oberfläche gewählt oder über die interne Token-API angesprochen werden. Erstellen Sie die Dienste zuerst unter <em>Website-Administration → Server → Webservices → Externe Dienste</em> und aktivieren Sie sie dann hier.';
$string['setting_services_help'] = $string['setting_services_desc'];
$string['setting_tools_page_size'] = 'Standard-Seitengröße für tools/list';
$string['setting_tools_page_size_desc'] = 'Maximale Anzahl von Tools, die pro <code>tools/list</code>-Antwort zurückgegeben werden. Größere Sätze werden über das Feld <code>nextCursor</code> paginiert.';
$string['setting_tools_page_size_help'] = $string['setting_tools_page_size_desc'];

// Server metadata.
$string['server_instructions'] = 'Moodle-MCP-Server mit kuratierten KI-nativen Tools.

Identität & Kontext: Rufen Sie zuerst moodle_me auf, um die Identität zu bestätigen, dann moodle_verify_user_context für die vollständige Abfrage von Rollen/Gruppen/Berechtigungen.

Personensuche: moodle_find_user löst ein freies Namensfragment (z. B. „erika“) zu einer Liste von Moodle-Nutzern auf, denen Nachrichten gesendet werden können, mit konkreten IDs. Verwenden Sie es VOR moodle_send_message, wann immer Sie die genaue Nutzer-ID des Empfängers nicht bereits kennen.

Kurssuche: moodle_my_courses listet eingeschriebene Kurse auf; moodle_search_courses durchsucht den öffentlichen Katalog; moodle_course_contents zählt Abschnitte/Aktivitäten eines Kurses auf; moodle_get_resource gibt den Inhalt einer Seite/eines Buchkapitels/Textfelds/einer URL/Datei anhand der cmid zurück.

Feeds & Fortschritt: moodle_get_announcements für die neuesten Beiträge im Nachrichtenforum; moodle_calendar_upcoming für Fristen; moodle_my_assignments für den Abgabestatus; moodle_my_grades für Kursendnoten (oder pro Element mit include_items + course_id).

Schreib-Tools: moodle_send_message akzeptiert to_user_id (bevorzugt), to_username (exakte Übereinstimmung) oder to_query (unscharf, nur bei eindeutiger Übereinstimmung). Zweistufiger Ablauf – einmal ohne confirm aufrufen, um eine Vorschau zu erhalten, dann erneut mit confirm=true und der ausdrücklichen Zustimmung des Nutzers, um tatsächlich zu senden. Hartes Limit 4000 Zeichen.

Schreibgeschützte Tools können automatisch aufgerufen werden; Schreib-Tools erfordern ein ausdrückliches „confirm“-Argument.';
$string['servername'] = 'Moodle-MCP-Server';

// Scheduled tasks.
$string['task_prune_revoked_tokens'] = 'Alte widerrufene MCP-Tokens bereinigen';
