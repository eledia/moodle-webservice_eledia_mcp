# eLeDia.OS — Master

> Dies ist der zentrale Eintrittspunkt. Jede Session startet hier.

---

## 1. Projekt-Meta

- **Name:** eLeDia.OS_DevFlow
- **Ziel:** Strukturierter „Operating System"-Rahmen für KI-gestützte Softwareentwicklung — definiert, wie Idee, Implementierung, Dokumentation und Qualität konsistent zusammenwirken, sodass Mensch und KI ohne Chaos kollaborieren können.
- **Kurzbeschreibung:** Minimales aber striktes Doku-Schema (fünf Perspektiven), feste Rollen für Mensch und KI, prüfbare „Definition of Done" und ein operativer Loop aus Idee → Feature → Task → Implementierung → Test → Doku-Sync.
- **Tech Stack:** Markdown-only Framework (kein Code). Wird in konkreten Projekten als Substrat verwendet (z. B. eLeDia-Moodle-Plugins).
- **Repository:** [github.com/jmoskaliuk/eLeDia.OS_DevFlow](https://github.com/jmoskaliuk/eLeDia.OS_DevFlow)

---

## 2. Session-Start (für KI)

1. Dieses Dokument vollständig lesen
2. `04-tasks.md` lesen — hier ist der operative Stand
3. Offene Tasks (`taskXX`) und offene Klärungen (`qXX`) identifizieren
4. Relevante Features in `01-features.md` lesen
5. Bei Moodle-Themen: `Skills/moodle-framework.md` konsultieren (siehe §11)
6. Mit dem Task höchster Priorität beginnen
7. Bei Unklarheit → **nicht raten**, sondern als `qXX` in `04-tasks.md` eintragen

---

## 3. Datei-System

| Datei | Zweck |
|---|---|
| `01-features.md` | Was wird gebaut und warum (gewünschtes Verhalten, Akzeptanzkriterien, Releases) |
| `02-user-doc.md` | Benutzerperspektive und Bedienung |
| `03-dev-doc.md` | Technische Implementierung (Ist-Zustand) |
| `04-tasks.md` | Tasks, Klärungen, operatives Tagesgeschäft |
| `05-quality.md` | Bugs (mit Severity) und Tests |
| `Skills/` | Generisches, projektübergreifendes Framework-Wissen |
| `Playbooks/` | Projekt-spezifische Deploy- und Release-Abläufe |
| `examples/` | Anschauungsmaterial — kein Live-Stand |

---

## 4. ID-System

| Präfix | Bedeutung | Wo |
|---|---|---|
| `featXX` | Feature | `01-features.md` |
| `taskXX` | Task | `04-tasks.md` |
| `qXX` | Offene Frage / Klärung | `04-tasks.md` |
| `bugXX` | Bug | `05-quality.md` |
| `testXX` | Test | `05-quality.md` |
| `adrXX` | Architektur-Entscheidung | §10 dieses Dokuments |
| `relXX` | Release | `01-features.md` |

**Beispielkette:**
`feat03 → task07 → bug04 → test12 → adr02`

---

## 5. 🔁 Workflow

```
Idee → Feature → Task → Implementierung → Test → (Bug → Fix) → Done → Doku-Sync
```

Der Loop ist iterativ: Implementierung kann neue Tasks erzeugen, Tests können Bugs aufdecken, Bugs erzeugen neue Tasks, Tasks können Features verfeinern.

---

## 6. 🔥 Definition of Done

Ein Feature ist erst **done**, wenn alle drei Perspektiven konsistent sind:

- `01-features.md` — Intent klar, Akzeptanzkriterien definiert
- `02-user-doc.md` — Benutzer kann es ohne Code-Lesen bedienen
- `03-dev-doc.md` — anderer Entwickler kann es erweitern, ohne zu raten

**Plus:**
- alle verlinkten `taskXX` sind erledigt
- alle verlinkten `testXX` sind grün
- keine blockierenden `bugXX` offen

### Done-Checkliste pro Task

Jeder Task führt vor dem Verschieben nach „✅ Done" diese Checkliste:

```
- [ ] 01-features.md aktualisiert (falls Verhalten geändert)
- [ ] 02-user-doc.md aktualisiert (falls UX geändert)
- [ ] 03-dev-doc.md aktualisiert (immer, wenn Code geändert)
- [ ] testXX in 05-quality.md grün
- [ ] PO Sign-off
```

Die ersten vier Punkte verantwortet die KI im Rahmen von `#doc`. Den letzten Punkt **darf nur der PO setzen** (Mensch). Erst dann wandert der Task von „🔧 In Progress" nach „✅ Done".

---

## 7. 🚫 Nicht done, wenn …

- Doku fehlt oder veraltet ist
- Verhalten unklar oder inkonsistent ist
- Implementierung vom Intent abweicht (ohne dass es ein bewusstes ADR gibt)
- Benutzer-Doku nicht zur Realität passt
- bekannte Bugs Kernfunktionalität betreffen

---

## 8. 🧠 Grundprinzip

> Implementierung allein ist keine Fertigstellung.
> Ein Feature ist erst fertig, wenn es **verstanden, nutzbar und wartbar** ist.

---

## 9. 🤝 Zusammenarbeit

Dieses Kapitel regelt, **wer in einem KI-Mensch-Setup wofür verantwortlich** ist. Es ist die zentrale politische Klammer dieses Frameworks.

### 9.1 Rollen

| Rolle | Wer | Verantwortung |
|---|---|---|
| **Product Owner (PO)** | Mensch | Ziel, Scope, Prioritäten, finales Sign-off, Releases |
| **Architekt** | Mensch (KI berät) | Tech-Stack, strukturelle Entscheidungen, ADRs |
| **Implementer** | KI primär | Code, Tests, Tasks abarbeiten |
| **Doc-Sync** | KI primär | `01`/`02`/`03` konsistent halten (`#doc`, `#consistency`) |
| **QA-Reviewer** | Mensch (KI generiert Drafts) | manuelle Verifikation, Browser/Gerät, BFSG, finale Test-Bewertung |
| **Triage** | Mensch | „🆕 New" → `taskXX`, `qXX` beantworten, Priorität setzen |

### 9.2 Befugnisse

**Die KI darf alleine:**
- Code für freigegebene `taskXX` schreiben
- Doku-Sync nach Code-Änderung (`02`, `03` aktualisieren)
- Test-Drafts erzeugen (`testXX` in `05-quality.md`)
- Status-Reports liefern (`#status`, `#next`)
- offensichtliche Refactorings, wenn Verhalten unverändert bleibt

**Die KI muss vorschlagen + Mensch-OK abwarten bei:**
- neuen Features (neuer `featXX`)
- Architektur-Entscheidungen (neuer `adrXX`)
- Library-/Framework-Wechsel oder neuen Dependencies
- Datenbank-Migrationen, Schema-Änderungen
- Lösch-Operationen (Code, Daten, Branches)
- Scope-Erweiterungen eines bestehenden Features
- externen API-Calls mit Nebenwirkungen

**Nur der Mensch:**
- Definition of Done erklären (Sign-off)
- Releases freigeben (`relXX` setzen, Tag erzeugen)
- Plugin-Submission / Marketplace
- Privacy-/DSGVO-Entscheidungen
- Token- und Secret-Handling
- Dritt-System-Zugriffe, die Credentials erfordern

### 9.3 Eskalation: offene Fragen

Wenn die KI auf eine Unklarheit stößt, **rät sie nicht**. Sie legt einen Eintrag in `04-tasks.md` unter „❓ Klärung benötigt" mit ID `qXX` an, mit Verweis auf das betroffene `featXX`/`taskXX`. Der PO antwortet dort, die Antwort wird ins passende Doc übernommen, der `qXX` wandert nach „✅ Geklärt" oder fliegt raus.

### 9.4 Traceability — Konventionen für Code- und Repo-Hygiene

Damit Doku, Tasks und Code nicht auseinanderdriften, gelten folgende Konventionen in **allen Projekten**, die eLeDia.OS_DevFlow als Substrat nutzen:

- **Branch-Namen:** `feat/featXX-kurztitel`, `fix/bugXX-kurztitel`, `task/taskXX-kurztitel`
- **Commit-Messages:** beginnen mit der ID, z. B. `task07: Popup-Sync gegen view-state synchronisiert`
- **PR-Titel:** ID + Klartext, im Body Verweis auf Feature/Task/Bug
- **PR-Beschreibung:** enthält die Done-Checkliste aus §6 als Häkchenliste
- **Merge erst,** wenn die Done-Checkliste erfüllt ist

---

## 10. 🏛 Architektur-Entscheidungen (ADR)

ADRs leben **inline in diesem Master-Dokument**, nicht in separaten Dateien. Vorteil: ein einziger Eintrittspunkt, keine versteckten Ordner. Format pro Entscheidung kompakt.

### Vorlage

```
### adrXX Titel der Entscheidung

- **Datum:** YYYY-MM-DD
- **Status:** vorgeschlagen | beschlossen | abgelöst durch adrYY
- **Kontext:** Welches Problem? Welche Randbedingungen?
- **Optionen:** A | B | C — ggf. Pro/Contra
- **Entscheidung:** Was wurde gewählt?
- **Konsequenzen:** Was zieht das nach sich (Wartung, Migration, Lock-in)?
```

### Aktive ADRs

#### adr01 Fünf Perspektiven + ADR-Layer im Master

- **Datum:** 2026-04-27
- **Status:** beschlossen
- **Kontext:** Architektur-Entscheidungen müssen festgehalten werden, ohne ein viertes Doku-Silo aufzumachen.
- **Optionen:**
  - A) eigener `decisions/`-Ordner mit ADR-Files (klassischer ADR-Pattern)
  - B) Inline im Master-Doc
  - C) als Sektion in `03-dev-doc.md`
