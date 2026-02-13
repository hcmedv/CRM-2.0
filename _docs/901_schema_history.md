# 901_schema_history.md
# CRM V2 – SCHEMA HISTORIE UND ENTSCHEIDUNGEN

Dieses Dokument enthält:

- Diskussionsverläufe
- Entscheidungsbegründungen
- Historische Fehlannahmen
- Code-Analysen
- Design-Überlegungen

Es ist KEIN normatives Dokument.

------------------------------------------------------------

# 1. Diskussion: workflow.status vs workflow.state

Historischer Fehler:
workflow.status wurde verwendet.

Entscheidung:
Nur workflow.state ist gültig.

------------------------------------------------------------

# 2. Diskussion: TeamViewer beendet → closed?

Fehlannahme:
end_date vorhanden → state = closed

Entscheidung:
Trigger steuert niemals workflow.

------------------------------------------------------------

# 3. Codeanalyse crm_process_teamviewer.php

[...]

------------------------------------------------------------

# 4. Worklog vs timing Diskussion

[...]

------------------------------------------------------------
