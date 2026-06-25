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

## Regeln

- Jeder Bug bekommt eine Severity — auch S4 ist eine Severity.
- Jeder Test referenziert ein Akzeptanzkriterium. Ein Test ohne `featXX.ACyy`-Verweis ist verdächtig.
- Reproduzierbarkeit hat Vorrang vor Vermutung.
- Fixed-Status nur, wenn der zugehörige `testXX` grün ist.

---

## Grundprinzip

> Bugs sind Teil des normalen Loops, keine Ausnahme.
> Ein Test ohne Akzeptanzkriterium prüft nichts Verbindliches.
