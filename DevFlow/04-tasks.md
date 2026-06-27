# Tasks

## Meta

Dies ist das **operative Zentrum** des Systems.

Es enthält:
- neue Beobachtungen
- Tasks (`taskXX`)
- offene Klärungen (`qXX`)
- aktive Arbeit
- Verifikations-Schritte

**Jede Session beginnt hier.**

---

## 🆕 Neu

(Unstrukturierter Input erlaubt — wandert nach Triage in `taskXX` oder `qXX`.)

### Idea: Demo-Hub Smoke-Test als GitHub Actions Workflow *(2026-05-01)*

Aktuell läuft der wöchentliche Demo-Hub Smoke-Test über einen Claude-Cron (`a0e1c01a`, Mo 07:23 CEST) — das ist Session-only und auto-expired nach 7 Tagen, also Re-Schedule jeden Montag manuell nötig.

**Idee:** `.github/workflows/demo-smoke.yml` mit `schedule: cron` (z.B. `27 5 * * 1` = Mo 06:27 CEST nach DST), der die `\local_lernhive\demo_data\registry::providers()` Round-Trip-Smoke-Test-Routine direkt auf der Hetzner-VM ausführt (oder im Container der bereits über deploy-hetzner.yml SSH-Zugriff hat).

**Output-Optionen (eine wählen):**
- GitHub Issue auto-erstellen bei FAIL (wird auto-closed beim nächsten PASS)
- Slack/Discord-Webhook
- Status-Badge im README (würde nur grün/rot zeigen, kein Detail)

**Warum nicht jetzt:** der Claude-Cron reicht für den aktuellen Bedarf, und die Setup-Arbeit für Webhook-Routing + Secret-Management ist ungerechtfertigt solange nur 10 Provider zu testen sind.

**Trigger zum Umsetzen:** sobald (a) ein Provider in den letzten 4 Wochen zweimal regressiert ist, oder (b) Demo-Stack auf >2 Umgebungen läuft, oder (c) Customer-Demos pro Woche >2.

---

## ❓ Klärung benötigt

Offene Fragen, die Fortschritt blockieren. ID-Format: `qXX`.

### q01 moodle_find_user: admin-aware Discovery — und nicht-sendbare User filtern oder flaggen?
Linked: bug45 / task52
Asked-by: KI
Status: answered
Answer: PO-Entscheid: Privilegierte Token (Site-Admin / `moodle/site:sendmessage`)
durchsuchen die `{user}`-Tabelle direkt; nicht-sendbare Treffer werden **weiter
gefiltert** (kein `messageable`-Flag). Nicht-privilegierte Token unverändert.
Umgesetzt in bug45/review55.

### q02 moodle_search_courses: eigenes Browse-Tool oder Scope-Modus — und mit my_courses konsolidieren?
Linked: bug46 / task53
Asked-by: KI
Status: answered
Answer: PO-Entscheid: **Scope-Modus** (`catalogue` | `enrolled`) in
`moodle_search_courses`, kein separates Tool; `query` optional (leer = Browsen).
Der `enrolled`-Scope wird auf `moodle_my_courses` **konsolidiert** (Delegation).
Umgesetzt in bug46/review55.

### Vorlage

```
### qXX Frage in einem Satz
Linked: featXX / taskXX
Asked-by: KI | Mensch
Status: open | answered
Answer: (wird beim Schließen ergänzt; Antwort wandert dann in das passende Doc)
```

---

## 📋 Tasks

### Vorlage

```
### taskXX Titel des Tasks
Status:    open | in_progress | done
Feature:   featXX
Priorität: P0 | P1 | P2 | P3
Linked:    bugXX (optional), qXX (optional)

**Ziel**
Was soll am Ende stehen?

**Schritte**
1. ...
2. ...

**Erwartetes Ergebnis**
Wie erkennt man Fertigstellung?

**Done-Checkliste** (vor Verschieben nach ✅ Done)
- [ ] 01-features.md aktualisiert (falls Verhalten geändert)
- [ ] 02-user-doc.md aktualisiert (falls UX geändert)
- [ ] 03-dev-doc.md aktualisiert (immer bei Code-Änderung)
- [ ] testXX in 05-quality.md grün
- [ ] PO Sign-off
```

### Prioritäten

| Stufe | Bedeutung |
|---|---|
| **P0** | Blocker — nichts anderes kann sinnvoll vorangehen |
| **P1** | Kern-Feature, geplante Iteration |
| **P2** | wichtig, aber nicht blockierend |
| **P3** | nice-to-have, Backlog |

