# Entwickler-Dokumentation

## Meta

Dieses Dokument beschreibt, **wie das System tatsächlich implementiert ist**.

Es hat zwei Aufgaben:
1. die technische Struktur des Produkts dokumentieren
2. die Implementierung einzelner Features (`featXX`) festhalten

Dieses Dokument ist die **Quelle der Wahrheit für die Realität (Ist-Zustand)**.

---

## Verwendung

### Für Menschen

Nutze dieses Dokument, um:
- die interne Funktionsweise zu verstehen
- Architektur und Komponenten zu navigieren
- neue Entwickler:innen zu onboarden
- Debugging und Erweiterungen zu unterstützen

Denke: **Wie ist das System aufgebaut und wie funktioniert es wirklich?**

---

### Für KI

- Behandle dieses Dokument als **Quelle der Wahrheit für die Implementierung**.
- Erfinde kein Verhalten, das nicht implementiert ist.
- Beschreibe keine Wunsch-Implementierung — dafür ist `01-features.md` zuständig.
- Konsistenz prüfen mit:
  - `01-features.md` (gewünschtes Verhalten)
  - `02-user-doc.md` (sichtbares Verhalten)
- Bei Mismatch → Inkonsistenz flaggen, ggf. `qXX` anlegen.

---

## Was gehört hierher

- Architektur
- Komponenten und Module
- Datenfluss
- Komponenten-Interaktionen
- technische Constraints
- bekannte Einschränkungen

## Was gehört NICHT hierher

- Feature-Planung / Ideen → `01-features.md`
- Tasks / Work-Tracking → `04-tasks.md`
- Bugs / Test-Logs → `05-quality.md`
- Benutzererklärungen → `02-user-doc.md`
- Architektur-Entscheidungen → `00-master.md` §10 (ADR)

---

# 🧭 System-Übersicht

## Architektur

- Systemtyp (z. B. Client-only, Client-Server, Plugin in Host-System)
- Hauptebenen
- Kommunikationsmuster

---

## Kernkomponenten

- Komponente 1 → Zweck
- Komponente 2 → Zweck

---

## Datenfluss

- Eingabe → Verarbeitung → Ausgabe
- Komponenten-Interaktionen
- Event-Fluss

---

## Externe Abhängigkeiten

- Libraries (mit Versionen)
- APIs
- Services

---

## Technische Constraints

- Performance
- architektonische Grenzen
- bekannte Trade-offs

---

# 🧩 Feature-Implementierung

Pro Feature die tatsächliche Implementierung.

Alle Einträge:
- referenzieren ein `featXX`
- beschreiben den **Ist-Zustand**
- spekulieren nicht

---

### Pathways non-blocking notifications (`feat45`)

**Überblick**
Pathways notifications are implemented as non-critical side effects. Lifecycle
operations such as allocation, deallocation, approval decisions and demo/source
cleanup must continue even when Moodle message delivery fails.

---

**Architektur**

- `local_lernhive_pathways\notification\sender` is the single notification
  dispatch boundary.
- All direct `message_send()` calls in the sender go through a private
  fail-closed dispatcher.
- Notification event observers catch unexpected notification failures and log
  them without using Moodle developer `debugging()`, because developer
  debugging can itself escalate to an exception in strict environments.

---

**Komponenten**

- `classes/notification/sender.php` → builds template context, loads full
  message-compatible user records and dispatches notifications.
- `classes/observer.php` → observes Pathways lifecycle events and invokes the
  sender without allowing notification failures to abort upstream flows.
- `tests/notification_sender_test.php` → covers message-processor failure at
  the sender boundary.
- `tests/notification_observer_test.php` → covers deallocation continuing when
  message delivery fails.

---

**Datenfluss**

1. A Pathways lifecycle operation fires an event.
2. The observer calls the notification sender.
3. The sender loads users via `core_user\fields::for_name()` plus message
   recipient fields such as `username` and `emailstop`.
4. The sender calls the fail-closed dispatcher.
5. On processor failure, the dispatcher logs via `error_log()` and returns
   `false`; the lifecycle flow continues.

---

**Constraints / Einschränkungen**

