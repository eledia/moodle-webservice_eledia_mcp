# Features

## Meta

Dieses Dokument definiert, **was das Produkt tun soll**.

Es enthält:
- Feature-Definitionen (`featXX`)
- gewünschtes Verhalten und Akzeptanzkriterien
- Produktentscheidungen
- Releases (`relXX`)

Dieses Dokument ist die **Quelle der Wahrheit für gewünschtes Verhalten**.

---

## Verwendung

### Für Menschen

Nutze dieses Dokument, um:
- neue Features vor der Implementierung zu definieren
- Verhalten während der Entwicklung zu klären
- Entscheidungen und Constraints festzuhalten
- ein gemeinsames Produktverständnis sicherzustellen

Hier wird gedacht: **Was soll das Produkt tun und warum?**

---

### Für KI

- Behandle dieses Dokument als **Quelle der Wahrheit für erwartetes Verhalten**.
- Erfinde kein Verhalten, das hier nicht definiert ist.
- Bei Unklarheit → **keine Annahme**, sondern Klärung als `qXX` in `04-tasks.md`.
- Implementierung und Doku müssen mit diesem Dokument konsistent sein.

---

## Was gehört hierher

- Feature-Definitionen (`featXX`)
- Ziele und Zweck
- erwartetes Verhalten inklusive Edge Cases
- **Akzeptanzkriterien** (Given/When/Then)
- Non-Goals (explizite Ausschlüsse)
- Design-Entscheidungen
- Releases (`relXX`) als Bündel von Features

## Was gehört NICHT hierher

- Tasks → `04-tasks.md`
- Implementierungsdetails → `03-dev-doc.md`
- Bugs / Test-Ergebnisse → `05-quality.md`
- Bedienungsanleitungen → `02-user-doc.md`
- Architektur-Entscheidungen → `00-master.md` §10 (ADR)

---

## Produkt-Übersicht

### Zweck
Beschreibt das übergreifende Ziel des Systems.

### Kernkonzepte
Die zentralen Bausteine.

### Hauptfunktionen
High-Level-Übersicht und ihre Beziehungen zueinander.

### Constraints
Technische, geschäftliche oder konzeptionelle Grenzen.

---

## Features

---

### feat45 Pathways notifications are non-blocking

**Ziel**
Pathways lifecycle flows such as demo-data creation, cohort/source unbind,
allocation and deallocation must not fail because Moodle message delivery or
the email processor is misconfigured.

---

**Verhalten**

- Notifications are treated as side effects of lifecycle events.
- If a message processor throws or returns failure, Pathways logs the delivery
  problem and continues the allocation/deallocation/request flow.
- Missing or partial Moodle user fields must not trigger developer-debugging
  exceptions inside notification rendering or `message_send()`.

---

**Akzeptanzkriterien**

- feat45.AC01
  Given:  A Pathways assignment is deallocated while the Moodle email processor fails
  When:   The deallocation event observer sends the learner notification
  Then:   The assignment is still cancelled and the notification failure does not bubble up

- feat45.AC02
  Given:  Pathways sends a notification to a Moodle user record
  When:   `fullname()` or Moodle messaging requires optional name/message fields
  Then:   The sender loads a message-compatible user record and does not trigger missing-field debugging exceptions

---

**Non-Goals**

- Pathways does not repair site-wide mail configuration.
- Failed notification delivery is not retried by this hotfix.

---

### feat46 Pathways catalogue shows learning contents

**Ziel**
The catalogue detail page must make it obvious that a Pathway is an ordered
learning path made of courses and other learning steps, not just a title and a
self-enrol button.

---

**Verhalten**

- The Pathway catalogue detail page shows the ordered steps that belong to the
  Pathway.
- Moodle course steps link directly to their course pages.
- Required and optional steps are visually distinguished.
- Empty Pathways show an explicit empty state instead of looking broken.

---

**Akzeptanzkriterien**

- feat46.AC01
  Given:  A visible Pathway contains Moodle course steps
  When:   A learner opens the catalogue detail page
  Then:   The courses are listed in Pathway order before the start/request action

- feat46.AC02
  Given:  A visible Pathway has no steps
  When:   A learner opens the catalogue detail page
  Then:   The page clearly states that no visible steps or courses exist yet

---

### feat47 Customer Portal billing event history

**Ziel**
Customers must be able to see the current customer-facing status of purchase
and change requests they triggered from the Moodle Customer Portal.

---

**Verhalten**

- The Customer Portal shows a request history for storage, capacity, and add-on
  billing events.