- **Entscheidung:** B — inline im Master.
- **Konsequenzen:** Master wird länger, dafür ist der Eintrittspunkt einer. ADRs sind nicht versteckt. Risiko: bei sehr vielen ADRs muss eventuell auf Variante A migriert werden.

---

## 11. 📅 Stand der Skills

Generisches Framework-Wissen lebt in `Skills/`. Dort wird **mit Datum dokumentiert**, gegen welche Version eines externen Standards (Moodle-Core, Design System, BFSG-Normen) der Skill aktuell ist.

- Aktueller Stand: siehe `Skills/README.md` → Sektion „Stand"
- Bei Drift zwischen Skill und Projektkontext: Prompt **`#refresh`** (siehe §12)

---

## 12. 🤖 Prompt-Shortcuts

Diese Prompts steuern KI-Verhalten in strukturierter Form. Verwendung direkt im Chat mit der KI.

### #status

Analysiere `01-features.md`, `04-tasks.md`, `05-quality.md`. Gib aus:
1. implementierte Features
2. Features in Arbeit
3. offene Tasks (gruppiert nach Feature)
4. offene Bugs (priorisiert nach Severity)
5. offene Klärungen (`qXX`)
6. Verifikations-Items
7. Risiken / Inkonsistenzen

Schließe mit: **3 konkrete nächste Schritte**.

