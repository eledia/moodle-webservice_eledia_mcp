# Qualität

## Meta

Dieses Dokument erfasst die **Qualitätssicht** auf das System.

Es enthält:
- Bugs (`bugXX`) — reproduzierbare Probleme, mit Severity
- Tests (`testXX`) — Verifikationen, die auf Akzeptanzkriterien aus `01-features.md` verweisen

Es enthält **nicht**:
- Ideen → `01-features.md`
- Tasks → `04-tasks.md`

---

## 🐞 Bugs

### Severity-Skala

| Severity | Bedeutung | Reaktion |
|---|---|---|
| **S1** | Kritisch — Kernfunktion komplett kaputt, Datenverlust, Sicherheitslücke | Sofortiger Hotfix-Task, blockiert Release |
| **S2** | Schwer — Feature unbrauchbar oder schwerer UX-Defekt, kein Workaround | Im laufenden Release fixen |
| **S3** | Mittel — Feature eingeschränkt, Workaround vorhanden | Nächstes Release |
| **S4** | Gering — kosmetisch, edge-case, minor | Backlog, opportunistisch |

### Vorlage

```
### bugXX Kurztitel

Feature:  featXX
Severity: S1 | S2 | S3 | S4
Status:   open | in_progress | fixed | wontfix
Linked:   taskXX (Fix), testXX (Reproduktion)

**Beschreibung**
Was ist falsch?

**Reproduktion**
1. ...
2. ...

**Erwartet**
Was sollte passieren?

**Tatsächlich**
Was passiert stattdessen?

**Umgebung** (falls relevant)
Browser / Version / Konfiguration / Datenstand
```

---

### bug42 Kein Test-Skelett für Library-Pfad
Feature:  feat42
Severity: S2
Status:   fixed
Linked:   task42, test42

**Beschreibung**
Der frühere Library-Bereich hatte keine verlässliche, im owning Plugin
verankerte Teststruktur. Nach der Produktentscheidung ist Library ein
ContentHub-Pfad und muss dort getestet werden.

**Reproduktion**
1. ContentHub-Repo prüfen: Library-spezifische Tests unter
   `plugins/local_lernhive_contenthub/tests/library/` und Behat unter
   `plugins/local_lernhive_contenthub/tests/behat/`

**Erwartet**
Jeder Produktpfad startet im owning Plugin mit Test-Skelett.

**Tatsächlich**
Tests sind im ContentHub konsolidiert.

---

### bug43 ContentHub-Library-Import: S3-Auth, Restore-Fehler, Odoo-FK
Feature:  feat43
Severity: S2
Status:   open
Linked:   task43, test43

**Beschreibung**
- S3 presigned URLs + Bearer-Header → Hetzner InvalidArgument
- Moodle Restore: Zielkurs muss vor `restore_controller` belastbar existieren;
  sonst `restore_check_course_not_exists`
- Odoo: FK-Fehler beim Löschen von Wizard/Version

**Reproduktion**
1. Import mit presigned URL und Bearer-Header → Fehler
2. ContentHub-Library-Import mit scheinbar korrektem .mbz → `restore_check_course_not_exists`
3. Odoo: Wizard-Objekt löschen, Version bleibt referenziert

**Erwartet**
- Kein Auth-Header bei presigned
- Restore legt Zielkurs wie Moodle UI sauber an und schlägt mit klarer
  ContentHub-Fehlermeldung fehl, falls das .mbz nicht wiederherstellbar ist
- FK-Fehler werden sauber behandelt

**Tatsächlich**
Siehe oben

---

### bug44 Pathways demo provider blocked by email message processor failure
Feature:  feat45
Severity: S1
Status:   fixed
Linked:   task45, test45

**Beschreibung**
Pathways notification observers treated Moodle message delivery failures as
fatal event-observer failures. During demo-data cleanup, cohort unbind triggered
`on_allocation_deallocated_notify`, and a failing email message processor
aborted the whole demo provider.

**Reproduktion**
1. Run the Pathways demo-data provider on an environment where the Moodle email
   message processor fails.
2. Trigger demo cleanup / cohort unbind.
3. Observe the deallocation notification observer.

**Erwartet**
Notification delivery failures are logged, but demo-data cleanup and
deallocation continue.

**Tatsächlich**
The demo provider failed with:
`local_lernhive_pathways deallocation notification failed: Error calling message processor email`

