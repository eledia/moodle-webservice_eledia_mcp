<p align="center">
  <a href="https://eledia.de" title="eLeDia GmbH — eLearning im Dialog">
    <img src="https://eledia.de/wp-content/uploads/2025/01/cropped-eLeDia_Logo-300x112.png"
         alt="eLeDia GmbH — eLearning im Dialog"
         width="220" height="82">
  </a>
</p>

<h1 align="center">Moodle MCP Web Service</h1>

<p align="center">
  <strong>Moodle-Plugin · <code>webservice_elediamcp</code></strong>
  <br>
  <em>Eine KI-native Integrationsschicht für Moodle: das Model Context Protocol (MCP)<br>
  als kuratierte, LLM-freundliche Werkzeugoberfläche auf Moodles Webservices —<br>
  jeder Aufruf respektiert das bestehende Moodle-Berechtigungsmodell.</em>
</p>

<p align="center">
  <a href="https://moodle.org"><img alt="Moodle 4.2+ / 5.x" src="https://img.shields.io/badge/Moodle-4.2%2B%20%E2%80%A2%205.x-003366?logo=moodle&logoColor=white"></a>
  <a href="https://www.php.net"><img alt="PHP 8.1+" src="https://img.shields.io/badge/PHP-8.1%2B-0066b3?logo=php&logoColor=white"></a>
  <img alt="Maturity: Stable" src="https://img.shields.io/badge/Maturity-Stable%20%C2%B7%20v1.1.0-00834a">
  <img alt="MCP 2025-11-25" src="https://img.shields.io/badge/MCP-2025--11--25%20%E2%80%A2%202025--03--26-6b7280">
  <img alt="Privacy: GDPR ready" src="https://img.shields.io/badge/Privacy-GDPR%20provider-6b7280">
  <a href="LICENSE"><img alt="GPL v3+" src="https://img.shields.io/badge/License-GPL%20v3%2B-0066b3"></a>
</p>

<p align="center">
  <a href="#-schnellstart">Schnellstart</a> ·
  <a href="#-highlights">Highlights</a> ·
  <a href="#-ki-native-werkzeuge">KI-Werkzeuge</a> ·
  <a href="#-sicherheitsmodell">Sicherheit</a> ·
  <a href="#-token-verwaltung">Token-Verwaltung</a> ·
  <a href="#-tests">Entwicklung</a> ·
  <a href="https://eledia.de"><strong>eledia.de</strong></a>
</p>

<p align="center">
  <a href="README.md">English documentation</a>
</p>

---

## ✨ Auf einen Blick

Dieses Plugin implementiert das **Model Context Protocol (MCP)** als Moodle-Webservice-
Protokoll. Es verbindet Moodles externe Funktions-API mit MCP, sodass KI-Tutoren,
Agenten, RAG-Systeme und MCP-kompatible Clients — Claude Desktop, Cursor, Windsurf,
Langflow, der eLeDia.ai Tutor Agent — über eine einzige, standardisierte
Schnittstelle mit Moodle kommunizieren können.

Statt Moodles vollständige rohe Webservice-Oberfläche freizugeben, bietet es einen
**kuratierten Satz stabiler, denormalisierter, LLM-freundlicher Werkzeuge**
(`moodle_me`, `moodle_my_courses`, `moodle_forum_discussions`, …). Jedes Werkzeug
läuft **im Kontext des authentifizierten Nutzers** und erzwingt dieselben Capability-,
Einschreibungs- und Sichtbarkeitsprüfungen wie die entsprechende Moodle-Oberfläche —
es kann niemals Daten erreichen, die der Nutzer nicht ohnehin sehen könnte.

Die kostenlose Version liefert einen grundlegenden Werkzeugsatz; der vollständige
kuratierte Katalog wird durch das optionale Add-on **eLeDia.ai Tutor Premium**
(`local_elediaai_tutor_premium`, Funktion `mcp_tools`) freigeschaltet.