---

### #next

Identifiziere die 1–3 wertvollsten nächsten Tasks. Nutze `04-tasks.md`, `05-quality.md`, `01-features.md`. Pro Task: WARUM, Abhängigkeiten, Komplexität.

Priorisierung:
1. Blocker
2. Bugs (S1 vor S2 vor S3)
3. unfertige Kern-Features (P0/P1)

---

### #plan

Brich ein Feature (`featXX`) in Tasks. Pro Task: Ziel, Schritte, Abhängigkeiten, erwartetes Ergebnis. Plus: Risiken und unklare Bereiche.

---

### #refine

Analysiere ein Feature (`featXX`). Identifiziere unklares Verhalten, fehlende Edge Cases, Konflikte. Stelle strukturierte Fragen (Verhalten / UX / technische Constraints). **Beantworte sie nicht** — lege sie als `qXX` ab.

---

### #implement

Führe einen Task (`taskXX`) aus:
1. Ziel restate'n
2. betroffene Komponenten identifizieren
3. schrittweise implementieren
4. Entscheidungen dokumentieren

Bei Unklarheit → `qXX` anlegen, nicht raten.

---

### #test

Designe Tests für ein Feature (`featXX`):
- manuelle Tests (Schritte)
- automatisierte Tests (falls anwendbar)
- Failure-Szenarien

Output: Checkliste für `05-quality.md`. Tests verweisen auf das jeweilige Akzeptanzkriterium aus `01-features.md`.

---

### #bugs

Analysiere Bugs:
- Issue zusammenfassen, Feature verlinken
- Severity bewerten (S1–S4)
- Fix-Ansatz skizzieren

Dann: priorisieren und Muster erkennen.

---

### #verify

Erzeuge Verifikations-Checkliste:
- was testen
- erwartetes Ergebnis
- Ort

Gruppiert nach Feature, einsatzbereit für `04-tasks.md`.

---

### #doc

Konsistenz-Check zwischen `01-features.md`, `02-user-doc.md`, `03-dev-doc.md`. Pro Feature: Implementierung vs. Definition, User-Doc vs. Realität, Dev-Doc vs. Realität. Korrigiere nur die betroffenen Stellen.

---

### #userdoc

Schreibe Benutzer-Doku: Ziel, Schritte, Beispiele, häufige Fehler. **Keine technischen Details.**

---

### #devdoc

Schreibe Entwickler-Doku: Architektur, Komponenten, Datenfluss, Abhängigkeiten. Ziel: Erweiterung ohne Raten.

---

### #consistency

Prüfe das Gesamtsystem:
- Features vs. Implementierung
- Tasks vs. Realität
- Bugs vs. ungelöste Themen
- fehlende Doku

Output: Liste der Issues + Fixes.

---

### #review

Bewerte eine Lösung:
1. was funktioniert
2. Risiken
3. Komplexität
4. Vereinfachungs-Potenzial

---

### #refresh

Prüfe, ob das in `Skills/` hinterlegte Framework-Wissen noch zum aktuellen Projektkontext passt. Konkret:
- Skill-Stand (Datum, Versionen) gegen tatsächliche Projekt-Versionen abgleichen (z. B. `package.json`, `composer.json`, Moodle-Core-Version)
- Drift flaggen (z. B. „Skill sagt MDS v2.1.1, Projekt zieht 2.0.4")
- Vorschlagen, welche Skills aktualisiert oder welche Migration eingeplant werden sollte

Output: Liste „Skill X — Drift gegenüber Projekt: …" plus Empfehlung.

---

## 13. 📌 Nutzungshinweise

- Prompts direkt in KI-Chats einsetzen
- Immer mit IDs arbeiten (`featXX`, `taskXX`, …)
- Kleine, fokussierte Prompts bevorzugen
- Unnötige Rewrites vermeiden

---

## 14. 🚀 Empfohlener Tagesablauf

1. `#status`
2. `#next`
3. `#implement` (für 1–2 Tasks)
4. `#test`
5. `#doc`

Wiederholen.

---
