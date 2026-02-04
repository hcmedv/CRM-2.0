# 111 – Seiten- & Layout-Struktur (CRM)

## Ziel
Definition einer **einheitlichen Seitenstruktur** für alle CRM-Module.
Der Aufbau ist unabhängig vom Modulinhalt und dient als feste Vorlage.

Ziele:
- konsistentes UI über alle Module
- einfache Erweiterbarkeit
- sauberes Debugging
- gute Nutzbarkeit auf Desktop und Tablet
- keine Tab-Explosion im Browser

---

## Grundprinzipien

- Jede Seite nutzt **dieselbe Grundstruktur**
- Layout ≠ Inhalt (strikte Trennung)
- Inhalte werden **eingesetzt**, nicht neu gerahmt
- Module dürfen **keine eigenen Seitenlayouts** definieren
- Navigation bleibt immer sichtbar
- Detailbearbeitung erfolgt **im Overlay**, nicht per Seitenwechsel

---

## Seitenarten (High Level)

- Dashboard (`/`)
- Modul-Index (`/events`, `/kunden`, `/dokumente`, …)
- Modul-Unterseiten (Listen, Verwaltung)
- Login (`/login`)
- Sonderseiten (z. B. Service-Report)

---

## Standard-Seitenlayout

Jede Seite besteht aus:

1. Globaler Header (Navigation)
2. Seiten-Titelbereich
3. Haupt-Content-Container
4. Optional: Footer (später)

---

## Standard-Content-Raster

Das **Default-Raster** für CRM-Seiten:

- obere Zone: **3 Cards nebeneinander**
- untere Zone: **1 Card volle Breite**

Dieses Raster ist:
- responsiv
- tabletfähig
- wiederverwendbar

Abweichungen sind **nur erlaubt**, wenn in der Modul-Doku begründet.

---

## Beispiel-Zuordnung

### Dashboard
- Card 1: Vorgänge
- Card 2: Leistungsnachweise
- Card 3: Kunden
- Card unten: Systemstatus / Hinweise

### Stammdaten – Übersicht
- Card 1: Kunden
- Card 2: Artikel
- Card 3: Mitarbeiter
- Card unten: Beschreibung / Hinweise

---

## Modul-Index-Seiten (z. B. Events)

Index-Seiten zeigen:
- Listen
- Filter
- Status-Gruppierungen

**Bearbeitung erfolgt nicht inline**, sondern über Detail-Overlay.

---

## Detail-Overlay (zentraler Mechanismus)

- Öffnet sich über Klick auf eine Card
- Legt sich über die Index-Seite
- Hintergrund bleibt sichtbar
- Kein Seitenwechsel
- Kein neuer Browser-Tab

Overlay nutzt die **volle verfügbare Breite** (Tablet optimiert).

---

## Detail-Overlay – Struktur

Das Detail-Overlay enthält:

1. Kopfbereich (Meta + Status)
2. Tab-Leiste
3. Inhaltsbereich (tababhängig)

---

## Pflicht-Tabs im Detail-Overlay (max. 5)

Reihenfolge fest:

1. Details
2. Notizen
3. Aktivität
4. Dokumente
5. Daten

Weitere Tabs nur bei klarer fachlicher Notwendigkeit.

---

## Tabs – Grundregeln

- Keine verschachtelten Tabs
- Kein Scrollen innerhalb einzelner UI-Elemente
- Tabs wechseln **nur den Inhalt**, nicht den Kontext
- Tabs sind modul-spezifisch befüllbar

---

## Verschachtelte Overlays

Erlaubt:
- Overlay → Sub-Overlay (z. B. Arbeitszeit)

Regeln:
- Maximal 2 Ebenen
- Sub-Overlay ist funktional (Formular)
- Schließen bringt immer sauber zurück

---

## Mobile / Tablet Leitlinien

- Smartphone: primär Lesen, einfache Aktionen
- Tablet: Lesen + Eingabe + Unterschrift
- Desktop: volle Bearbeitung

Smartphone ist **kein primäres Pflege-UI**.

---

## Seiten ohne Overlay

Bestimmte Seiten bleiben klassische Scroll-Seiten:

- Service-Report
- Formulare mit Unterschrift
- Exporte / Druckansichten

Diese Seiten:
- kein Overlay
- kein Tab-System
- Fokus auf linearen Ablauf

---

## Navigation & URLs

- Saubere URLs ohne Dateinamen
- Keine Übergabe von Bearbeitungszuständen per URL
- Kein POST-Status in der Browser-History
- Session-basierter Kontext

Beispiele:
- `/events`
- `/kunden`
- `/login`

---

## Zusammenfassung

- Eine Seitenstruktur für alles
- Overlay statt Seitenwechsel
- Tabs nur im Detail-Kontext
- Module liefern Inhalte, kein Layout
- Tablet ist gleichwertiger Arbeitsplatz

Diese Struktur ist **verbindliche Basis** für alle weiteren Module.