### task50 LernHive Copilot-Findings aus geschlossenen PRs triagieren
Status:    open
Feature:   feat50
Priorität: P1
Linked:    test50

**Ziel**
Die offenen Punkte aus den Copilot-Kommentaren der geschlossenen
`jmoskaliuk/lernhive` PRs werden nachvollziehbar triagiert und in konkrete
Fix-PRs überführt. Der Audit lief am 2026-06-23 gegen den aktuellen
`main`-Stand des LernHive-Repos.

**Findings**
1. `local_lernhive_certify`: Cohort-Observer liest die Cohort-ID weiterhin aus
   `$event->other['cohortid']`; Moodle Cohort-Events sollten `$event->objectid`
   verwenden. Regressionstest ergänzen.
2. `local_lernhive_certify`: Cohort-Observer ruft `source_cohort_service`
   direkt auf; der Service fragt Source-Cohort-Tabellen ohne
   Install-/Upgrade-Guard ab. Table-Guard oder Observer-`try/catch` ergänzen.
3. `local_lernhive_pathways`: Collaboration-Activity-Lookup nutzt
   `get_coursemodule_from_id('', $cmid, ...)`; leeren Modulnamen durch robusten
   Lookup ersetzen.
4. `local_lernhive_orgchart`: `tools.php` setzt die Page-URL auf `manage.php`,
   und der aktive Section-Key `frameworks` hat keinen passenden
   `section_nav`-Eintrag. URL, Settings-Link und Nav-Item angleichen.
5. `local_lernhive`: Hook-Callback enthält unerreichbare
   Nicht-LernHive-Theme-Branches nach frühem Theme-Return; günstige Exits vor
   DB-Lookup ziehen und Dead Code entfernen.
6. `local_lernhive_certify` / Produktdocs: Code steht bei Release `0.5.18`,
   Produkt- und RFP-Dokumente nennen noch ältere `0.3.4`-Shipping-Stände.
   `product/02-plugin-map.md`, `product/12-feature-catalogue.md` und
   `product/webseite/rfp-features.md` synchronisieren.
7. `format_lernhive_community` und `format_lernhive_snack`: mindestens die
   englischen Lang-Files haben keinen `defined('MOODLE_INTERNAL') || die();`
   Guard. Lang-Files prüfen und Guards ergänzen.
8. `format_lernhive_community` und `format_lernhive_snack`: `version.php`
   referenziert Moodle 4.4, während die LernHive-Baseline offenbar 4.5+ ist.
   Supported baseline klären und Requires-Werte angleichen.
9. `local_lernhive_pathways`: Help-Strings verwenden `<number>` / `<Nummer>`;
   Moodle-Output kann das als HTML-ähnliche Tags behandeln. Beispiele auf
   konkrete Werte wie `id=123` ändern.

**Bereits geprüft**
- `local_lernhive_reporting` PR #180 wirkt im aktuellen Code weitgehend
  erledigt: suspendierte Nutzer werden ausgeschlossen, Course-Scoping ist
  vorhanden und Recordsets werden mit `finally` geschlossen.
- Die Course-Format-Scaffolds enthalten inzwischen `lib.php`, Privacy Provider
  und Tests; offen bleiben vor allem Guards, Requires-Metadaten und finale
  Format-Verifikation.

**Schritte**
1. Aus den P1-Findings separate Fix-PRs für Certify und Pathways erstellen.
2. P2-Findings für Orgchart, Hook-Cleanup und Certify-Doku in einem oder zwei
   kleinen PRs nachziehen.
3. P3-Findings als Konventions-/Metadaten-Cleanup bündeln.
4. Nach jedem PR PHP-Lint, relevante PHPUnit/Behat-Tests und GitHub Checks
   dokumentieren.

**Erwartetes Ergebnis**
Alle offenen Copilot-Reviewpunkte sind entweder gefixt, bewusst verworfen oder
als niedrig priorisierte Folgearbeit dokumentiert. Die wichtigsten Laufzeit-
und Upgrade-Risiken in Certify und Pathways sind behoben.

**Done-Checkliste**
- [ ] P1-Findings behoben oder begründet verworfen
- [ ] P2-Findings behoben oder in eigene Tasks ausgelagert
- [ ] P3-Findings behoben oder als Cleanup-Backlog bestätigt
- [x] test50 in 05-quality.md ergänzt
- [ ] PO Sign-off

---

