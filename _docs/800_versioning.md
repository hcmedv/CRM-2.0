# 800_versioning.md
# SCHEMA VERSIONIERUNG

## Ziel

Kontrollierte Weiterentwicklung ohne Bruch.

---

# Regeln

- Neue Felder nur additiv
- Keine Umbenennung bestehender Root-Keys
- Keine Änderung von state -> status

---

# Migration

Bei Schemaänderung:

- Version dokumentieren
- Writer ggf. Migration Layer enthalten
- Alte Events nicht automatisch verändern
