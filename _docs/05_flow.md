# Ablauf & Datenfluss

## Ziel
Transparenter, reproduzierbarer Datenfluss.
Jede Stufe hat **klare Verantwortung**.

---

## Gesamtüberblick

Ingress  
→ Normalisierung  
→ Enrichment  
→ Writer  
→ Merge  
→ Recompute  
→ UI

---

## 1. Ingress
Quellen:
- PBX
- TeamViewer
- Camera
- UI (manuelle Aktionen)

Aufgabe:
- Entgegennahme externer Daten
- Keine Interpretation
- Keine Persistenz

---

## 2. Normalisierung
- Quellenformate → internes, neutrales Format
- Mapping technischer Felder
- Fehlerhafte Daten früh verwerfen

---

## 3. Enrichment
- Anreicherung mit Wissen:
  - Kunden
  - Kontakte
  - interne Zuordnungen
- Zentrale Logik
- Quellenunabhängig

---

## 4. Writer
- Einzige Schreibstelle
- Schema-Prüfung
- Übergabe an Merge

---

## 5. Merge
- Idempotenter Abgleich
- Zusammenführen von Daten
- Respektiert bestehende Werte
- Setzt Meta-Informationen

---

## 6. Recompute
- Abgeleitete Felder
- Aggregationen
- Zustandsabhängige Berechnungen

---

## 7. UI
- Read-only Anzeige
- Edit nur über definierte Commit-Wege
- Keine impliziten Writes

---

## Leitregeln
- Jede Stufe kennt nur ihre Nachbarn
- Kein Überspringen von Stufen
- Keine versteckten Seiteneffekte