### task52 moodle_find_user admin-aware Discovery
Status:    done
Feature:   webservice_elediamcp
Priorität: P2
Linked:    bug45, q01, review55

**Ergebnis (2026-06-27)**
Admin-aware Fallback + Mehrwort-Namensmatching umgesetzt; nicht-sendbare User
bleiben gefiltert (q01). `php -l` grün, Version `2026061204`. PHPUnit/Live-Smoke
offen (CI).


**Ziel**
Der Discovery-Pfad (`moodle_find_user`) ist konsistent zum Action-Pfad
(`moodle_send_message`): Was per ID sendbar ist, ist auch per Namenssuche
auffindbar — ohne Sichtbarkeits-Regression für nicht-privilegierte Token.

**Schritte**
1. Admin-Fallback: Wenn Token-User `is_siteadmin` ist bzw.
   `moodle/site:sendmessage` hält, statt `message_search_users()` direkt über
   `core_user_get_users_by_field` / `{user}`-Suche auflösen.
2. Mehrwort-Namensmatching gegen `firstname`/`lastname` bzw.
   `CONCAT(firstname,' ',lastname)`.
3. Optional `messageable`-Flag pro Treffer statt Unterdrückung (siehe q01).
4. Negative-/Regressionstests: nicht-privilegierte Token sehen weiterhin nur
   erlaubte User.

**Erwartetes Ergebnis**
`moodle_find_user(query="Paul"|"Maier"|"Paul Maier")` findet User 72, wenn der
Token-User Site-Sende-Rechte hat. AKs siehe bug45.

**Done-Checkliste**
- [ ] 01-features.md aktualisiert (falls Verhalten geändert)
- [ ] 03-dev-doc.md aktualisiert
- [ ] testXX in 05-quality.md grün
- [ ] PO Sign-off

### task53 moodle_list_courses / Katalog-Browsing
Status:    done
Feature:   webservice_elediamcp
Priorität: P2
Linked:    bug46, q02, review55

**Ergebnis (2026-06-27)**
Statt separatem Tool: `scope`-Parameter (`catalogue`|`enrolled`) + optionale
Query (leer = Browsen) in `moodle_search_courses`; `enrolled` auf
`moodle_my_courses` konsolidiert (q02). `php -l` grün, Version `2026061204`.
PHPUnit/Live-Smoke offen (CI).


**Ziel**
„Alle Kurse auflisten/zählen" (Enumeration) wird abgebildet, ohne die Such-API
zu missbrauchen. `moodle_search_courses` bleibt für echte Suche und nutzt bereits
korrekt `core_course_category::search_courses()`.

**Schritte**
1. Neues Tool `moodle_list_courses`: paginiertes Katalog-Browsing ohne Query
   (z. B. `core_course_category::top()->get_courses(['recursive'=>true,…])`),
   Front-Page-Kurs (id 1) ausgeschlossen, Capability-Sichtbarkeit (Admin sieht
   versteckte, Teilnehmer nur freigegebene).
2. Optional `scope`-Parameter (`catalogue` | `enrolled`) in
   `moodle_search_courses`; ggf. `enrolled` mit `moodle_my_courses` konsolidieren
   (siehe q02).
3. Tool-Beschreibungen schärfen: Suchen vs. Browsen für das LLM klar trennen.
4. Tests gemäß Handover-Checkliste (Admin ohne Einschreibung sieht alle 3 Kurse,
   versteckter Kurs/Kategorie, Front-Page nie, Pagination, Gastkontext).

**Erwartetes Ergebnis**
Admin ohne Einschreibung erhält über das List-Tool alle sichtbaren Kurse;
search liefert weiterhin nur echte Keyword-Treffer.

**Done-Checkliste**
- [ ] 01-features.md aktualisiert
- [ ] 02-user-doc.md aktualisiert (falls UX geändert)
- [ ] 03-dev-doc.md aktualisiert
- [ ] testXX in 05-quality.md grün
- [ ] PO Sign-off

---

## 🔧 In Progress

Tasks, an denen aktuell gearbeitet wird. Hier landen Tasks, sobald die KI mit `#implement` startet.

### task45 Pathways notification failures must not block demo/source cleanup
Status:    in_progress
Feature:   feat45
Priorität: P0
Linked:    bug44, test45

**Ziel**
Pathways demo-data creation and cohort/source cleanup must continue even when
Moodle's email message processor fails during allocation/deallocation
notifications.

**Schritte**
1. Route all Pathways `message_send()` calls through one fail-closed dispatcher.
2. Stop notification observers from logging via developer `debugging()` in
   catch blocks that run inside lifecycle events.
