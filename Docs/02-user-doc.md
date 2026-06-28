# Benutzer-Dokumentation

## Meta

Dieses Dokument beschreibt, **wie Benutzer mit dem Produkt interagieren**.

Es hat zwei Aufgaben:
1. das Produkt aus Benutzersicht erklären
2. die Bedienung einzelner Features (`featXX`) dokumentieren

Dieses Dokument ist die **Quelle der Wahrheit für die Benutzererfahrung**.

---

## Verwendung

### Für Menschen

Nutze dieses Dokument, um:
- die Interaktion mit dem System zu beschreiben
- Features verständlich und bedienbar zu halten
- Flows, Schritte und erwartete Ergebnisse festzuhalten
- Usability unabhängig von der Implementierung zu validieren

Denke: **Wie erlebt ein Benutzer dieses Produkt?**

---

### Für KI

- Behandle dieses Dokument als **Quelle der Wahrheit für sichtbares Verhalten**.
- Keine technischen Erklärungen.
- Konsistent mit `01-features.md` und `03-dev-doc.md`.
- Bei Unklarheit → `qXX` in `04-tasks.md`.

---

## Was gehört hierher

- Benutzer-Flows
- Schritt-für-Schritt-Interaktionen
- erwartete Ergebnisse
- Constraints aus Benutzersicht
- Anwendungsbeispiele

## Was gehört NICHT hierher

- Implementierungsdetails → `03-dev-doc.md`
- interne Logik / Architektur
- Tasks oder Planung → `04-tasks.md`
- Bugs / Test-Logs → `05-quality.md`

---

# Produkt-Nutzung — Übersicht

## Zielgruppen
Wer sind die Hauptnutzer?

- Nutzertyp 1
- Nutzertyp 2

---

## Haupt-Use-Cases
Was wollen Nutzer typischerweise erreichen?

- Use-Case 1
- Use-Case 2
- Use-Case 3

---

## Typischer Workflow

End-to-End-Beispielfluss:

1. Nutzer startet
2. Nutzer führt Aktion aus
3. System reagiert
4. Nutzer fährt fort
5. Ergebnis

---

## Kernkonzepte (Benutzerperspektive)

Wichtige Begriffe in einfacher Sprache.

- Konzept 1
- Konzept 2

---

# Feature-Bedienung

Pro Feature: wie der Benutzer damit arbeitet.

Alle Einträge:
- referenzieren ein `featXX`
- fokussieren auf Bedienbarkeit
- vermeiden technische Details

---

### Customer Portal Anfrageverlauf (`feat47`)

**Was tut es?**
Der Anfrageverlauf zeigt Kundinnen und Kunden, welchen Stand ihre Kauf- und
Aenderungsanfragen haben. Dazu gehoeren Speichererweiterungen, Wechsel der
Kapazitaetsstufe und Add-on-Anfragen.

---

**Wann nutze ich es?**
Wenn nach dem Ausloesen einer Anfrage sichtbar sein soll, ob die Anfrage noch
offen ist, geprueft wird, geplant ist oder bereits bestaetigt wurde.

---

**Bedienung**

1. Customer Portal oeffnen.
2. Auf dem Dashboard die Kachel "Request history" / "Anfrageverlauf" oeffnen.
3. Die Liste der bisherigen Anfragen pruefen.

---

**Erwartetes Ergebnis**

Jede Anfrage erscheint als Tabellenzeile mit Datum, Anfragetyp, Beschreibung,
optionalem Wirksamkeitsdatum und Status-Badge.

---

**Einschraenkungen / Hinweise**

- Die Seite aktualisiert sich beim Oeffnen neu aus Odoo.
- Es gibt keinen "Jetzt pruefen"-Button und kein Live-Polling.
- Eine Anfrage kann in Moodle nicht zurueckgezogen oder bearbeitet werden.

---

## Feature-Vorlage

---

### [Feature-Name] (`featXX`)

**Was tut es?**
Feature in einfachen Worten beschreiben.

---

**Wann nutze ich es?**
Kontext oder Anwendungsfall erklären.

---

**Bedienung**

Schritt für Schritt:

1. Schritt
2. Schritt
3. Schritt

---

**Erwartetes Ergebnis**

Was soll nach der Nutzung passieren?

---

**Beispiele** (optional)

Realistisches Anwendungsbeispiel.

---

**Einschränkungen / Hinweise**

- Constraints
- für den Nutzer relevante Edge Cases

---

**Häufige Fehler** (optional)

- Fehler 1
- Fehler 2

---

# Regeln

- Jedes Feature referenziert ein `featXX`.
- Sprache einfach und nutzerorientiert.
- Keine Fachterminologie.
- Anweisungen sind handlungsorientiert.
- Aktualisierung, sobald sich das sichtbare Verhalten ändert (sonst nicht „done").

---

# Grundprinzip

> Dieses Dokument erklärt, **wie sich das Produkt für den Nutzer anfühlt** — nicht **wie es gebaut ist**.