- The history is refreshed from Odoo whenever the page is opened.
- Only customer-facing fields are shown: request date, event type, product or
  add-on description, effective date when available, and status.
- The status vocabulary is exactly `pending`, `in_review`, `scheduled`, and
  `confirmed`.
- Odoo internals such as raw payload, error messages, quotations, invoices, or
  internal object ids are not displayed.

---

**Akzeptanzkriterien**

- feat47.AC01
  Given:  A customer has triggered billing events in the Customer Portal
  When:   The customer opens the billing-event history page
  Then:   The page lists the events with date, type, description, effective date if present, and a customer-facing status badge

- feat47.AC02
  Given:  Odoo returns no billing events for the installation
  When:   The customer opens the billing-event history page
  Then:   The page shows an explicit empty state instead of an empty table

- feat47.AC03
  Given:  Odoo is temporarily unavailable
  When:   The customer opens the billing-event history page
  Then:   The page stays renderable and shows a customer-friendly unavailable hint

---

**Non-Goals**

- No active polling, websocket, edit, or cancel flow.
- No "current subscription plan" surface.
- No Moodle-side changes to Odoo's customer-facing status mapping.

---

### feat48 Certify delivery baseline is explicit

**Ziel**
The Certify plugin status must be explicit enough that the next agent can pick
the right continuation point without re-discovering shipped and pending slices.

---

**Verhalten**

- Certify documentation states which core certification surfaces are already
  implemented.
- Shipped slices include certification templates, assignment/period lifecycle,
  recertification, cohort source, approval requests, catalogue self-assignment,
  history import, Report Builder, read-only webservices, custom fields, external
  record model, and the learning-record aggregate service.
- Remaining major gaps are named as PDF rendering/dispatch/archive, learner and
  manager learning-record UI, and external-record privacy/file export-delete
  hardening.
- The UX/UI implementation remains unchanged during this documentation pass.

---

**Akzeptanzkriterien**

- feat48.AC01
  Given:  A developer opens the Certify plugin docs
  When:   They review feature, task, developer, and quality docs
  Then:   They can distinguish shipped Certify slices from the remaining major gaps

- feat48.AC02
  Given:  The Certify docs are updated
  When:   The change is reviewed
  Then:   No customer-facing UX/UI implementation is changed by the documentation pass

---

**Non-Goals**

- No implementation of PDF rendering or learning-record UI in this task.
- No redesign of existing Certify Moodle pages.

---

### feat49 Odoo Library editorial backend is production-shaped

**Ziel**
The Odoo Library addon must support a practical editorial workflow for
maintaining LernHive Library items, releases, feed tags, customer segmentation
and multiple S3-compatible storage backends.

---

**Verhalten**

- Editors work primarily from Library Items, not from a split Catalog/Versions
  mental model.
- Releases remain the underlying version model but are shown as release history
  on the Library Item and moved to an Operations menu for managers.
- The .mbz upload action is visible from the Library Item and uses editorial
  wording: upload file, version, notes, publish.
- Each entry has an explicit `entry_type` of `library_course` or `template`.
- Template entries require a `sourcecourseid` so existing ContentHub consumers
  continue to recognise copy-template handoff without a breaking feed change.
- Entries can carry editorial tags; the feed emits active tag names as `tags`.
- Entries can be restricted by customer flavour; global entries remain visible
  to every valid token.
- Multiple S3-compatible storage profiles can be configured inside the addon.
- Each release can point at a storage profile; missing profile falls back to the
  legacy global S3 system parameters.
- Managers can test a storage profile connection from the plugin UI.

---

**Akzeptanzkriterien**

- feat49.AC01
  Given:  An editor opens the Odoo Library addon
  When:   They manage course content
  Then:   The primary surface is Library Items with upload and release history,
          while Releases are treated as an operational view

- feat49.AC02
  Given:  A Library Item has entry type, tags and customer flavours
  When:   `/eledia/library/v1/feed` is requested with a valid token
  Then:   The feed emits `entry_type`, active `tags`, and only entries allowed
          for the token's flavour

- feat49.AC03
  Given:  Multiple storage profiles are configured
  When:   A release is uploaded or a download URL is signed
  Then:   Odoo uses the release's selected storage profile and falls back to
          legacy global S3 parameters only when no profile is set

- feat49.AC04
  Given:  An entry is marked as `template`
  When:   It is saved
  Then:   Odoo requires a positive `sourcecourseid` to preserve ContentHub's
          existing template-detection contract

---

**Non-Goals**

- No active webhook from Odoo to customer Moodle in this slice.
- No breaking change to ContentHub's feed parser or template detection.
- No migration of existing object files between buckets.