3. Add regression tests for sender failure and deallocation survival.
4. Bump Pathways to `2026050307 / 1.0.13`.
5. Run syntax/style checks, open PR and merge the hotfix.

**Erwartetes Ergebnis**
The demo provider no longer fails with `Error calling message processor email`
during cohort unbind/deallocation; the notification failure is logged and the
assignment cleanup continues.

**Aktueller Stand**
- Implemented in branch `codex/pathways-notification-nonblocking`.
- PR #111 merged on 2026-05-03: https://github.com/jmoskaliuk/lernhive/pull/111
- Merge commit: `e7171b85faf4a10b62140398646e7d0aff29a827`
- PR #112 merged on 2026-05-03: https://github.com/jmoskaliuk/lernhive/pull/112
- Help-string blocker fixed in `2026050308 / 1.0.14`.
- Local runtime synced to `../runtime/moodle52/moodle/public/local/lernhive_pathways/`.
- GitHub check `Verify plugin UI boundary` passed.
- PHPUnit is pending because local Docker/OrbStack is not reachable.

**Done-Checkliste** (vor Verschieben nach ✅ Done)
- [x] 01-features.md aktualisiert
- [ ] 02-user-doc.md aktualisiert (nicht erforderlich, keine UX-Änderung)
- [x] 03-dev-doc.md aktualisiert
- [ ] test45 in 05-quality.md grün
- [ ] PO Sign-off

---

## 🔎 Verifikation nach Deploy

Verifikations-Items für die letzte Auslieferung. Nach erfolgreicher Verifikation → in den jeweiligen Task „PO Sign-off" abhaken und nach „✅ Done" verschieben.

### verify45 Pathways demo provider after notification hotfix
Linked: task45, bug44, test45
Status: pending

**Schritte**
1. Deploy current `main` including PR #111, #112 and #113.
2. Moodle upgrade for `local_lernhive_pathways` auf `1.0.14` ausführen.
3. Pathways Demo-Daten-Provider starten.
4. Cohort unbind / Demo cleanup ausführen.
5. Pathway edit form öffnen and verify no missing help-string debugging occurs.

**Erwartet**
Keine Exception aus `on_allocation_deallocated_notify`; ein fehlerhafter
E-Mail-Processor blockiert die Demo-Daten nicht mehr. Die Pathway-Bearbeitung
scheitert nicht mehr an `form_scheduling_dsl`.

### task46 Pathways catalogue detail shows included courses/steps
Status:    done
Feature:   feat46
Priorität: P0
Linked:    test46

**Ziel**
Learners opening a Pathway in the catalogue must see the ordered courses/steps
that make up the Pathway before deciding whether to start/request it.

**Schritte**
1. Load ordered steps on `catalogue/pathway.php`.
2. Resolve Moodle course steps and link them to `course/view.php`.
3. Show required/optional badges and an empty-state for Pathways without steps.
4. Keep the repo-wide UI boundary check green.

**Aktueller Stand**
- PR #113 merged on 2026-05-03: https://github.com/jmoskaliuk/lernhive/pull/113
- Merge commit: `626703e535f37712e55e8b81c43e171ac86a933a`
- GitHub check `Verify plugin UI boundary` passed.
- Local runtime synced to `../runtime/moodle52/moodle/public/local/lernhive_pathways/`.
- PR #114 added Behat coverage for catalogue detail, empty-state and edit-form rendering.
- PR #123 corrected the edit-form Behat assertion to check the submit button semantically.
- GitHub Actions run `25281212353` passed for `@local_lernhive_pathways` on 2026-05-03.

**Done-Checkliste** (vor Verschieben nach ✅ Done)
- [x] 01-features.md aktualisiert
- [ ] 02-user-doc.md aktualisiert
- [x] 03-dev-doc.md aktualisiert
- [x] test46 in 05-quality.md grün
- [ ] PO Sign-off

---

## ✅ Done

Erledigte Tasks. Nicht löschen — sie sind die Historie der Entscheidungen.

### task51 webservice_elediamcp Review-Fixes Runde 2
Status:    done
Feature:   webservice_elediamcp
Priorität: P1
Linked:    review54

**Ziel**
Die in der zweiten Review-Runde gefundenen Befunde sind behoben.

**Ergebnis**
- H-1 (`moodle_me` E-Mail), M-1 (`search_content` Snippet-Escaping),
  M-2 (`create_user` nur aktivierte Auth-Plugins), N-1, N-3, N-4, N-5, N-6
  umgesetzt.
