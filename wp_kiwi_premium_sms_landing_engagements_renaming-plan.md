# Issue #96 – bereinigter Plan für das Landing-Session-Engagement-Renaming

> Stand: 2026-07-30
> Planungsstatus: fachlich und technisch abgeschlossen, noch nicht implementiert
> Aktuell bindender GitHub-Plan: [Codex Planner Report in Issue #96](https://github.com/mpetrovic-hub/backend/issues/96#issuecomment-5073397024)
> Synchronisationsstatus: Der GitHub-Issue-Text und der einzelne bindende Planner Report wurden am 2026-07-30 mit der verpflichtenden Post-Merge-/Pre-Production-Generalprobe synchronisiert und vollständig live verifiziert.

Diese temporäre Root-Datei ist die lokale Arbeits- und Migrations-Checkliste. Sie ist keine dauerhafte Architektur- oder Betriebsdokumentation. Nach der Implementierung müssen die unten genannten kanonischen Dokumente den tatsächlich umgesetzten Stand beschreiben.

## 1. Aktuell verifizierter Stand

- `origin/main` steht beim Abgleich auf `7711b5c9d943bf2b584ea8d2d2774cf11b418e2d`.
- Issue #103 ist abgeschlossen. Der allgemeine externe Datenbank-Runner ist über das repository-eigene WP-CLI-`--require` aktiv und stellt `kiwi database status|apply` bereit.
- Der kanonische Schema-Stand auf `main` ist weiterhin `2026-07-20-1`.
- Issue #96 ist offen und noch nicht implementiert:
  - Tabelle, Repository, Consumer und Tests verwenden weiterhin `premium_sms_landing_engagement...`.
  - Unter `tools/database/migrations/` existiert noch kein Issue-#96-Artefakt.
  - Die Zielversion `2026-07-23-1` ist noch nicht veröffentlicht.
- Issue #72 ist weiterhin offen und verwendet noch den alten Tabellennamen. Es wird erst nach dem erfolgreichen Rename aktualisiert.
- Live-Snapshot von Issue #96:
  - Labels: `0 - codex-candidate`, `1b - codex-planned`.
  - `2 - codex-implement-ready` wurde am 2026-07-25 vom Repository-Owner wieder entfernt und wird durch diese Dateibereinigung nicht zurückgesetzt.
  - Project: `Type=Refactor`, `Codex Mode=Critical`, `Priority=High`, `Risk=High`, `Size=L`, Status `Ready for Codex`.

Dieser Snapshot ist nur ein Freshness-Nachweis. GitHub-Labels, Felder, Issue-Status und Schema-Version müssen unmittelbar vor Implementierungsbeginn erneut geprüft werden.

## 2. Ziel und Scope

### Ziel

- Physischer Production-Tabellenname: `wp_kiwi_landing_session_engagements`.
- Logischer, prefix-unabhängiger Tabellensuffix: `kiwi_landing_session_engagements`.
- Neues Repository: `Kiwi_Landing_Session_Engagement_Repository`.
- Die gemeinsame Landing-Session-Engagement-Datenquelle wird vollständig aus dem Premium-SMS-Namespace gelöst.

### Fachliche Bedeutung

Die Tabelle speichert flow-übergreifende Landing-Session-Evidence: Page Loads, allgemeine und step-spezifische CTA-Klicks, Attribution-/Traffic-Kontext, Session-/UA-Kontext sowie persistierte Soft-Flag-Ergebnisse. Premium-SMS-Fraud ist ein Consumer dieser Datenquelle, aber nicht ihr fachlicher Besitzer.

### Nicht-Ziele

- Keine Änderung des Engagement-Event-Schemas.
- Keine Änderung an CTA-Zählung, Fraud-Regeln, Fraud-Schwellwerten, Retention-Logik oder Summary-Berechnungen außer technisch notwendigen Namensumstellungen.
- Keine Entfernung von `first_cta_click_at`, `last_cta_click_at` oder `cta_click_count`; diese bleiben dauerhafte generische Session-Metriken.
- Keine View, kein Alias, kein Dual Read, keine Dual-Table-Phase, kein Feature Flag und keine alte Repository-Klasse als Kompatibilität.
- Keine Zentralisierung aller Kiwi-Tabellennamen.
- Keine Runtime-Migration und keine Production-Mutation durch den Implementer.
- Keine automatische Backup-, Restore-, Datenverlust- oder Operational-Event-Logik im Migrationsartefakt.
- Keine Implementierung von Issue #72.

## 3. Verbindliche Architekturentscheidungen

### 3.1 Generische Namensquelle

- Eine fokussierte, später erweiterbare Tabellen-Namensquelle wird eingeführt; Zielname: `Kiwi_Database_Table_Names`.
- In Issue #96 wird nur `kiwi_landing_session_engagements` zentral definiert.
- Die Namensquelle kombiniert den vorhandenen WordPress-Tabellenprefix mit dem kanonischen Suffix. Production ergibt dadurch `wp_kiwi_landing_session_engagements`; Tests dürfen weiterhin andere Prefixe verwenden.
- Repository, Schema-Contract, allgemeiner Datenbank-Runner und alle Consumer verwenden diese eine Definition.

### 3.2 Repository und Evaluator-Grenze

- `Kiwi_Landing_Session_Engagement_Repository` ersetzt die alte Repository-Klasse vollständig; es gibt keinen Alias.
- Das Repository hängt nur vom neutralen `Kiwi_Landing_Session_Engagement_Evaluator_Interface` ab.
- Der neutrale Vertrag bildet die vorhandene Auswertung als `evaluate(array $row): array` ab.
- `Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service` implementiert diesen Vertrag.
- Der normale Plugin-Aufbau injiziert den konkreten Premium-SMS-Service ausdrücklich.
- Schema-only-Pfade benötigen keinen Fraud-Evaluator. Produktive Pfade, die Soft-Flags berechnen oder persistieren, dürfen ohne den erforderlichen Evaluator nicht stillschweigend mit einer Ersatzpolicy weiterarbeiten.
- `Kiwi_Premium_Sms_Fraud_Signal_Repository` bleibt eine getrennte Fraud-Datenquelle und ist keine Abhängigkeit des generischen Engagement-Repositories.

### 3.3 Vollständige Namensumstellung

Folgende aktive Bereiche müssen auf `landing_session_engagement...` beziehungsweise die zentrale Namensquelle umgestellt werden:

- Repository-Klasse und -Datei.
- `Kiwi_Plugin` einschließlich Container-/Runtime-Variablen.
- `Kiwi_Landing_Kpi_Rest_Routes`.
- `Kiwi_Premium_Sms_Fraud_Shortcode`.
- `Kiwi_Premium_Sms_Mo_Engagement_Evaluator_Service`.
- `Kiwi_Sales_Attribution_Snapshot_Builder`.
- `Kiwi_Traffic_Source_Funnel_Statistics_Repository`.
- Main- und TK-zone-Summary-Aggregation.
- Device Model Harvest.
- Retention Coverage Gate und betroffene Retention-Source-/Settings-Namen.
- Verwaltete Views.
- Schema-Contract, Datenbank-Deployment-Service und WP-CLI-Bootstrap.
- Testklassen, Fixtures, SQL-Erwartungen und Testbeschreibungen.
- Dauerhafte Dokumentation.

Der alte Tabellen-/Repository-Name ist danach nur noch im migrationsspezifischen Artefakt, dessen Tests und eindeutig historischen Hinweisen zulässig. Wirklich Fraud-spezifische Premium-SMS-Klassen und Begriffe bleiben unverändert.

## 4. Datenbank- und Migrationsvertrag

### 4.1 Trennung der Werkzeuge

Der allgemeine Runner bleibt dauerhaft zuständig für den jeweils kanonischen Schema-Sollzustand:

```bash
wp --require=wp-content/plugins/backend/tools/database/kiwi-database.php kiwi database status
wp --require=wp-content/plugins/backend/tools/database/kiwi-database.php kiwi database apply
```

- Der allgemeine `apply` führt niemals den historischen Rename oder Rollback aus.
- Erkennt er die alte Engagement-Tabelle, stoppt er fail-closed als migrationspflichtiger Legacy-Zustand und legt nicht parallel eine leere neue Tabelle an.
- Neue Installationen erstellen direkt die neue Tabelle über den allgemeinen Runner.

Der historische Rename erhält ein getrenntes, versioniertes Artefakt unter `tools/database/migrations/`:

```text
kiwi database migration landing-session-engagements check
kiwi database migration landing-session-engagements apply
kiwi database migration landing-session-engagements rollback
```

Der Implementer dokumentiert die vollständigen `wp --require=...`-Aufrufe. Registrierung und Ausführung verwenden dieselbe sichere WP-CLI-Grenze wie Issue #103: vor dem WordPress-Load registriert, Ausführung nach `plugins_loaded`, Abbruch vor normalen `init`-Seiteneffekten und fail-closed bei Lifecycle-/API-Fehlern.

### 4.2 Versionen

- Exakt erwarteter Vorgänger: `2026-07-20-1`.
- Zielversion von Issue #96: `2026-07-23-1`.
- Ändert ein anderes Schema-Issue vor Implementierungsbeginn den kanonischen Stand, sind Vorgänger- und Zielversion neu festzulegen und Planner Report, Tests und Checkliste vor der Implementierung zu aktualisieren.

### 4.3 Read-only `check`

Das JSON-Ergebnis verwendet `state`; `check` mutiert nichts.

| Ermittelter Zustand | Ergebnis |
|---|---|
| Alte Tabelle, Vorgängerversion und vollständiges altes Schema | `success=true`, `state=pending`, Exit `0` |
| Neue Tabelle, Zielversion und vollständiges Zielschema | `success=true`, `state=applied`, Exit `0` |
| Alte und neue Tabelle gleichzeitig | `success=false`, `state=conflict`, Non-zero |
| Beide Tabellen fehlen | `success=false`, `state=missing`, Non-zero |
| Tabellenname und Schema-Version widersprechen sich | `success=false`, `state=version_mismatch`, Non-zero |
| Spalten/Indizes fehlen oder eine Prüfquery scheitert | `success=false`, `state=schema_mismatch` beziehungsweise eindeutiger Fehlercode, Non-zero |

`check` bestätigt nur die technische Datenbank-Eignung. Es erteilt keine User-Autorisierung für `apply`, `rollback` oder das Öffnen der Website.

### 4.4 `apply`

`apply` ist nur aus vollständig bestätigtem `pending` zulässig:

1. Denselben datenbankweiten Advisory-Lock wie der allgemeine Runner erwerben.
2. Tabellenexistenz/-typ, Version, Zeilenzahl, Min-/Max-ID, `AUTO_INCREMENT`, Spalten und Indizes erfassen.
3. Atomar `RENAME TABLE wp_kiwi_premium_sms_landing_engagements TO wp_kiwi_landing_session_engagements` ausführen.
4. Daten- und Schema-Snapshot sowie vollständige Ziel-Postconditions prüfen.
5. Erst danach `2026-07-23-1` speichern.
6. Lock in jedem Exit-Pfad freigeben.

Wiederholtes `apply` im vollständig bestätigten `applied`-Zustand ist ein erfolgreicher No-op. Teil- oder Widerspruchszustände werden nicht automatisch repariert.

### 4.5 `rollback`

- Kein automatischer Rollback.
- Nur nach ausdrücklicher User-/Operator-Freigabe, im Maintenance-Fenster und bevor neue Schreibzugriffe freigegeben wurden.
- Reverse Rename zuerst ausführen, alten Daten-/Schema-Zustand prüfen und erst danach die Vorgängerversion wiederherstellen.
- Anschließend den vorherigen kompatiblen Code-Release wiederherstellen und prüfen.
- Wiederholtes `rollback` ist nur im vollständig bestätigten alten Zustand ein erfolgreicher No-op; alle anderen Zustände stoppen.
- Falls ein sicherer Rename-Rückweg nicht mehr möglich ist, bleibt der Restore aus dem Hostinger-Backup eine getrennte Operator-Aktion.

## 5. Production-Sicherheitsvertrag

### Maintenance und Sicherung

- `apply` und `rollback` laufen ausschließlich in einem ausdrücklich autorisierten Maintenance-Fenster.
- Website-, REST-, AJAX- und Admin-Traffic werden gestoppt.
- WP-Cron, externe Scheduler, Worker und andere schreibende WP-CLI-Prozesse werden pausiert.
- Der Datenbank-Lock verhindert nur parallele Migrationsläufe und ersetzt den Schreibstopp nicht.
- Vor `apply` lädt der User ein aktuelles Hostinger-Datenbank-Backup herunter und bestätigt Zeitpunkt/Bezeichnung sowie lokale Verfügbarkeit.
- Das Artefakt erstellt keinen eigenen Dump.
- Die konkreten Maintenance-, Pause- und Wiederaufnahme-Kommandos beziehungsweise Hostinger-Schritte müssen vor dem Production-Fenster im Implementer-Handoff oder Runbook feststehen. Fehlt dieser Nachweis, beginnt der Cutover nicht.

### Nachprüfung und Stop-Gates

Eine Nachprüfung gilt als gescheitert, wenn mindestens eine verlangte Postcondition nicht eindeutig bestätigt ist, eine Prüfquery scheitert, das Ergebnis widersprüchlich ist oder ein relevanter Anwendungs-Smoke fehlschlägt. Dann bleiben Maintenance und Schreibstopp aktiv.

Ein unerwartet leerer historischer Engagement-Datenbestand ist ein sichtbares Stop-Gate – unabhängig davon, ob er bereits im Vorher-Snapshot oder erst danach auffällt. Der User darf den Verlust ausdrücklich akzeptieren, wenn:

- Tabellenstruktur und neue Schreib-/Lesepfade funktionieren,
- Sales-, Billing- und Transaktionsdaten nicht betroffen sind,
- die relevanten Smokes grün sind,
- das Retention Gate bei unzureichender Vergleichsgrundlage fail-closed bleibt.

Ohne diese ausdrückliche Entscheidung wird restauriert beziehungsweise – falls sicher möglich – zurückgerollt.

### Operational Events

- Ein vollständig erfolgreicher Erstlauf erzeugt keinen Operational Event; der Erfolg wird in Issue #96 dokumentiert.
- Bei einem qualifizierten Fehler bewahrt der Operator zuerst das bereinigte Original-JSON und den Exit-Code.
- Danach wird der Fehler manuell über `Kiwi_Operational_Event_Service::record_failure()` als `schema_migration_failed` erfasst; niemals per Direkt-SQL und niemals automatisch durch das Artefakt.
- Der Event verwendet `area=database`, `severity=critical`, eine stabile Correlation und versuchsspezifische Idempotency. Secrets, vollständige SQL-Payloads, MSISDNs und Subscriber-Identifier bleiben ausgeschlossen.
- Recovery wird genau einmal erst nach vollständig grüner Datenbank-/Anwendungsprüfung erfasst.
- Scheitert ausschließlich das Event-/Recovery-Logging bei sonst vollständig grüner Technik, darf der User die Website ausdrücklich freigeben. Der fehlende Eintrag wird später über denselben Service nachgetragen; Issue #96 bleibt bis dahin offen. Dafür entsteht kein separates GitHub Issue.

## 6. Implementierung, Tests und Dokumentation

### Implementer-Lieferumfang

1. Generische Namensquelle, Repository und neutralen Evaluator-Vertrag implementieren.
2. Alle aktiven Consumer und internen Schlüssel vollständig umstellen.
3. Schema-Contract und Zielversion aktualisieren; allgemeinen Runner beim alten Namen fail-closed halten.
4. Getrenntes migrationsspezifisches `check|apply|rollback`-Artefakt implementieren.
5. Automatisierte Zustands-, Lock-, Snapshot-, Postcondition-, Lifecycle-, Versions- und Regressionstests ergänzen.
6. Dauerhafte Dokumentation aktualisieren.
7. In der Abschlussbesprechung strikt zwischen lokal implementierten Mechanismen und offenen Production-Aktionen trennen.

Der Implementer greift nicht auf Production zu und führt dort weder `check`, `apply`, `rollback`, Backup, Smokes noch Operational Events aus.

### Mindestvalidierung

- Vollständige `pending`/`applied`/`conflict`/`missing`/`schema_mismatch`/`version_mismatch`-Matrix.
- Wiederholtes `apply` und `rollback`.
- Lock-Kollision/-Verlust, Query-Fehler, Rename-Fehler, Postcondition-Fehler und Versionspersistenzfehler.
- Nachweis, dass der allgemeine `apply` weder Rename noch Rollback ausführt und keine parallele leere Zieltabelle erzeugt.
- Repository-/DI-Tests für den neutralen Evaluator und unveränderte Soft-Flag-Ergebnisse.
- Main-/TK-zone-Summary, KPI/Statistics, Device Harvest, Retention Gate, Views, Sales Attribution sowie Premium-SMS-Fraud-/MO-Consumer.
- Vollständige Suche nach nicht erlaubten alten Datenquellen-Namen.
- PHP-Lint, `php tests/run-tests.php`, Dokumentations-/Linkprüfung und `git diff --check`.

### Verpflichtende Post-Merge-/Pre-Production-Generalprobe

Nach dem Merge und vor jedem Production-Deployment wird der exakte Merge-Commit aus `origin/main` technisch end-to-end geprüft:

1. Der Deployment-Codex legt einen getrennten temporären Git-Worktree für den exakten Merge-Commit an. Der aktuell geöffnete VS-Code-Checkout und seine lokalen Änderungen bleiben unangetastet.
2. In einer vollständig von Production getrennten Wegwerf-Umgebung werden WordPress, WP-CLI 2.12 und MariaDB mit synthetischen Daten betrieben. Production-Zugänge, Production-Dumps und echte Kunden-/Subscriber-Daten sind ausgeschlossen.
3. Der vorherige stabile Code beziehungsweise eine daraus abgeleitete geprüfte Fixture erzeugt den echten Vorgängerzustand: Version `2026-07-20-1`, alter Tabellenname, vollständiges altes Schema und künstliche Engagement-Zeilen.
4. Danach wird ausschließlich der exakte gemergte Issue-#96-Stand geladen.
5. Mit den tatsächlich implementierten vollständigen `wp --require=...`-Befehlen werden mindestens Migrations-`check`, `apply`, allgemeiner `kiwi database status`, wiederholtes `apply` als No-op sowie die relevanten WordPress-Schreib-/Lesepfade und Consumer-Smokes ausgeführt.
6. In einer separaten frischen Scratch-Datenbank werden der kontrollierte `rollback`, wiederholtes `rollback` und die fail-closed Zustände `conflict`, `missing`, `version_mismatch` und `schema_mismatch` über das echte PHP-/WP-CLI-Artefakt geprüft.
7. Der Bericht hält Merge-Commit, Umgebungsversionen, bereinigte Befehle/Ergebnisse, Daten-/Schema-Snapshots, Smokes und Cleanup fest. Scratch-Datenbanken, Worktree und Wegwerf-Artefakte werden anschließend entfernt.

Nur ein vollständig grüner Lauf gegen genau den später zu deployenden Merge-Commit darf zur Production-Freigabe führen. Scheitert die Generalprobe oder ändert ein Korrektur-PR den Commit, bleibt Production blockiert und die gesamte Generalprobe wird mit dem neuen Merge-Commit wiederholt.

Diese lokale Generalprobe kann Hostinger-Maintenance, reale Traffic-/Job-Sperren, Production-Berechtigungen, das heruntergeladene Hostinger-Backup und Production-Smokes nicht beweisen. Diese Punkte bleiben im Production-Preflight.

### Dauerhafte Dokumentationsziele

- `docs/operations/database-migrations.md`
- `docs/operations/landing-funnel-analytics.md`
- `docs/operations/premium-sms-fraud-monitoring.md`
- betroffene Stellen in `docs/architecture/capability-matrix.md`
- `CHANGELOG.md`
- der bestehende Issue-#96-/Tabellenrename-Abschnitt in `TODO.md`

`INDEX.md`-Dateien ändern sich nur, falls ein neues navigierbares Dokument entsteht. Ein neues Sammel- oder Architekturdokument ist nicht vorgesehen.

## 7. Bereits erbrachte Planner-Evidence

### MariaDB-Generalprobe vom 2026-07-24

- Von Production getrenntes portables MariaDB Community Server `12.3.2`.
- Paket-SHA-256: `67347c129eb9c5923d002ea34fbfa27c60eb95d36dd73b85af2651cdeceecac5`.
- Drei künstliche Zeilen; `min_id=7`, `max_id=41`, `AUTO_INCREMENT=73`.
- Erfolgreich geprüft: `pending`, echter Rename mit Daten-/Schema-Erhalt, wiederholtes Apply als No-op, Rollback mit Daten-/Schema-Erhalt, wiederholtes Rollback als No-op, `conflict`, `missing`, `version_mismatch` und `schema_mismatch`.
- Alle neun Szenarien bestanden; Scratch-Datenbank und Wegwerf-Code wurden entfernt.

Die Generalprobe bestätigt MariaDB-Rename und Zustandslogik. Sie bestätigt nicht die noch zu implementierende PHP-/WP-CLI-Integration, Production-Berechtigungen, Maintenance-Wirksamkeit, Hostinger-Backup oder Production-Smokes.

### Freshness-Audit vom 2026-07-30

- Issue #96, #103 und #72 sowie Project-Felder und Labelhistorie live geprüft.
- `origin/main`, `CHANGELOG.md`, `tools/database/`, Schema-Contract, Runner, relevante Consumer, Dokumentationsindizes und TODO-Kontext geprüft.
- Graphify-Abhängigkeitsabfrage und gezielte Repository-Suche bestätigen: Der Rename ist noch nicht implementiert; die festgelegte externe Issue-#103-Grenze passt weiterhin zum aktuellen Code.

## 8. Offene Punkte

Es gibt aktuell keine unbeantwortete fachliche oder architektonische Entscheidung.

Offen bleiben bewusst folgende Freigabe- und Freshness-Gates:

1. Der neue Generalproben-Vertrag wird vor Implementierungsbeginn in den einzelnen bindenden GitHub Planner Report übernommen und dort erneut verifiziert.
2. Der User entscheidet danach, wann Issue #96 zur Implementierung freigegeben wird und ob das entfernte Label `2 - codex-implement-ready` im vorgesehenen Workflow wieder gesetzt werden soll.
3. Unmittelbar vor Implementierungsbeginn werden `origin/main`, Issue-/Planner-Report, Schema-Version und konkurrierende Datenbankänderungen erneut geprüft.
4. Vor Production müssen die lokale Generalprobe vollständig grün und die konkreten Maintenance-/Job-Pause-/Wiederaufnahme-Schritte für die Zielumgebung dokumentiert sein.
5. Nur im Fehlerfall entscheidet der User über Datenverlustakzeptanz, Rollback/Restore und die Ausnahmefreigabe bei isoliertem Operational-Event-Logging-Fehler.

Diese Punkte sind keine Lücken im Implementierungsdesign; sie sind absichtlich spätere User-/Operator-Entscheidungen.

## 9. Verbindliche Migrations-Todo-Liste

Die Reihenfolge ist verbindlich. Ein Schritt beginnt erst, wenn der vorherige erfolgreich abgeschlossen und dokumentiert wurde.

### A. Planung und Implementierungsfreigabe

- [x] 1. `[Planner]` Architektur-, Migrations-, Rollback-, Fehler- und Freigabeentscheidungen abschließen; MariaDB-Prototyp ausführen und den einzelnen aktuellen Planner Report in Issue #96 verifizieren.
- [x] 2. `[Planner]` Plan am 2026-07-30 gegen aktuellen Repository-/GitHub-Stand prüfen, Redundanzen entfernen und offene Gates sichtbar festhalten.
- [x] 3. `[Planner]` Die verpflichtende Post-Merge-/Pre-Production-Generalprobe in den einzelnen bindenden GitHub Planner Report übernehmen, den gespeicherten Report vollständig verifizieren und keine Project-Status-Änderung vornehmen.
- [ ] 4. `[User]` Aktualisierten Planner Report und Hard-Cutover-Ablauf erneut prüfen, die Implementierung ausdrücklich freigeben und entscheiden, ob `2 - codex-implement-ready` wieder gesetzt werden soll.
- [ ] 5. `[Implementer]` Vor Arbeitsbeginn `origin/main`, den einzelnen aktuellen Planner Report, Schema-Version und konkurrierende Datenbankänderungen prüfen; bei geändertem Vorgängerstand stoppen und neu planen.

### B. Implementierung und Review

- [ ] 6. `[Implementer]` Generische Namensquelle, Repository, Evaluator-Vertrag, Consumer-Umstellung, Schema-Contract und getrenntes Migrationsartefakt implementieren; Fraud-Fachlogik unverändert lassen.
- [ ] 7. `[Implementer]` Zustands-/Lock-/Snapshot-/Lifecycle-/Versions- und Consumer-Regressionstests sowie vollständige Namenssuche, PHP-Lint, Testsuite, Dokumentationsprüfung und `git diff --check` ausführen; keine Production-Datenbank verändern.
- [ ] 8. `[Implementer]` Kanonische Dokumentation und TODO aktualisieren und in der Abschlussbesprechung exakte Production-Befehle/JSON, Maintenance-/Job-Steuerung, Generalproben-Voraussetzungen sowie alle offenen Operator-Aktionen ausweisen.
- [ ] 9. `[User/Reviewer]` Implementierung, Tests, Dokumentation und Deployment-/Rollback-Anleitung prüfen und den PR zum Merge freigeben.
- [ ] 10. `[User/Reviewer]` PR mergen und den exakten Merge-Commit aus `origin/main` als einzigen Kandidaten für Generalprobe und Production dokumentieren.

### C. Verpflichtende lokale Generalprobe

- [ ] 11. `[Deployment-Codex]` Den exakten Merge-Commit in einem isolierten temporären Worktree bereitstellen und eine vollständig von Production getrennte WordPress-/WP-CLI-2.12-/MariaDB-Wegwerf-Umgebung aufbauen.
- [ ] 12. `[Deployment-Codex]` Den geprüften Vorgängerzustand mit altem Code beziehungsweise Fixture und künstlichen Daten herstellen; danach mit dem exakten Merge-Commit den vollständigen Erfolgs-, No-op-, Consumer-Smoke-, Rollback- und fail-closed Ablauf über die echten PHP-/WP-CLI-Befehle ausführen.
- [ ] 13. `[Deployment-Codex]` Merge-Commit, Umgebung, bereinigte Befehle/JSON, Vorher-/Nachher-Snapshots, Smokes und Cleanup dokumentieren; Scratch-Datenbanken, temporären Worktree und Wegwerf-Artefakte entfernen.
- [ ] 14. `[User/Reviewer]` Generalproben-Nachweis prüfen. Bei Fehler Production blockieren, Korrektur-PR erstellen und ab Schritt 9 mit dem neuen Merge-Commit wiederholen; nur bei vollständig grünem Lauf den exakten Commit für Production freigeben.

### D. Vorbereitung des Production-Fensters

- [ ] 15. `[User]` Production-Deployment des lokal geprüften Merge-Commits und das konkrete Maintenance-Fenster ausdrücklich autorisieren.
- [ ] 16. `[Deployment-Codex/Operator]` Zielumgebung, lokal geprüften Merge-Commit, vorherigen kompatiblen Code-Release, Zugänge und die exakten Maintenance-/Pause-/Wiederaufnahme-Schritte bestätigen.
- [ ] 17. `[User]` Aktuelles Hostinger-Datenbank-Backup herunterladen und Zeitpunkt/Bezeichnung sowie lokale Verfügbarkeit bestätigen.
- [ ] 18. `[Deployment-Codex/Operator]` Vorzustand soweit mit dem freigegebenen Artefakt ohne Code-Aktivierung möglich read-only erfassen.
- [ ] 19. `[User/Operator]` Maintenance aktivieren und Website-, REST-, AJAX- und Admin-Traffic sperren.
- [ ] 20. `[Operator]` WP-Cron, externe Scheduler, Worker und andere schreibende WP-CLI-Prozesse pausieren; Schreibstopp bestätigen.
- [ ] 21. `[Operator]` Exakt den lokal geprüften Release bereitstellen, ohne Website oder Jobs freizugeben.

### E. Externer Hard Cutover

- [ ] 22. `[Operator]` Migrations-`check` ausführen und nur `success=true`, `state=pending`, Exit `0`, Vorgängerversion `2026-07-20-1` und vollständiges altes Schema akzeptieren.
- [ ] 23. `[Operator]` Vorher-Snapshot mit Row Count, Min-/Max-ID, `AUTO_INCREMENT`, Spalten und Indizes dokumentieren; unerwartete Leere als Stop-Gate behandeln.
- [ ] 24. `[User/Operator]` Grünen Ausgangscheck, lokal geprüften Commit und verfügbares Hostinger-Backup nochmals bestätigen.
- [ ] 25. `[User/Operator]` `apply` ausdrücklich freigeben und ausführen.
- [ ] 26. `[Operator]` Rename, Snapshot-Erhalt, Ziel-Postconditions und erst danach gespeicherte Version `2026-07-23-1` bestätigen.
- [ ] 27. `[Operator]` Allgemeinen read-only `kiwi database status` ausführen und Exit `0`, `ready=true`, `installed_version=2026-07-23-1` sowie keine Drift verlangen.
- [ ] 28. `[Operator]` Neuen Schreib-/Lesepfad, KPI/Statistics, Main-/TK-zone-Summary, Device Harvest, Retention Gate, Views, Sales Attribution und relevante Premium-SMS-Fraud-/MO-Pfade kontrolliert prüfen.

### F. Stop, Rollback oder Wiederfreigabe

- [ ] 29. `[Stop-Gate/Operator]` Bei nicht vollständig beweisbarem Erfolg Maintenance und Schreibstopp beibehalten, bereinigtes Original-JSON/Exit sichern, Zustand diagnostizieren und einen qualifizierten Fehler separat über den Operational-Event-Service erfassen.
- [ ] 30. `[User]` Bei unerwartet leerem historischem Engagement-Bestand ausdrücklich zwischen akzeptiertem Verlust und Restore beziehungsweise sicherem Rollback entscheiden.
- [ ] 31. `[Operator]` Bei akzeptiertem Verlust Struktur, neue Schreib-/Lesepfade, relevante Smokes und fail-closed Retention Gate bestätigen.
- [ ] 32. `[User/Operator]` Falls erforderlich `rollback` ausdrücklich freigeben und nur vor neuen Schreibzugriffen ausführen; anschließend alten Zustand, Vorgängerversion und vorherigen kompatiblen Code verifizieren.
- [ ] 33. `[User/Operator]` Bei vollständig grüner Technik Wiederfreigabe genehmigen. Scheitert ausschließlich Event-/Recovery-Logging, den bereinigten Fehler und die offene Nachholung dokumentieren und die Ausnahme ausdrücklich freigeben.
- [ ] 34. `[Operator]` Zuerst kontrollierte Jobs/Smokes und danach öffentlichen Traffic freigeben; kurzfristig überwachen.

### G. Abschluss und Folgearbeit

- [ ] 35. `[Operator/Deployment-Codex]` Einen noch fehlenden Operational-Event-/Recovery-Eintrag ohne Direkt-SQL über den bestehenden Service nachtragen und verifizieren; bis dahin Issue #96 offen lassen.
- [ ] 36. `[Operator]` Release, lokale Generalprobe, Backup, Vorher-/Nachher-Werte, Commands, Production-Smokes, Datenverlust/Rollback, Events und verbleibende Risiken in Issue #96 dokumentieren.
- [ ] 37. `[Planner/User]` Issue #72 nach erfolgreichem Rename in Titel, Beschreibung und Akzeptanzkriterien auf `wp_kiwi_landing_session_engagements` umstellen.
- [ ] 38. `[User]` Erst nach vollständiger Nachweisprüfung und ohne fehlenden erforderlichen Event-/Recovery-Eintrag über den Abschluss von Issue #96 entscheiden.