---

### feat50 LernHive closed-PR review debt is visible and actionable

**Ziel**
Findings from closed GitHub PR review comments must not remain hidden in old
Copilot threads. Runtime, upgrade and documentation risks discovered during the
2026-06-23 audit should be visible in DevFlow and actionable through small
follow-up PRs.

---

**Verhalten**

- Copilot findings from closed `jmoskaliuk/lernhive` PRs are recorded as a
  DevFlow task.
- High-priority runtime and upgrade risks are separated from convention and
  metadata cleanup.
- Each finding has an expected next action: fix, split into a narrower task,
  or explicitly mark as wontfix.
- Verification requires code checks for implementation PRs, not only a
  documentation entry.

---

**Akzeptanzkriterien**

- feat50.AC01
  Given:  A closed-PR Copilot audit finds unresolved P1 issues
  When:   The audit is written into DevFlow
  Then:   Each P1 issue has a concrete affected plugin, risk and next action

- feat50.AC02
  Given:  Lower-priority review findings exist
  When:   They are triaged
  Then:   They are either fixed, split into follow-up tasks or explicitly parked

- feat50.AC03
  Given:  A follow-up PR claims to resolve a finding
  When:   It is reviewed
  Then:   Relevant lint, PHPUnit/Behat coverage where available, `git diff --check`
          and GitHub checks are documented

---

**Non-Goals**

- No product UX change is implied by documenting the audit.
- No automatic acceptance of Copilot suggestions without human/code review.
- No release is triggered by this documentation entry alone.

---

### featXX [Feature-Name]

**Ziel**
Warum existiert dieses Feature? Welches Problem löst es?

---

**Verhalten**
Was genau soll passieren?

- Hauptfluss beschreiben
- Edge Cases einschließen
- erwartete System-Reaktionen definieren

---

**Akzeptanzkriterien**

Format: Given / When / Then. Jedes Kriterium hat eine ID `featXX.ACyy` und wird in `05-quality.md` von `testXX` referenziert.

```
- featXX.AC01
  Given:  <Ausgangszustand>
  When:   <Aktion>
  Then:   <erwartetes Ergebnis>

- featXX.AC02
  Given:  ...
  When:   ...
  Then:   ...
```

Faustregel: Ein Akzeptanzkriterium ist erfüllt, wenn ein Außenstehender mit der Beschreibung allein verifizieren kann, ob das System es erfüllt.

---

**Non-Goals**

- explizit ausgeschlossen
- verhindert Scope Creep

---

**Entscheidungen**

Wichtige produktbezogene Design-Entscheidungen:
- gewählter Ansatz und Begründung
- verworfene Alternativen (optional)

(Architektur-Entscheidungen → ADR im Master-Dokument.)

---

**Offene Fragen** (optional)

Nur eintragen, wenn Klärung nötig ist. Verweis auf `qXX` in `04-tasks.md`.

---

## Regeln

- Jedes Feature hat eine eindeutige ID (`featXX`).
- Beschreibungen präzise und unzweideutig halten.
- Keine Implementierungsdetails.
- Bei Verhaltensänderung dieses Dokument aktualisieren — sonst ist das Feature nicht „done".

---

## Grundprinzip

> Dieses Dokument definiert, **was passieren soll** — nicht **wie es implementiert ist**.

---

## 📦 Releases

Releases bündeln eine Menge fertiger Features zu einem versionierten Stand.

### Konvention

- ID-Format: `relXX` (oder semantisch `R1.2`, `R1.3`, …)
- Ein Release ist erst freigegeben, wenn **alle enthaltenen Features den Done-Kriterien aus `00-master.md` §6** entsprechen.
- Release-Freigabe ist eine Mensch-only-Befugnis (PO).
- Nach Freigabe: Tag im Repo (`vX.Y`), Eintrag im jeweiligen `Playbooks/`-Dokument für Release-Mechanik.

### Release-Vorlage

```
### relXX RX.Y

- **Datum:** YYYY-MM-DD
- **Status:** geplant | in Arbeit | freigegeben
- **Enthaltene Features:** featAA, featBB, featCC
- **Bekannte Einschränkungen:** offene bugXX (Severity ≤ S3, dokumentiert in 05-quality.md)
- **Migrations-Hinweis:** falls Schema-/Konfig-Änderung
- **Release-Notes:** kurzer Klartext für Anwender (verlinkt nach 02-user-doc.md)
```

### Aktive Releases

(noch keine — sobald das erste konkrete Projekt darauf läuft, wandert es hierher.)

---