**Umgebung**
Moodle developer debugging enabled; Pathways notifications active.

---

### bug45 moodle_find_user: Discovery strenger als der Sende-Pfad
Feature:  webservice_elediamcp
Severity: S3
Status:   fixed
Linked:   task52, review54, review55

**Beschreibung**
`moodle_find_user` findet existierende, sendbare Nutzer nicht. Das Tool nutzt
`\core_message\api::message_search_users()` (Rückgabe als `contacts` /
`noncontacts`). Der noncontacts-Zweig filtert Nicht-Kontakte ohne gemeinsamen
Kurs heraus (außer `$CFG->messagingallusers`). Der Action-Pfad
`moodle_send_message` per `to_user_id` prüft dagegen nur `can_send_message()` /
`moodle/site:sendmessage`. Daraus folgt eine Asymmetrie: per ID sendbar, aber
nicht auffindbar.

**Reproduktion**
1. Admin-Token (User-ID 2, keine Einschreibungen, kein Kontakt zu Ziel-User).
2. `moodle_find_user(query="Paul")` / `"Maier"` / `"Paul Maier"` → 0 Treffer.
3. `moodle_send_message(to_user_id=72)` → erfolgreich (Paul Maier, message_id 2).

**Erwartet**
Was per ID sendbar ist, ist auch auffindbar — oder zumindest mit einem klaren
`messageable`-Flag annotiert. Keine Regression für nicht-privilegierte Token.

**Tatsächlich**
`total_matches: 0`, leere `contacts`/`noncontacts`-Buckets.