- H-2 als Admin-Setting `enforce_mcp_service` (Default an) implementiert:
  `server::authenticate_user()`-Override prüft, dass das Token zu einem
  konfigurierten MCP-Service gehört.
- Version `2026061203`, CHANGELOG `1.0.1`.
- `php -l` für alle geänderten Dateien grün, `git diff --check` sauber.
- PHPUnit/Behat: offen (keine lokale Moodle-PHPUnit-Umgebung) → in CI nachziehen.

**Folgearbeit (alle erledigt)**: bug45/task52 (find_user) und bug46/task53
(search_courses scope/browse) → review55; M-3 (Rate-Limiter atomar via MUC-Lock)
und N-2 (Announcements `uservisible`/Gruppen) → review56. Offen bleibt nur die
PHPUnit/Behat-Ausführung in der CI.

**Done-Checkliste**
- [x] 03-dev-doc.md/Review-Protokoll aktualisiert (review54)
- [x] testXX/Review in 05-quality.md ergänzt
- [ ] PO Sign-off

### task47 Customer Portal billing-event history
Status:    done
Feature:   feat47
Priorität: P1
Linked:    test47

**Ziel**
Customers can see the status of billing events triggered in Moodle without
clicking a manual refresh button.

**Schritte**
1. Extend `local_customerportal\local\odoo_billing_service` with the
   `/billing-event-history` read call.
2. Add `/local/customerportal/billing-events.php`.
3. Render the history in a Customer Portal Mustache template using existing
   table/card/badge patterns.
4. Add dashboard link, language strings and PHPUnit mapping coverage.
5. Keep Odoo code and status mapping unchanged.

**Erwartetes Ergebnis**
The dashboard links to a request-history page that refreshes from Odoo on page
load and renders `pending`, `in_review`, `scheduled`, and `confirmed` statuses
with customer-facing labels.

**Aktueller Stand**
- Implemented in repo commit `1f8cd317` on 2026-05-10.
- Moodle plugin bumped to `local_customerportal` `2026051002 / 0.2.33`.
- Static checks passed: `php -l`, UI boundary lint, `git diff --check`.
- Full local PHPUnit is blocked because `playbooks/test.local.env` is missing.

**Done-Checkliste**
- [x] 01-features.md aktualisiert
- [x] 02-user-doc.md aktualisiert
- [x] 03-dev-doc.md aktualisiert
- [ ] test47 in 05-quality.md grün
- [ ] PO Sign-off

### task48 Certify status baseline documentation
Status:    done
Feature:   feat48
Priorität: P2
Linked:    test48

**Ziel**
Align Certify plugin docs and global DevFlow with the actual implementation
status so the next work can start from PDF/dispatch or learning-record UI
instead of re-triaging shipped slices.

**Schritte**
1. Review `plugins/local_lernhive_certify` docs and implementation surfaces.
2. Mark shipped/partial/pending LH-CRT slices in the plugin task backlog.
3. Update developer and quality docs for the learning-record aggregate,
   external records, and remaining gaps.
4. Add DevFlow feature/task/test references without changing Certify UX/UI code.

**Erwartetes Ergebnis**
Certify documentation clearly separates shipped core operations from pending
PDF certificate and learning-record UI work.

**Aktueller Stand**
- Documentation-only update on 2026-05-10.
- Certify plugin code and UX/UI implementation are unchanged.

**Done-Checkliste**
- [x] 01-features.md aktualisiert
- [x] 02-user-doc.md aktualisiert (nicht erforderlich, keine UX-Änderung)
- [x] 03-dev-doc.md aktualisiert
- [x] test48 in 05-quality.md erfasst
- [ ] PO Sign-off

### task49 Odoo Library editorial backend polish
Status:    done
Feature:   feat49
Priorität: P1
Linked:    test49

**Ziel**
Make `jmoskaliuk/eLeDia_Library` usable as an editorial
control plane: Library Items first, clear upload/release wording, explicit
template vs library-course type, feed tags, customer flavour segmentation and
multi-storage support.

**Schritte**
1. Add explicit `entry_type` to `eledia.library.entry`.
2. Add `eledia.library.tag` and emit active tag names in `/eledia/library/v1/feed`.
3. Add `eledia.library.flavour`, entry restrictions and token flavour
   filtering.
4. Replace global-only S3 handling with `eledia.library.storage.profile`
   while keeping legacy System Parameter fallback.
5. Wire `storage_profile_id` into releases, upload wizard, feed presign and
   download redirect.