> Entwickelt und gepflegt von [eLeDia GmbH](https://eledia.de), Berlin.

---

## 🚀 Schnellstart

```bash
# 1. Plugin in die Moodle-Installation kopieren (Moodle 5.x mit public/-Root):
git clone https://gitlab.eledia.de/eledia_plugins/webservice/moodle-webservice_elediamcp.git \
  path/to/moodle/public/webservice/elediamcp

# 2. Installation auslösen:
php path/to/moodle/admin/cli/upgrade.php --non-interactive
```

Anschließend als Website-Administration:

1. **Webservices aktivieren** unter *Website-Administration → Erweiterte Funktionen*.
2. **Das MCP-Protokoll aktivieren** unter *Plugins → Webservices → Protokolle verwalten*.
3. **Die MCP-Konfiguration öffnen** unter *Plugins → Webservices → Model Context Protocol*
   und die **externen Dienste** auswählen, die MCP-Tokens ausgeben dürfen.
4. **Ein Token erstellen** — Administration über die Konfigurationsseite, Nutzer über
   *Einstellungen → MCP-Tokens* (erfordert `webservice/elediamcp:managetokens`).
5. **Einen Client verbinden** mit dem Endpunkt über einen `Authorization: Bearer`-Header
   (siehe [Verwendung](#-verwendung)).

> Die Token-Seite rendert einen einsatzbereiten Claude-Desktop-Ausschnitt, in dem Ihr
> Token bei der Erstellung bereits eingetragen ist.

---

## 🧩 Highlights

- **KI-native Werkzeugschicht** — 24 stabile, kuratierte Werkzeuge auf Basis der rohen
  Moodle-Webservices, ausgelegt auf Stabilität über kleinere Moodle-Versionssprünge
  hinweg. Eine Basis aus 15 Werkzeugen ist in der kostenlosen Version enthalten; der
  vollständige Katalog wird durch das optionale Add-on eLeDia.ai Tutor Premium
  freigeschaltet.
- **Token-Verwaltung** — Selbstbedienungs-Oberfläche, mit der Nutzer ihre eigenen
  MCP-Tokens erstellen, deren Metadaten einsehen und sie widerrufen können, sowie eine
  interne PHP-API, über die vertrauenswürdige Erstanbieter-Plugins nutzergebundene
  Tokens bereitstellen und widerrufen. Siehe [Token-Verwaltung](#-token-verwaltung).
- **MCP-Mehrversionsunterstützung** — verhandelt `2025-11-25`, `2025-06-18` und
  `2025-03-26` (Legacy) gemäß der aktuellen MCP-Spezifikation.
- **Streamable-HTTP-Transport** mit vollständigem Lebenszyklus (`initialize`,
  `notifications/initialized`, `ping`, `tools/list`, `tools/call`).
- **Kanonische strukturierte Ausgabe** — entspricht der Form ab MCP 2025-06-18; die
  Legacy-Hülle `{ "result": … }` wird für ältere Clients weiterhin ausgegeben.
- **Werkzeug-Annotationen** — `readOnlyHint`, `destructiveHint`, `idempotentHint`,
  `openWorldHint` und `title` werden für jedes Werkzeug angegeben.
- **Werkzeug-Ausführungsfehler als `isError: true`** — fachliche Fehler werden als
  MCP-Werkzeugergebnisse ausgegeben, damit LLMs sich selbst korrigieren können, statt
  als JSON-RPC-Fehler, die das Gespräch abbrechen.
- **`tools/list`-Paginierung** mit opaken Cursors.
- **Sicherheitshärtung** — Origin-Prüfung, CORS-Allowlist, optionales Token im
  Query-String (standardmäßig aus), Ratenbegrenzung, Begrenzung der Anfragegröße,
  Notabschaltung.
- **OAuth-Protected-Resource-Discovery** (`/.well-known/oauth-protected-resource`)
  und `WWW-Authenticate` bei 401 für ein konfigurationsfreies Client-Onboarding.
- **Umfassende Audit-Protokollierung** über Moodle-Events (`tool_invoked`,
  `write_performed`, `context_verified`, `token_created`, `token_revoked`).
- **Dienst-spezifische Erkennung roher Funktionen** — wenn rohe Funktionen
  bereitgestellt werden, werden nur die dem authentifizierten externen Dienst
  zugewiesenen Funktionen angeboten.

---

## 🤔 Was ist MCP?

Das **Model Context Protocol (MCP)** ist ein offenes Protokoll, das standardisiert,
wie Anwendungen KI-Assistenten und großen Sprachmodellen Kontext und Werkzeuge
bereitstellen. Dieses Plugin verbindet Moodles Webservice-API mit MCP und ermöglicht
KI-Agenten, über eine standardisierte Schnittstelle mit Moodle zu interagieren, wobei
jede bestehende Moodle-Capability-Prüfung respektiert wird.

---

## 🗂 Voraussetzungen & Kompatibilität

| Komponente | Version |
|-----------|---------|
| Moodle    | **4.2 (Build 2023041800) und neuer**, einschließlich 5.x |
| PHP       | **8.1+** |
| Webservices | Müssen in Moodle aktiviert sein |

---

## 📥 Installation

1. Den Plugin-Ordner so in die Moodle-Installation legen, dass er sich zu
   `webservice/elediamcp` auflöst (bei einem Moodle-5.x-`public/`-Root also
   `public/webservice/elediamcp`).
2. **Website-Administration → Mitteilungen** öffnen, um die Installation
   abzuschließen.
3. Das Plugin wird als `webservice_elediamcp` installiert.

---

## ⚙️ Konfiguration

### 1. Webservices aktivieren

**Website-Administration → Erweiterte Funktionen**: **Webservices aktivieren**
einschalten.

### 2. Das MCP-Protokoll aktivieren

**Website-Administration → Plugins → Webservices → Protokolle verwalten**:
**Model Context Protocol** aktivieren.

### 3. MCP-spezifische Einstellungen konfigurieren

**Website-Administration → Plugins → Webservices → Model Context Protocol** bietet:

| Einstellung | Standard | Zweck |
|---|---|---|
| **Externe MCP-Dienste** | _(keine)_ | Welche externen Dienste MCP-Tokens ausgeben dürfen. Nur diese erscheinen in der Selbstbedienungs-Oberfläche und der internen API. |
| **Endpunkt auf MCP-Dienste beschränken** | Ein | Wenn aktiviert, akzeptiert der Endpunkt nur Tokens, die zu einem konfigurierten externen MCP-Dienst gehören — die MCP-Dienstliste wird damit zur Zugriffsgrenze, nicht nur zur Ausgabegrenze. Nur in Übergangsszenarien deaktivieren, in denen Tokens vorgelegt werden müssen, die für andere Webservices erstellt wurden. |
| **Zulässige CORS-Origins** | _(leer)_ | Ein Origin pro Zeile. Leer = nur derselbe Origin. Platzhalter werden vom Formular abgelehnt. |
| **Token im Query-String zulassen** | Aus | Wenn aus (empfohlen), wird nur `Authorization: Bearer` akzeptiert. Wenn ein, wird zusätzlich `?wstoken=` akzeptiert und ein `Deprecation`-Header ausgegeben. |
| **Rohe Moodle-Webservice-Funktionen bereitstellen** | Aus | Wenn aus, werden nur die kuratierten KI-nativen Werkzeuge angeboten. Für produktive KI-Agenten empfohlen. Rohe Funktionen werden nur angeboten, wenn dies ein **und** das Premium-Add-on aktiv ist. |
| **Ratenbegrenzung pro Minute / pro Stunde** | 60 / 600 | Pro Token (oder pro IP, wenn nicht authentifiziert). |
| **Maximale Größe des Anfragetexts** | 1 MiB | Größere Anfragen werden mit HTTP 413 abgelehnt. |
| **Standard-Seitengröße für tools/list** | 50 | Größere Kataloge werden über `nextCursor` paginiert. |
| **Aufbewahrung widerrufener Tokens (Tage)** | 30 | Wie lange Metadaten widerrufener Tokens aufbewahrt werden, bevor die Aufräumaufgabe sie löscht. |
| **Notabschaltung** | Aus | Gibt für jede Anfrage HTTP 503 zurück. Notausschalter für die Reaktion auf Vorfälle. |

### 4. Einen externen Dienst erstellen

**Website-Administration → Server → Webservices → Externe Dienste**. Einen
MCP-Dienst hinzufügen (z. B. *Name*: `MCP Service`, *Kurzname*: `mcp_service`,
*Aktiviert*: Ja, *Nur berechtigte Nutzer/innen*: Ja — empfohlen), anschließend die
rohen externen Funktionen hinzufügen, die Sie bereitstellen möchten. Die KI-nativen
Werkzeuge sind unabhängig von der Funktionsliste des Dienstes immer verfügbar. Wählen
Sie den Dienst abschließend unter **Externe MCP-Dienste** (Schritt 3) aus, damit er
MCP-Tokens ausgeben kann.

### 5. Ein Token erstellen

Die Administration verwendet die **MCP-Konfigurationsseite**; Nutzer mit
`webservice/elediamcp:managetokens` verwenden **Einstellungen → MCP-Tokens**. Der
vollständige Token-Wert wird **genau einmal** unmittelbar nach der Erstellung
angezeigt.

### 6. Die Capability zuweisen

Stellen Sie sicher, dass Nutzer über `webservice/elediamcp:use` verfügen
(standardmäßig dem Archetyp `user` gewährt), um auf den MCP-Endpunkt zuzugreifen. Die
Website-Administration verfügt zusätzlich über `webservice/elediamcp:viewcaps`, das
für die Verwendung des Arguments `include_capabilities` von
`moodle_verify_user_context` erforderlich ist.

---

## 🔌 Verwendung

### Endpunkt

```
https://your-moodle-site.com/webservice/elediamcp/server.php
```

Die Authentifizierung erfolgt über einen HTTP-**Authorization**-Header:

```
Authorization: Bearer YOUR_TOKEN
```

Der Query-String-Fallback (`?wstoken=YOUR_TOKEN`) ist **standardmäßig aus**.
Aktivieren Sie ihn in den Plugin-Einstellungen, falls Sie ihn für die Entwicklung
benötigen.

### Einen MCP-Client verbinden

| Einstellung | Wert |
|---|---|
| Transport | HTTP / Streamable HTTP |
| URL | `https://your-moodle-site.com/webservice/elediamcp/server.php` |
| Header | `{"Authorization": "Bearer YOUR_TOKEN"}` |
| Verhandeltes Protokoll | `2025-11-25` für neue Clients, automatischer Fallback auf `2025-03-26` für ältere |

#### Claude Desktop (und andere reine stdio-Clients) über `mcp-remote`

Claude Desktop spricht MCP über stdio und erreicht diesen entfernten HTTP-Server daher
über die [`mcp-remote`](https://www.npmjs.com/package/mcp-remote)-Brücke, die
[Node.js](https://nodejs.org/) (für `npx`) auf dem Client-Rechner benötigt. Fügen Sie
das Folgende zu `claude_desktop_config.json` hinzu
(macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`,
Windows: `%APPDATA%\Claude\claude_desktop_config.json`) und starten Sie Claude Desktop
anschließend vollständig neu:

```json
{
  "mcpServers": {
    "moodle": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://your-moodle-site.com/webservice/elediamcp/server.php",
        "--header",
        "Authorization:${AUTH_HEADER}"
      ],
      "env": {
        "AUTH_HEADER": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

> Der Header-Wert wird über die Umgebungsvariable `AUTH_HEADER` übergeben, und das
> Argument `--header` enthält keinen Leerraum nach dem Doppelpunkt. Dies ist der
> dokumentierte Workaround dafür, dass Claude Desktop Leerzeichen aus
> Befehlsargumenten entfernt.

Die Selbstbedienungs-Token-Seite (**Einstellungen → MCP-Tokens**) rendert genau diesen
Ausschnitt, in dem Ihr Token bei der Erstellung bereits eingetragen ist — kopieren Sie
ihn direkt in die Konfigurationsdatei.

Discovery für OAuth-fähige Clients:

```
GET https://your-moodle-site.com/webservice/elediamcp/.well-known/oauth-protected-resource
```

Wenn eine Anfrage nicht authentifiziert ist, antwortet der Server mit:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="Moodle MCP", resource_metadata="https://.../webservice/elediamcp/.well-known/oauth-protected-resource"
```

---

## 🧰 KI-native Werkzeuge

Diese Werkzeuge sind stabil, günstig im Aufruf und für KI-Agenten empfohlen. Jedes
Werkzeug läuft **im Kontext des authentifizierten Nutzers** und erzwingt dieselben
Capability-, Einschreibungs- und Sichtbarkeitsprüfungen wie die entsprechende
Moodle-Oberfläche — sie können nicht verwendet werden, um Daten zu erreichen, die der
Nutzer nicht ohnehin sehen könnte. Die fünf Schreibwerkzeuge erfordern eine
ausdrückliche zweistufige Bestätigung; alles andere ist schreibgeschützt.

Die Spalte **Edition** kennzeichnet die 15 Werkzeuge der kostenlosen Version (Free)
und die 9 Werkzeuge, die durch das optionale Add-on eLeDia.ai Tutor Premium
freigeschaltet werden (Premium). Die MCP-Konfigurationsseite zeigt die aktive Edition
an und listet die zusätzlichen Premium-Werkzeuge auf.

| Werkzeug | Typ | Edition | Zweck |
|---|---|---|---|
| `moodle_me` | lesen | Free | Identitätsprüfung: Wer ist der authentifizierte Nutzer, auf welcher Website, in welcher Sprache. Günstig in jeder Runde aufzurufen. |
| `moodle_verify_user_context` | lesen | Free | Kompakter Bootstrap: zählt aktive Einschreibungen mit Rollen und Gruppen auf, optional den Capability-Satz des Nutzers (abgesichert durch `webservice/elediamcp:viewcaps`). |
| `moodle_find_user` | lesen | Premium | Löst ein freies Namensfragment in anschreibbare Nutzer auf (respektiert Mitteilungs-Datenschutzregeln). |
| `moodle_my_courses` | lesen | Free | Listet die eingeschriebenen Kurse des Nutzers mit Fortschrittsklassifizierung und Suche auf. |
| `moodle_search_courses` | lesen | Free | Durchsucht den sichtbaren Kurskatalog oder durchstöbert/listet alle Kurse im Bereich, wenn keine Suchanfrage angegeben ist (respektiert Kurs-/Bereichssichtbarkeit). |
| `moodle_course_contents` | lesen | Free | Listet Abschnitte und sichtbare Aktivitäten eines Kurses auf, auf den der Nutzer zugreifen darf. |
| `moodle_get_resource` | lesen | Free | Gibt den lesbaren Inhalt einer Seite/eines Buchkapitels/einer Textseite/einer URL/einer Datei anhand der `cmid` zurück. |
| `moodle_search_content` | lesen | Free | Volltextsuche über zugängliche Inhalte via globaler Suche (sanfter Fallback auf Aktivitätsnamen/-beschreibungen, wenn deaktiviert). |
| `moodle_get_announcements` | lesen | Free | Aktuelle Beiträge aus Nachrichtenforen der eingeschriebenen Kurse des Nutzers. |
| `moodle_forum_discussions` | lesen | Premium | Kursforen-Diskussionen und -Beiträge (erzwingt Gruppen, Q&A-Gating, zeitgesteuerte Beiträge und private Antworten über die Forum-API). |
| `moodle_calendar_upcoming` | lesen | Free | Anstehende Fristen und Ereignisse, beschränkt auf die Kurse/Gruppen des Nutzers. |
| `moodle_due_work` | lesen | Free | Priorisierte Aufgabenliste der lernenden Person: überfällige Aufgaben, anstehende Aufgaben-Abgabetermine und anstehende Kalendereinträge. |
| `moodle_my_assignments` | lesen | Free | Abgabe- und Bewertungsstatus von Aufgaben über die eingeschriebenen Kurse hinweg. |
| `moodle_my_grades` | lesen | Free | Kursendnoten oder Aufschlüsselung pro Element (respektiert verborgene Bewertungselemente). |
| `moodle_my_progress` | lesen | Free | Abschlussfortschritt pro eingeschriebenem Kurs, optional Aktivitätsstatus pro Kurs für einen Kurs. |
| `moodle_quiz_info` | lesen | Free | Tests mit Zeit-/Versuchsgrenzen sowie der **eigene** Versuchsverlauf und die beste Note des Nutzers — niemals die Versuche anderer Nutzer. |
| `moodle_my_submission_files` | lesen | Premium | Die **eigene** letzte Abgabe des Nutzers für eine Aufgabe: Dateien und Online-Text-Inhalt. Streng auf die eigene Person beschränkt. |
| `moodle_grading_queue` | lesen | Premium | Lehrenden-Ansicht: Aufgaben, die die lehrende Person bewerten kann, mit Anzahl der Abgaben, die zu bewerten scheinen. |
| `moodle_unanswered_forum_posts` | lesen | Premium | Lehrenden-Ansicht: sichtbare Forendiskussionen ohne Antworten, die die lehrende Person beantworten kann. |
| `moodle_send_message` | **schreiben** | Premium | Sendet eine Eins-zu-eins-Mitteilung. Zweistufig: Vorschau, dann `confirm=true`. Respektiert `can_send_message()`. |
| `moodle_create_user` | **schreiben** | Free | Erstellt ein Nutzerkonto. Zweistufige Bestätigung; erfordert `moodle/user:create`. |
| `moodle_create_course` | **schreiben** | Premium | Erstellt einen Kurs in einem Bereich. Zweistufige Bestätigung; erfordert `moodle/course:create` im Bereichskontext. |
| `moodle_update_course` | **schreiben** | Premium | Aktualisiert Kurstitel, Kurzname, Sichtbarkeit, Zusammenfassung und Daten. Zweistufige Bestätigung; erfordert `moodle/course:update`. |
| `moodle_enrol_user` | **schreiben** | Premium | Schreibt einen bestehenden Nutzer per manueller Einschreibung ein. Zweistufige Bestätigung; erfordert `enrol/manual:enrol`. |

Wenn **Rohe Moodle-Webservice-Funktionen bereitstellen** aktiviert ist **und** das
Premium-Add-on aktiv ist, wird jede dem authentifizierten Dienst zugewiesene externe
Funktion zusätzlich als MCP-Werkzeug bereitgestellt. Das ist für Power-User praktisch,
vergrößert aber die Schema-Oberfläche; deaktivieren Sie es für produktive KI-Agenten,
um den Katalog auf den oben genannten kuratierten Satz zu beschränken.

### Fehlersemantik

Fachliche Fehler (Capability verweigert, Validierungsfehler, nicht gefunden) werden als
MCP-Werkzeug-Ausführungsfehler zurückgegeben, damit das LLM sich selbst korrigieren
kann:

```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "result": {
    "isError": true,
    "content": [{ "type": "text", "text": "Course 9999 does not exist or you do not have access to it." }],
    "structuredContent": { "error": "Course 9999 does not exist or you do not have access to it." }
  }
}
```

Protokollfehler (fehlerhafte Anfrage, unbekannte Methode, nicht unterstützte
Protokollversion) bleiben JSON-RPC-Fehler mit dem passenden Code.

### Unterstützte MCP-Methoden

| Methode | Beschreibung |
|---|---|
| `initialize` | Capability-Verhandlung; respektiert die `protocolVersion` des Clients. |
| `notifications/initialized` | Wird mit HTTP 202 gemäß Streamable-HTTP-Spezifikation bestätigt. |
| `ping` | Leichtgewichtige Funktionsprüfung. |
| `tools/list` | Paginierte Liste kuratierter KI-nativer Werkzeuge + (optional) roher Moodle-Webservice-Funktionen. Unterstützt `cursor` / `nextCursor`. |
| `tools/call` | Ruft entweder ein KI-natives Werkzeug oder eine externe Moodle-Funktion im Sicherheitskontext des authentifizierten Nutzers auf. |
| `resources/list`, `prompts/list` | Geben derzeit eine leere Liste zurück. Für kommende Releases reserviert. |

`GET` ohne `Accept: text/event-stream` gibt ein Server-Info-JSON zurück. `OPTIONS`
gibt 204 für CORS-Preflight zurück. `DELETE` gibt 405 zurück (es werden derzeit keine
Sitzungen ausgegeben).

---

## 🛡 Sicherheitsmodell

| Grenze | Durchsetzung |
|----------|-------------|
| Token in URLs | Standardmäßig aus; Tokens im Query-String sind Opt-in und geben bei Verwendung einen `Deprecation`-Header aus. |
| Origin | Gegen eine Admin-Allowlist geprüft. Anfragen vom selben Origin und Server-zu-Server-Anfragen (ohne `Origin`) werden immer akzeptiert. |
| CORS | Spiegelt nur erlaubte Origins; niemals `*` zusammen mit Anmeldedaten. |
| Ratenbegrenzung | Pro Token (oder pro IP, wenn nicht authentifiziert). HTTP 429 mit `Retry-After`- und `X-RateLimit-Remaining-*`-Headern. |
| Anfragegröße | Am Einstiegspunkt durchgesetzt (HTTP 413 oberhalb des Limits). |
| Endpunkt-Capability | `webservice/elediamcp:use` wird nach der Authentifizierung im Systemkontext geprüft, zusätzlich zu den Capability-Prüfungen pro Funktion. |
| Endpunkt-Dienstbereich | Mit aktiviertem **Endpunkt auf MCP-Dienste beschränken** (Standard) können nur Tokens, deren externer Dienst ein konfigurierter MCP-Dienst ist, den Endpunkt erreichen; andere Tokens erhalten HTTP 401. Die MCP-Dienstliste ist die Zugriffsgrenze, nicht nur die Ausgabegrenze. |
| Autorisierung pro Werkzeug | Jedes Werkzeug prüft die jeweilige Moodle-Capability erneut mit der expliziten Nutzer-ID und bleibt auf die Daten des authentifizierten Nutzers beschränkt. |
| Audit | `tool_invoked`-, `write_performed`-, `context_verified`-, `token_created`-, `token_revoked`-Events unter *Website-Administration → Berichte → Protokolle* (über Logstore-Plugins weiterleitbar). |
| Notabschaltung | Gibt sofort HTTP 503 zurück, vor jeder weiteren Verarbeitung. |

> **Hinweis zur Ratenbegrenzung:** Die Zähler liegen im Moodle-Application-Cache. Für
> strikte produktive Limits konfigurieren Sie einen gemeinsamen atomaren Cache-Store
> (z. B. Redis); das Inkrement ist nicht auf jedem Backend garantiert atomar.

---

## 🔑 Token-Verwaltung

Über die Admin-Seite **Tokens verwalten** hinaus liefert das Plugin eine
produktionsreife, MCP-spezifische Token-Verwaltung mit einer sauberen Audit-Spur.

### MCP-Dienste konfigurieren

Unter **Website-Administration → Plugins → Webservices → Model Context Protocol**
wählt die Einstellung **Externe MCP-Dienste** aus, welche externen Dienste
MCP-Tokens ausgeben dürfen. Nur dort aufgeführte Dienste können in der
Selbstbedienungs-Oberfläche gewählt oder über die interne API angesprochen werden. So
bleiben MCP-Anmeldedaten von unverbundenen Webservices isoliert.

### Selbstbedienungs-Oberfläche

Nutzer mit `webservice/elediamcp:managetokens` (standardmäßig dem Archetyp `user`
gewährt) erhalten einen Eintrag **MCP-Tokens** auf ihrer Seite **Einstellungen**. Dort
können sie:

- ein Token **erstellen**, indem sie eine Bezeichnung, einen MCP-Dienst, den sie
  verwenden dürfen, und ein optionales Ablaufdatum wählen;
- die **Metadaten** ihrer bestehenden Tokens **einsehen** — Bezeichnung, Dienst,
  Erstellungsdatum, Ablauf, Datum der letzten Verwendung und Status (aktiv / abgelaufen
  / widerrufen);
- ein Token **widerrufen**, wodurch die zugrunde liegende Anmeldeinformation sofort
  gelöscht wird.

Der vollständige Token-Wert wird **genau einmal** unmittelbar nach der Erstellung
angezeigt. Er wird niemals im Klartext gespeichert und nie erneut angezeigt — nur ein
SHA-256-Hash wird zur Korrelation aufbewahrt.

### Interne PHP-API

Vertrauenswürdige Erstanbieter-Plugins stellen nutzergebundene MCP-Tokens über
`\webservice_elediamcp\api` bereit und widerrufen sie. Jeder Aufruf wird der
aufrufenden Komponente zur Auditierung zugeordnet, und die komponentenbezogene
Widerrufung betrifft nur Tokens, die diese Komponente erstellt hat
(Selbstbedienungs-Tokens werden nie berührt).

```php
// Provision a token for a learner's AI tutor.
$result = \webservice_elediamcp\api::create_token(
    'local_aitutor',     // calling component (must be installed)
    $userid,             // token owner
    $serviceid,          // a configured MCP external service
    'AI tutor',          // label
    time() + WEEKSECS    // optional expiry (0 = never)
);
// Show $result->token to the user once, then discard it.
$tokenmetadata = $result->record; // metadata only, no secret

// Later: revoke a single token, or all of this component's tokens for the pair.
\webservice_elediamcp\api::revoke_token('local_aitutor', $result->record->id);
\webservice_elediamcp\api::revoke_user_service_tokens('local_aitutor', $userid, $serviceid);

// Read-only metadata listing (no secrets) and the configured service list.
$tokens   = \webservice_elediamcp\api::get_user_tokens($userid);
$services = \webservice_elediamcp\api::get_services();
```

Die Erstellung erzwingt unabhängig vom Aufrufer stets dieselben Garantien: Der Dienst
muss ein konfigurierter, aktivierter MCP-Dienst sein; die Zielperson muss aktiv sein
und die erforderliche Capability des Dienstes erfüllen (sowie, bei eingeschränkten
Diensten, in der Liste berechtigter Nutzer stehen); und ein etwaiges Ablaufdatum muss
in der Zukunft liegen. Lebenszyklus-Änderungen lösen die Audit-Events `token_created`
und `token_revoked` aus.

### Speichermodell

Die Authentifizierung läuft weiterhin über Moodles Kerntabelle `external_tokens`. Eine
begleitende Tabelle `webservice_elediamcp_token` enthält die MCP-spezifischen
Lebenszyklus- und Audit-Metadaten und **überlebt die Widerrufung** (das zugrunde
liegende Kern-Token wird gelöscht, sodass es nicht mehr authentifizieren kann, während
die Metadaten-Zeile als widerrufen markiert und der Zeitstempel der letzten Verwendung
festgehalten wird), wodurch widerrufene Tokens auditierbar bleiben. Eine geplante
Aufgabe entfernt Metadaten widerrufener Tokens, die älter als das konfigurierte
Aufbewahrungsfenster sind.

---

## 🛂 Datenschutz / DSGVO

Dieses Plugin speichert **ausschließlich Token-Metadaten** — Inhaber, Dienst,
Bezeichnung, Zeitstempel und Widerrufsstatus — in `webservice_elediamcp_token`. Das
Token-Geheimnis selbst wird niemals gespeichert (nur ein SHA-256-Hash zur Korrelation).
Ein vollständiger Privacy-Provider deklariert diese Daten und implementiert Export und
Löschung, einschließlich der Loslösung gelöschter Nutzer von Tokens, die sie lediglich
erstellt oder widerrufen haben. Die Protokollschicht selbst speichert keine
personenbezogenen Daten.

---

## ✅ Tests

```bash
# From the Moodle root, after php admin/tool/phpunit/cli/init.php:
vendor/bin/phpunit --testsuite webservice_elediamcp_testsuite
```

Die Testabdeckung umfasst:

- JSON-RPC-2.0-Anfrage-Parsing und -Validierung (`request_test`)
- Verhandlung der Protokollversion (`protocol_test`)
- Sicherheitshelfer, Origin-Prüfung, Ratenbegrenzung (`security_test`)
- Werkzeug-Provider, Annotation-Inferenz, Paginierung (`tool_provider_test`, `tool_provider_extras_test`)
- KI-native, Inhalts- und Lernenden-Werkzeuge (`ai_tools_test`, `content_tools_test`, `learner_tools_test`)
- Negativ-/Fehlerfälle über alle Werkzeuge hinweg (`negative_cases_test`)
- Server-Lebenszyklus und Parameter-Koerzierung (`server_test`)
- Token-Lebenszyklus-Manager: Erstellung, Widerrufung, Ablauf, Auflistung (`token_manager_test`)
- Interne Token-API: Komponentenzuordnung und Eigentümer-Beschränkung (`api_test`)
- Privacy-Provider Export/Löschung (`privacy_provider_test`)
- Client-Bibliothek (`client_test`)

Die Behat-Abdeckung (`--tags @webservice_elediamcp`) durchläuft die
Selbstbedienungs-Oberfläche von Anfang bis Ende: den Einstellungen-Link, den
Ablauf Erstellen → einmaliges Anzeigen → Widerrufen sowie die Hinweisführung „kein
nutzbarer Dienst".

---

## 🩺 Fehlerbehebung

| Symptom | Lösung |
|---|---|
| „Ungültiges Token" | Prüfen Sie das Token, die Capability `webservice/elediamcp:use` des Nutzers und ob der Dienst aktiviert ist. |
| 401 mit `WWW-Authenticate` | Die Anfrage ist nicht authentifiziert. Fügen Sie `Authorization: Bearer YOUR_TOKEN` hinzu. |
| 403 „Origin not allowed by site policy" | Fügen Sie den Client-Origin zu **Zulässige CORS-Origins** in den Plugin-Einstellungen hinzu. |
| 429 mit `Retry-After` | Ratenbegrenzung überschritten; warten Sie die angegebene Anzahl Sekunden oder erhöhen Sie die Limits in den Einstellungen. |
| 413 „Request body exceeds the maximum allowed size" | Erhöhen Sie die Einstellung **Maximale Größe des Anfragetexts**. |
| 503 mit `Retry-After: 3600` | **Notabschaltung** ist in den Plugin-Einstellungen aktiv. |
| Weniger Werkzeuge als erwartet | Der vollständige kuratierte Katalog erfordert das Add-on eLeDia.ai Tutor Premium (Funktion `mcp_tools`); die kostenlose Version stellt die Basis aus 15 Werkzeugen bereit. Rohe Werkzeuge erfordern zusätzlich **Rohe Moodle-Webservice-Funktionen bereitstellen** sowie dem Dienst hinzugefügte Funktionen. Die kostenlosen KI-nativen Werkzeuge sind immer vorhanden. |
| 401 „This token is not authorised for the MCP service" | Der externe Dienst des Tokens steht nicht in **Externe MCP-Dienste**, während **Endpunkt auf MCP-Dienste beschränken** aktiv ist. Fügen Sie den Dienst hinzu oder deaktivieren Sie die Beschränkung für Übergangsszenarien. |
| `?wstoken=` gibt 401 zurück | Aktivieren Sie **Token im Query-String zulassen** in den Plugin-Einstellungen oder verwenden Sie den Authorization-Header. |

---

## 🗺 Roadmap

Siehe die Projekt-Roadmap für den geplanten OAuth-2.1-Authorization-Code-+-PKCE-Flow
(`/.well-known/oauth-authorization-server`), weitere Schreibwerkzeuge, Resources und
Prompts sowie semantische Suche.

---

## 🤝 Mitwirken

Pull Requests, Issues und Sicherheitsmeldungen sind willkommen. Bitte beachten Sie
[CONTRIBUTING.md](CONTRIBUTING.md) für Branching-Konventionen, Commit-Message-Standards
und Review-Erwartungen sowie [SECURITY.md](SECURITY.md) für den vertraulichen Prozess
zur Offenlegung von Sicherheitslücken. Die Release-Historie befindet sich in
[CHANGELOG.md](CHANGELOG.md).

## 📜 Lizenz

GPL v3 oder neuer — siehe [LICENSE](LICENSE) für den vollständigen Text.

---

<p align="center">
  <a href="https://eledia.de" title="eLeDia GmbH — eLearning im Dialog">
    <img src="https://eledia.de/wp-content/uploads/2025/01/cropped-eLeDia_Logo-136x51.png"
         alt="eLeDia GmbH"
         width="136" height="51">
  </a>
  <br><br>
  <strong>eLeDia GmbH</strong> · <em>eLearning im Dialog</em><br>
  Wilhelmsaue 37 · 10713 Berlin · Germany<br>
  <a href="tel:+4930505610700">+49 30 5056 10-70</a> ·
  <a href="mailto:info@eledia.de">info@eledia.de</a> ·
  <a href="https://eledia.de">eledia.de</a><br>
  <sub>Moodle Premium Partner · Moodle Global Partner of the Year 2025</sub>
</p>

<p align="center">
  <sub>© 2025–2026 eLeDia GmbH, Berlin · Veröffentlicht unter der GNU GPL v3 oder neuer · Mit ❤️ in Berlin entwickelt</sub>
</p>