**Fix-Ansatz**
Admin-aware Fallback: Wenn der Token-User `is_siteadmin` ist bzw.
`moodle/site:sendmessage` hält, nicht über die Messaging-Suche gehen, sondern
über `core_user_get_users_by_field` / direkte `{user}`-Suche; Messageability
optional per Flag annotieren. Zusätzlich Mehrwort-Namensmatching
(`firstname`/`lastname` bzw. `CONCAT`). Umgebung: „eledia.ai Local Moodle"
(Moodle 5.2.1, Docker, http://localhost:8080).

---

### bug46 moodle_search_courses: kein Katalog-Browsing (Handover-Fehldiagnose korrigiert)
Feature:  webservice_elediamcp
Severity: S3
Status:   fixed
Linked:   task53, review54, review55

**Beschreibung**
Handover meldete „0 Treffer, falsche API-Schicht (globale Volltextsuche)".
Am Code verifiziert ist die Prämisse falsch: `moodle_search_courses` nutzt
bereits `core_course_category::search_courses(['search' => $query], …)` — die
Katalog-Such-API, **kein** `\core_search\manager`. `enrol_get_users_courses`
dient nur dem Highlighting der eigenen Kurse, **nicht** als Ergebnisfilter.
Sichtbarkeit (Admin sieht versteckte Kurse, Teilnehmer nur freigegebene) kommt
korrekt über die Capabilities der Core-API.

Die 0-Treffer im Handover-Test entstanden, weil die Suchbegriffe
(`course`, `kurs`, `test`, `in`) nicht in den Kursnamen der Instanz vorkommen
(„Welcome to Moodle", „Fragentypen", „Moodle Demo"). Ein Suchbegriff wie
`moodle` hätte zwei Kurse geliefert.

**Echte Lücke**
„Wie viele Kurse habe ich / liste alle auf" ist Enumeration, nicht Suche; eine
Such-API mit `MIN_QUERY = 2` kann das nicht beantworten. Es fehlt ein
Browse-/List-Tool.

**Fix-Ansatz**
Neues Tool `moodle_list_courses` (paginiertes Katalog-Browsing ohne Query,
z. B. `core_course_category::top()->get_courses(['recursive'=>true,…])`,
Front-Page-Kurs id 1 ausgeschlossen, Capability-Sichtbarkeit). Optional
`scope`-Parameter (`catalogue` | `enrolled`) in `moodle_search_courses`,
ggf. Konsolidierung des `enrolled`-Pfads mit `moodle_my_courses`. Tool-
Beschreibungen schärfen, damit das LLM Suchen vs. Browsen unterscheidet.

---

## 🧪 Tests

Jeder Test verweist auf ein Akzeptanzkriterium aus `01-features.md` und macht es prüfbar.

### Vorlage

```
### testXX Kurztitel

Feature:                featXX
Akzeptanzkriterium:     featXX.ACyy
Typ:                    manuell | automatisiert
Status:                 pending | pass | fail
Letzter Lauf:           YYYY-MM-DD

**Schritte**
1. ...
2. ...

**Erwartetes Ergebnis**
Aus dem Akzeptanzkriterium übernommen oder konkretisiert.

**Beobachtetes Ergebnis** (bei pass/fail)
Was wurde tatsächlich gesehen?

**Verlinkter Bug** (bei fail)
bugXX
```

---

### test42 Teststruktur vorhanden
Feature:                feat42
Akzeptanzkriterium:     feat42.AC1
Typ:                    automatisiert
Status:                 pass
Letzter Lauf:           2026-05-02

**Schritte**
1. PHPUnit- und Behat-Teststruktur im ContentHub prüfen
2. Sicherstellen, dass keine separate Library-Plugin-Teststruktur als neuer
   DevFlow wieder eingeführt wurde

**Erwartetes Ergebnis**
Teststruktur ist im ContentHub vorhanden und lauffähig.

**Beobachtetes Ergebnis**
Struktur vorhanden; PHP-Lint ist grün, vollständige PHPUnit-Ausführung lokal
wegen fehlendem Docker/OrbStack nicht ausgeführt.

---

### test43 Fehlerfälle ContentHub-Library-Import
Feature:                feat43
Akzeptanzkriterium:     feat43.AC1
Typ:                    manuell
Status:                 pending
Letzter Lauf:           2026-05-02

**Schritte**
1. Import mit presigned URL + Bearer testen
2. Restore mit .mbz aus UI und ContentHub-Library-Import vergleichen
3. Odoo-Wizard-Delete testen

**Erwartetes Ergebnis**
Alle Fehlerfälle werden korrekt behandelt oder sauber geloggt.

---

### test44 ContentHub-Library UX Smoke
Feature:                feat44
Akzeptanzkriterium:     feat44.AC1
Typ:                    manuell
Status:                 pending
Letzter Lauf:           2026-05-02

**Schritte**
1. `/local/lernhive_contenthub/library.php` auf dev öffnen
2. Prüfen, ob Plugin-Shell-Breite zur Default-Entscheidung passt
3. Prüfen, ob Karten gleich hoch wirken
4. Suche, Sprachfilter und Themenfilter bedienen
5. Import-Bestätigungsseite öffnen

**Erwartetes Ergebnis**
- ContentHub bleibt ein einheitlicher Produktbereich
- Library-Karten springen nicht in der Höhe
- Suche/Filter reduzieren die Karte sichtbar ohne Layoutbruch
- Import-Bestätigung ist eine ContentHub-Fläche, kein nackter Moodle-Formblock

---

### test45 Pathways notification delivery failure does not abort deallocation
Feature:                feat45
Akzeptanzkriterium:     feat45.AC01
Typ:                    automatisiert + manuell
Status:                 pending
Letzter Lauf:           2026-05-03

**Schritte**
1. Run `notification_sender_test::test_message_processor_failure_is_silent`.
2. Run `notification_observer_test::test_deallocate_survives_message_processor_failure`.
3. After deploy, run the Pathways demo-data provider and demo cleanup.

**Erwartetes Ergebnis**
The sender returns `false` instead of throwing when message delivery fails, and
`assignment_service::deallocate()` still leaves the assignment in
`cancelled` state.

**Beobachtetes Ergebnis**
Implementation and regression tests were merged via PR #111 on 2026-05-03
(`e7171b85`). Static validation is green (`php -l`, Moodle phpcs,
`git diff --check`). Full PHPUnit execution and post-deploy demo verification
are pending because local Docker/OrbStack is not reachable.

**Verlinkter Bug**
bug44

---

### test46 Pathways catalogue detail shows included courses/steps
Feature:                feat46
Akzeptanzkriterium:     feat46.AC01, feat46.AC02
Typ:                    automatisiert
Status:                 passed
Letzter Lauf:           2026-05-03

**Schritte**
1. Run `Test on Hetzner` with suite `behat`.
2. Use tag expression `@local_lernhive_pathways`.
3. Verify catalogue detail with two course steps.
4. Verify catalogue detail empty-state.
5. Verify Pathway edit form renders including scheduling help section and save button.

**Erwartetes Ergebnis**
Learners can see what belongs to the Pathway before starting/requesting it.
Course steps link to Moodle course pages; empty Pathways clearly say that no
steps/courses exist yet.

**Beobachtetes Ergebnis**
Implementation merged in PR #113 (`626703e5`). Behat coverage merged in PR #114
and corrected in PR #123. GitHub Actions run `25281212353` passed on
2026-05-03 for `@local_lernhive_pathways` on head `6ac46ee2`.

---

### test47 Customer Portal billing-event history
Feature:                feat47
Akzeptanzkriterium:     feat47.AC01, feat47.AC02, feat47.AC03
Typ:                    automatisiert + manuell
Status:                 pending
Letzter Lauf:           2026-05-10

**Schritte**
1. Run `odoo_billing_service_test::test_get_billing_event_history_returns_customer_timeline`.
2. Run `odoo_billing_service_test::test_get_billing_event_history_requires_config`.
3. Open `/local/customerportal/billing-events.php` with Odoo test data.
4. Verify dashboard link, empty state, unavailable state and the four status
   badge labels.

**Erwartetes Ergebnis**
The billing-event history posts to
`/eledia/maas/v1/billing-event-history` with
`{installation_id, limit}` and renders only customer-facing fields. Empty and
unavailable states remain readable.

**Beobachtetes Ergebnis**
Implementation committed in `1f8cd317` on 2026-05-10. Static validation is
green (`php -l`, UI boundary lint, `git diff --check`). Full local PHPUnit and
browser screenshot are pending because `playbooks/test.local.env` is missing.

---

### test48 Certify documentation reflects shipped and pending slices
Feature:                feat48
Akzeptanzkriterium:     feat48.AC01, feat48.AC02
Typ:                    manuell
Status:                 pass
Letzter Lauf:           2026-05-10

**Schritte**
1. Inspect `plugins/local_lernhive_certify` code surfaces, services, tests and
   docs.
2. Update plugin docs to mark shipped, partial and pending LH-CRT slices.
3. Verify that the change is documentation-only for Certify and does not alter
   customer-facing UX/UI code.

**Erwartetes Ergebnis**
The next implementation step is clear: PDF certificate rendering/dispatch or
learning-record UI/privacy hardening. No Certify runtime code changes are part
of this documentation pass.

**Beobachtetes Ergebnis**
Plugin docs now state that the core certification lifecycle, catalogue,
approval, history import, Report Builder, webservices, custom fields, external
record model and learning-record aggregate service exist. Known gaps are PDF
rendering/dispatch/archive, learner/manager learning-record UI and external
record privacy/file export-delete hardening.

---

### test49 Odoo Library editorial backend and storage profiles
Feature:                feat49
Akzeptanzkriterium:     feat49.AC01, feat49.AC02, feat49.AC03, feat49.AC04
Typ:                    automatisiert + manuell
Status:                 pending
Letzter Lauf:           2026-05-10

**Schritte**
1. Run Odoo tests for `eledia_library`:
   `odoo-bin -d <db> -i eledia_library --test-enable --test-tags=eledia_library --stop-after-init`.
2. Verify model tests for storage-profile default handling, positive TTL,
   template `sourcecourseid` guard, flavour normalisation and tag handling.
3. Verify HttpCase feed tests for `entry_type`, active `tags`, and flavour
   filtering.
4. Open Odoo UI and confirm Library Items is the primary editorial surface,
   Releases are under Operations, and `.mbz` upload wording is editorial.
5. Create two storage profiles, mark one default, upload a release with a
   profile and confirm feed/download signing uses that profile.

**Erwartetes Ergebnis**
Odoo Library exposes a coherent editorial workflow and feed contract while
maintaining backwards compatibility with ContentHub's `sourcecourseid`
template handoff. Storage profiles support multiple S3-compatible backends,
including connection testing and per-release selection.

**Beobachtetes Ergebnis**
Static validation passed locally on 2026-05-10: Python compile for the Odoo
addon, XML parse with `xmllint`, and `git diff --check`. Full Odoo test DB run
and browser/UI smoke remain pending.

---

### test50 LernHive Copilot-Findings follow-up
Feature:                feat50
Akzeptanzkriterium:     feat50.AC01, feat50.AC02, feat50.AC03
Typ:                    automatisiert + manuell
Status:                 pending
Letzter Lauf:           2026-06-23

**Schritte**
1. Verify that the P1 findings from `task50` have dedicated fixes or documented
   wontfix decisions:
   - Certify Cohort-Observer uses Moodle event IDs correctly.
   - Certify observer/service does not fatal during install/upgrade or
     partial-schema states.
   - Pathways collaboration activity lookup does not depend on an empty module
     name.
2. Verify that the P2 findings have either landed or been split into follow-up
   tasks:
   - Orgchart tools URL/navigation consistency.
   - `local_lernhive` hook dead-code/performance cleanup.
   - Certify product/RFP documentation version sync.
3. Verify that the P3 findings have either landed or remain explicitly parked:
   - Course-format lang guards.
   - Course-format Moodle baseline metadata.
   - Pathways help-string examples without angle-bracket placeholders.
4. For each implementation PR, run relevant PHP lint, PHPUnit/Behat coverage
   where available, `git diff --check`, and GitHub checks.

**Erwartetes Ergebnis**
The closed-PR Copilot audit has no unresolved high-priority runtime or upgrade
risks. Any remaining low-priority conventions are explicitly tracked instead
of staying hidden in old PR comments.

**Beobachtetes Ergebnis**
Audit findings were documented on 2026-06-23 in `04-tasks.md` as `task50`.
Implementation fixes are still pending.

---

## Review-Protokoll

### review51 webservice_elediamcp Code-Review
Datum:   2026-06-25
Branch:  review_johannes
Typ:     Moodle-Core-/Security-Review
Status:  fixed

**Gesamteinschätzung**
Das Plugin ist sicherheitsbewusst aufgebaut: Capability-Checks,
IDOR-Schutz über Kurszugriff, Zwei-Schritt-Bestätigung für Write-Tools,
Session-Key-Schutz, Privacy-API und Audit-Events sind vorhanden.

**Befunde und Ergebnis**

| ID | Schwere | Thema | Status |
|---|---|---|---|
| KR-1 | kritisch | `moodle_forum_discussions::read_posts()` prüfte Capability ohne explizite User-ID | fixed |
| KR-2 | kritisch | `moodle_get_resource::generic_intro()` nutzte dynamischen Modul-Tabellennamen ohne ausreichende Prüfung | fixed |
| HO-1 | hoch | Rate-Limiter ist nicht atomar | documented |
| HO-2 | hoch | `moodle_me` / `moodle_verify_user_context` gaben E-Mail unabhängig von `emaildisplay` aus | fixed |
| HO-3 | hoch | `allowed_origins` nutzte `PARAM_RAW` und hatte keine Formularvalidierung | fixed |
| HO-4 | hoch | Neues Token wurde kurz in der Moodle-Session zwischengespeichert | fixed |
| HO-5 | hoch | `moodle_send_message` hatte keinen expliziten `moodle/site:sendmessage`-Check | fixed |
| MI-1 | mittel | Rate-Limit-Verhalten nicht ausreichend dokumentiert | fixed |
| MI-2 | mittel | Tool-/Ressourcen-Cache-TTL prüfen | ok |
| MI-3 | mittel | Forum-Tool hatte N+1-Abfragen für Firstposts/Replycounts | fixed |
| MI-4 | mittel | Quiz-Tool brauchte konsistente `can_access_course()`-Prüfung | fixed |
| MI-5 | mittel | MCP `SERVER_VERSION` war nicht semantisch auf Pluginstand | fixed |
| MI-6 | mittel | Create-User-Tool sollte Passwort nach Anlage aus dem Record entfernen | fixed |
| MI-7 | mittel | Raw-Webservice-Funktionen brauchten sichereren Default/Warnung | fixed |
| MI-8 | mittel | Request-Body wurde doppelt aus `php://input` gelesen | fixed |
| NI | niedrig | Kosmetik/Standards: Shell-Klassen, Settings-Redirect, Search-Kommentar, Privacy-Cleanup, OAuth-Doku-URL | fixed |

**Verifikation**
- PHP-Syntaxprüfung für geänderte/neue Plugin-Dateien: passed
- `git diff --check`: passed
- HTTP/MCP-Smoke: `tools/list` enthält `moodle_create_user` und `moodle_create_course`
- PHPUnit: not run, lokale Moodle-PHPUnit-Umgebung ohne `$CFG->phpunit_dataroot`

---

### review52 webservice_elediamcp UX/UI-Review
Datum:   2026-06-26
Branch:  review_johannes
Typ:     eLeDia UX/UI-Review
Status:  fixed

**Gesamteinschätzung**
Die UI ist für ein Webservice-Plugin ungewöhnlich sorgfältig gestaltet:
CSS-Namespacing, Sprachstring-Abdeckung, Accessibility und Plugin-Shell-Fallback
sind grundsätzlich sauber.

**Befunde und Ergebnis**

| ID | Schwere | Thema | Status |
|---|---|---|---|
| M1 | mittel | `configuration_form.php` hatte keine `addHelpButton()`-Aufrufe für Settings-Felder | fixed |
| M2 | mittel | `token/index.php` nutzte nicht die Plugin Shell und wirkte für Nicht-Admins inkonsistent | fixed |
| K1 | klein | Toter Sprachstring `tokens_no_services` | fixed |
| K2 | klein | Einzelne Token-Status-/Danger-Farben waren hartcodiert statt über Variablen geführt | fixed |
| K3 | klein | Wrapper-Klasse `webservice-elediamcp-token-page` war semantisch zu eng | fixed |

**Verifikation**
- PHP-Syntaxprüfung für geänderte Dateien: passed
- `git diff --check`: passed
- HTTP-Smoke auf Konfigurations- und Token-Seite: unauthentifiziert sauberer Login-Redirect, kein Fatal

---

### review53 webservice_elediamcp Handover
Datum:   2026-06-25
Branch:  review_johannes
Typ:     Handover aus read-only Code-Review
Status:  integrated

**Kernaussage**
Die Handover-Notizen deckten sich mit dem Code-Review: keine akuten SQLi/XSS/SSRF/CSRF-Lücken, gute Privacy-Provider-Basis und sichere Token-Delegation an Moodle-Core. Die genannten Härtungen wurden in `review51` aufgegriffen.

**Übernommene Punkte**
- MCP-Capability wird hart erzwungen.
- `expose_raw_functions` hat einen sicheren Default für neue Installationen.
- CORS-Wildcard/Credentials-Kombination ist entschärft.
- Tote Ternär-/Kosmetikpunkte und Moodle-5.x-Kontextstil wurden bereinigt, soweit im aktuellen Diff relevant.

---

### review54 webservice_elediamcp Code-Review Runde 2 + Fixes
Datum:   2026-06-27
Branch:  review_johannes
Typ:     Folge-Review mit Umsetzung
Status:  fixed (Kernbefunde), 2 neue Bugs offen (bug45, bug46)

**Gesamteinschätzung**
Zweite, tiefere Review-Runde (Kern selbst gelesen, 18 Tools parallel geprüft).
Kein Cross-User-/IDOR-Leak und keine SQL-Injection. Die als kritisch markierten
Befunde aus review51 sind am Code als geschlossen verifiziert. Gefundene neue
Befunde wurden umgesetzt; zwei Discovery-/Browsing-Themen aus Handovers sind als
bug45/bug46 offen.

**Befunde und Ergebnis**

| ID | Schwere | Thema | Status |
|---|---|---|---|
| H-1 | hoch | `moodle_me` lieferte nie die E-Mail (fehlplatzierte `$email`-Zuweisung in `input_schema()`, undefiniert in `execute()`) | fixed |
| H-2 | hoch | MCP-Endpoint akzeptierte jedes Webservice-Token, nicht nur MCP-Service-Tokens | fixed (Admin-Setting `enforce_mcp_service`, Default an) |
| M-1 | mittel | `moodle_search_content` escapte `snippet` nicht (Inkonsistenz zu `title`) | fixed |
| M-2 | mittel | `moodle_create_user` akzeptierte jedes installierte Auth-Plugin statt nur aktivierte | fixed |
| M-3 | mittel | Rate-Limiter nicht atomar | fixed (MUC-Lock, review56) |
| N-1 | niedrig | `moodle_course_contents` zeigte versteckte Sektion ohne `viewhiddenactivities` | fixed |
| N-2 | niedrig | `moodle_get_announcements` ohne `uservisible`/Gruppen-Check auf News-Forum | fixed (review56) |
| N-3 | niedrig | `moodle_forum_discussions` `discussion_count` nur über die paginierte Seite | fixed |
| N-4 | niedrig | Interne Exception-Messages an Client (`search_courses`, `calendar_upcoming`) | fixed |
| N-5 | niedrig | Toter Code `request::from_raw_input()` / `is_raw_input_empty()` | fixed |
| N-6 | niedrig | Byte- statt Multibyte-Truncation in 8 Tools | fixed |

**H-2 Umsetzung**
Override `server::authenticate_user()` ruft `parent::authenticate_user()` und
prüft danach `token_manager::is_mcp_service((int) $this->restricted_serviceid)`.
Deckt alle Pfade ab (MCP-Methoden, AI-Tools, Raw-Functions via `parent::run()`).
Steuerbar über neues Setting `enforce_mcp_service` (Default 1).

**Neu aus Handovers (offen)**
- bug45 `moodle_find_user`: Discovery strenger als Sende-Pfad → task52.
- bug46 `moodle_search_courses`: Handover-Prämisse („global search") am Code
  widerlegt; echte Lücke ist fehlendes Katalog-Browsing → task53.

**Verifikation**
- PHP-Syntaxprüfung aller geänderten Dateien: passed
- `git diff --check`: passed
- Versionssprung `2026061203`, CHANGELOG `1.0.1`
- PHPUnit/Behat: not run (keine lokale Moodle-PHPUnit-Umgebung) — in CI nachziehen

---

### review55 webservice_elediamcp Discovery/Browsing-Fixes (bug45, bug46)
Datum:   2026-06-27
Branch:  review_johannes
Typ:     Umsetzung Handover-Findings
Status:  fixed

**Gesamteinschätzung**
Die beiden Handover-Themen sind umgesetzt; PO-Entscheidungen q01/q02 eingearbeitet.

**Umsetzung**

| ID | Thema | Lösung | Status |
|---|---|---|---|
| bug45 | `moodle_find_user` Discovery-Asymmetrie | Admin-aware Fallback: bei `is_siteadmin`/`moodle/site:sendmessage` direkte `{user}`-Namenssuche (Mehrwort-Matching über `firstname`/`lastname` + Full-Name), jeder Treffer per `can_send_message()` gefiltert. Nicht-sendbare bleiben gefiltert (q01); nicht-privilegierte Token unverändert. | fixed |
| bug46 | `moodle_search_courses` Browsing/Scope | `scope`-Parameter `catalogue`(Default)\|`enrolled`; `query` optional (leer = Browsen via `core_course_category::top()->get_courses(recursive)`, Front-Page id 1 ausgeschlossen, Capability-Sichtbarkeit). `enrolled` delegiert an `moodle_my_courses` (Konsolidierung, q02). | fixed |

**q01-Entscheidung:** nicht-sendbare User weiter filtern (kein `messageable`-Flag).
**q02-Entscheidung:** scope-Modus statt separatem Tool; `enrolled` auf
`moodle_my_courses` konsolidiert.

**Verifikation**
- `php -l` für `moodle_find_user`, `moodle_search_courses`, `moodle_my_courses`: passed
- Versionssprung `2026061204`, CHANGELOG `1.0.1` (Added)
- PHPUnit/Behat + Live-Smoke gegen „eledia.ai Local Moodle": offen → in CI / lokal
  gemäß Handover-Checkliste (Admin ohne Einschreibung findet alle Kurse / User 72)

---

### review56 webservice_elediamcp Härtung M-3 + N-2
Datum:   2026-06-27
Branch:  review_johannes
Typ:     Härtung verbliebener Review-Befunde
Status:  fixed

**Umsetzung**

| ID | Thema | Lösung | Status |
|---|---|---|---|
| M-3 | Rate-Limiter nicht atomar | Read-Increment-Write in `security::enforce_rate_limit()` wird über den MUC-Lock (`cache::acquire_lock`/`release_lock`) pro Bucket serialisiert; bei Lock-Fehlschlag Best-Effort statt Request-Abbruch. Atomar auch auf nicht-atomaren Stores; Redis weiter für Performance empfohlen. | fixed |
| N-2 | Announcements ohne `uservisible`/Gruppen-Check | `moodle_get_announcements` löst News-Foren via `get_fast_modinfo` auf und liest nur Foren mit sichtbarem cm (`uservisible`); Separate-Groups-Discussions werden auf die Gruppen des Nutzers gescoped (`accessallgroups` berücksichtigt). | fixed |

**Verifikation**
- `php -l` für `security.php`, `moodle_get_announcements.php`: passed
- Versionssprung `2026061205`, CHANGELOG `1.0.1` (Hardened)
- PHPUnit/Behat: offen → in CI

---

### review57 webservice_elediamcp lokaler PHPUnit-Lauf (CI test-Stage lokal)
Datum:   2026-06-27
Branch:  review_johannes
Typ:     Lokale Testausführung (OrbStack) + Folgefund
Status:  green

**Kontext**
Plugin in lokales Docker/OrbStack-Moodle (`demo-webserver-1`, Moodle 5.1.3+,
PHP 8.3, PostgreSQL) deployt und die CI-`test`-Stage lokal ausgeführt:
`phpunit --testsuite webservice_elediamcp_testsuite`.

**Folgefund (Severity S2, Privacy) — behoben**
Der lokale Lauf deckte auf, dass das E-Mail-Hiding das **falsche Property**
`$user->emaildisplay` las. Das Moodle-Feld heißt `maildisplay`
(`user_create_user` kennt nur `maildisplay`). Dadurch lieferte `?? 2` immer den
Default „sichtbar", und die E-Mail wurde in `moodle_me` /
`moodle_verify_user_context` **unabhängig** von der Nutzereinstellung
ausgegeben — der review51/HO-2-Fix hatte faktisch nie gegriffen. Korrigiert in
Code und Tests (`emaildisplay` → `maildisplay`).

**Weiterer Test-Fix**
`tool_provider_test::test_get_tools` setzte `expose_raw_functions` nicht; seit
review53 ist der Default OFF, daher fehlten die Raw-Tools. Test aktiviert das
Flag nun explizit.

**Ergebnis**
- PHPUnit: **123 Tests, 711 Assertions, 0 Failures** (exit 0). Vorher 3 Failures
  (2× maildisplay, 1× expose_raw_functions).
- 14 PHPUnit-Deprecation-Hinweise (Annotation-Stil), nicht test-brechend.
- Version `2026061206`, CHANGELOG `1.0.1` (Security).
- Codechecker (`phpcs --standard=moodle`): **354 Errors / 72 Warnings, 332
  auto-fixbar** — überwiegend Formatierung der aktuellen moodle-cs, auch in
  unveränderten Dateien (vorbestehend). Offen: PHPCBF-Lauf (separat, großer
  rein formaler Diff).

---

### review58 webservice_elediamcp finaler Plugin-Check
Datum:   2026-06-28
Branch:  review_johannes
Typ:     Finaler lokaler Check vor Übergabe
Status:  green

**Ergebnis**
- PHP-Syntaxprüfung aller Plugin-Dateien: **passed**.
- PHPUnit `webservice_elediamcp_testsuite`: **passed** mit 123 Tests,
  711 Assertions, 0 Failures; 14 PHPUnit-Deprecation-Hinweise.
- Behat `@webservice_elediamcp`: **passed** mit 5 Szenarien, 48 Steps,
  0 Failures. Der frühere `Copy it now`-Fund ist durch Post/Redirect/Get
  für neu erzeugte Tokens behoben; Reload zeigt das Token nicht erneut.
  Selenium/WebDriver lief über den lokalen Container `elediaai-selenium`.
- Regression: Help-Action im MCP Plugin Shell Header zeigt jetzt auf
  `/webservice/elediamcp/help.php`; per Behat abgedeckt. Die Hilfe rendert
  die plugin-eigene Dokumentation aus `public/webservice/elediamcp/docs`.
- Docs-Struktur: **passed**. Keine losen `.md`-Dokumente im Repo-Root;
  `Docs` besteht aus den sechs Hauptdateien `00` bis `05`.

**Offen vor Commit/Push**
- Ungetracktes lokales Hilfsskript `tools/local-ci.sh` entweder mit korrekten
  Container-Defaults produktionsfähig machen und aufnehmen oder aus dem
  Übergabeumfang entfernen.
- CSS-Fix zum Ausblenden der Moodle-Blockleiste final committen, falls der
  aktuelle UI-Stand so bleiben soll.

---

## Regeln

- Jeder Bug bekommt eine Severity — auch S4 ist eine Severity.
- Jeder Test referenziert ein Akzeptanzkriterium. Ein Test ohne `featXX.ACyy`-Verweis ist verdächtig.
- Reproduzierbarkeit hat Vorrang vor Vermutung.
- Fixed-Status nur, wenn der zugehörige `testXX` grün ist.

---

## Grundprinzip

> Bugs sind Teil des normalen Loops, keine Ausnahme.
> Ein Test ohne Akzeptanzkriterium prüft nichts Verbindliches.