- Failed delivery is logged but not retried.
- Full PHPUnit verification is pending locally while Docker/OrbStack is not
  reachable.

---

### Pathways catalogue contents (`feat46`)

**Überblick**
The catalogue detail page now renders the ordered Pathway contents before the
claim/request action block, so learners can see which courses and learning
steps belong to the Pathway before joining it.

---

**Architektur**

- `catalogue/pathway.php` loads steps via `step_service::list_for_pathway()`.
- Course-type steps collect their `courseid` values and resolve course names
  from Moodle's `{course}` table in one query.
- The page renders each step with its type label plus required/optional badge.
- Course steps link to `/course/view.php?id=...`; missing course references are
  shown as missing instead of causing a fatal error.

---

**Komponenten**

- `catalogue/pathway.php` → renders title/description, contents, and action
  block.
- `classes/service/step_service.php` → supplies ordered step rows.
- `lang/en|de/local_lernhive_pathways.php` → catalogue content labels and empty
  state strings.

---

### Customer Portal billing event history (`feat47`)

**Überblick**
The Moodle Customer Portal exposes a read-only customer-facing timeline for
Odoo billing events. It uses Odoo as the source of truth and refreshes the
history on each page load.

---

**Architektur**

- `local_customerportal\local\odoo_billing_service::get_billing_event_history()`
  posts to `/eledia/maas/v1/billing-event-history`.
- The existing `odoo_registry_client` supplies the `X-eLeDia-MaaS-Key`
  authentication header.
- The service normalises only the public response fields. Odoo internals such
  as raw payload, quote ids, invoice ids or error messages are intentionally
  ignored.
- `/local/customerportal/billing-events.php` renders the page inside the
  existing Plugin Shell.

---

**Komponenten**

- `plugins/local_customerportal/billing-events.php` → page controller.
- `templates/billing_event_history.mustache` → table, empty state and status
  badges.
- `templates/dashboard.mustache` → dashboard link to the history page.
- `classes/local/page_setup.php` → shared `url_billing_events` URL.
- `tests/odoo_billing_service_test.php` → endpoint path, payload and mapping
  regression coverage.

---

**Datenfluss**

1. Customer opens `/local/customerportal/billing-events.php`.
2. Moodle posts `{installation_id, limit}` to Odoo.
3. Odoo returns up to 50 customer-facing billing-event rows.
4. Moodle maps event type/status labels and renders the table.
5. On missing config or temporary Odoo failure, the page renders with a warning
   and empty history state.

---

**Constraints / Einschränkungen**

- No Odoo addon code is changed by this feature.
- Status values are fixed to `pending`, `in_review`, `scheduled`, `confirmed`.
- The first UI cut shows `effective_date` as its own table column when Odoo
  provides it.
- Full PHPUnit execution is pending locally because `playbooks/test.local.env`
  is missing.

---

## Feature-Vorlage

---

### [Feature-Name] (`featXX`)

**Überblick**
Kurze Beschreibung der Implementierung.

---

**Architektur**
Wie das Feature im System sitzt:
- beteiligte Komponenten
- Kommunikationsmuster

---

**Komponenten**

- Komponente A → Rolle
- Komponente B → Rolle

---

**Datenfluss**

- Trigger → Verarbeitung → Ergebnis

---

**State Management** (falls relevant)

- wo der Zustand liegt
- wie er sich ändert

---

**Abhängigkeiten**

- intern (andere Features, Module)
- extern (APIs, Libraries)

---

**Constraints / Einschränkungen**

- bekannte Probleme
- technische Grenzen
- Edge-Case-Verhalten

---

**Notizen** (optional)

- erwähnenswerte Implementierungsdetails
- ungewöhnliche Entscheidungen (Verweis auf `adrXX`, falls relevant)

---

# 📏 Regeln

- Beschreibe immer den **Ist-Zustand**, nicht den Soll-Zustand.
- Präzise und technisch.
- Feature-Beschreibungen nicht duplizieren — dafür ist `01-features.md` da.
- Aktualisieren, sobald sich die Implementierung ändert (sonst nicht „done").

---

# 🔑 Grundprinzip

> Dieses Dokument erklärt, **wie das System gebaut ist** — nicht **wie es sich verhalten soll**.