6. Reword Odoo UI from Catalog/Versions to Library Items/Releases/Operations
   and expose `.mbz` upload from the Library Item.
7. Update Odoo Library docs and tests.

**Erwartetes Ergebnis**
Editors can maintain Library Items and upload .mbz releases from one coherent
surface. Odoo feed metadata is explicit enough for ContentHub filters, while
existing ContentHub template detection through `sourcecourseid` remains
compatible. Multiple S3-compatible backends can be configured and selected per
release.

**Aktueller Stand**
- Implemented in the separate source repository `jmoskaliuk/eLeDia_Library`.
- Static validation passed: Python compile, XML parse and `git diff --check`.
- Full Odoo test suite is pending because no local Odoo test database was run.
- Browser/UI smoke on Odoo is pending.

**Done-Checkliste**
- [x] 01-features.md aktualisiert
- [x] 02-user-doc.md aktualisiert
- [x] 03-dev-doc.md aktualisiert
- [ ] test49 in 05-quality.md grün
- [ ] PO Sign-off

---

## ✅ Geklärt

Beantwortete `qXX`. Antworten sind ins jeweilige Doc übernommen.

---

## Regeln

- Eingaben in „🆕 Neu" werden zeitnah in `taskXX` oder `qXX` umgewandelt.
- Tasks klein und ausführbar halten.
- Erledigte Items **nicht löschen**, sondern verschieben.
- Klärungen nicht im Chat versanden lassen → immer als `qXX` festhalten.
- Status-Änderungen passieren genau hier — nicht in `01-features.md`.

---

## Grundprinzip

> Was nicht hier steht, passiert nicht.

---

### task42 Teststruktur für ContentHub-Library-Pfad konsolidieren
Status: done
Linked: test42, bug42

**Beschreibung:**
Die frühere Library-Teststruktur wurde in `local_lernhive_contenthub`
konsolidiert. Copy, Template und Library sind keine separaten Plugin-DevFlows
mehr, sondern ContentHub-Pfade. Künftig muss jedes neue Feature/Modul sofort
mit Test-Skelett im owning Plugin starten.

**Ergebnis:**
- Library-PHPUnit-Tests liegen unter `plugins/local_lernhive_contenthub/tests/library/`.
- Library-Behat-Tests liegen unter `plugins/local_lernhive_contenthub/tests/behat/`.
- Alte Plugin-Ordner `local_lernhive_copy` und `local_eledia_library` sind entfernt.
- Der einzige DevFlow für Copy, Template und Library liegt beim owning Plugin
  `local_lernhive_contenthub`.

---

### task43 Lessons Learned: S3-Auth, Restore-Fehler, Odoo-FK
Status: open
Linked: bug43, test43

**Beschreibung:**
- S3 presigned URLs dürfen keinen Authorization-Header bekommen (Hetzner/CEPH: InvalidArgument)
- ContentHub-Library-Import: Moodle-Restore muss den Zielkurs sauber vor
  `restore_controller` anlegen, sonst droht `restore_check_course_not_exists`
- Odoo: Wizard-Objekte mit FK müssen vor referenzierten Versionen gelöscht werden

**ToDo:**
- Lessons in Fehlerdatenbank und Tests aufnehmen
- Testfälle für ContentHub-Library-Import/Restore-Fehler und Odoo-FK-Probleme anlegen

---

### task44 ContentHub nach Plugin-Zusammenlegung UX-durchgehen
Status: in_progress
Linked: test44

**Beschreibung:**
Nach der Zusammenlegung von Copy, Template und Library in ContentHub braucht
der gesamte ContentHub einen UX-Durchgang.

**Schwerpunkte:**
- Plugin-Shell-Breiten prüfen: Default-Shell ist breit; nur begründete Ausnahmen dokumentieren.
- Library-Karten gleich hoch halten.
- Library-Katalog mit Suchleiste und Filtern ausstatten.
- Tags/Themen als Feed-Feld nutzen (`tags`) und im Katalog filterbar machen.
- Import-Bestätigungsseite aus der nackten Moodle-Form in eine ContentHub-Fläche bringen.

**Done-Checkliste:**
- [x] Library-Karten gleiche Höhe
- [x] Library-Suche
- [x] Sprach-/Themenfilter auf Basis vorhandener Katalogdaten
- [x] Feed-Schema um `tags` ergänzt
- [x] Import-Bestätigungsseite layoutseitig geglättet
- [ ] Browser-Sichtung auf dev.lernhive.de
