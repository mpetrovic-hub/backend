# Issue #110 – temporäre Planungsnotizen

Status: laufendes Planungsinterview. Diese Datei ist eine lokale Arbeitsnotiz, keine dauerhafte Projektdokumentation und kein Ersatz für den finalen Codex Planner Report. Nach dem finalen Report wird entschieden, ob sie gelöscht oder in eine passende Dokumentation überführt wird.

## Issue-Bindung und aktueller GitHub-Stand

- Issue: `mpetrovic-hub/backend#110`
- Titel: `[codex] Move SQLite integrity checks out of retention WP-Cron`
- Status: `OPEN`
- Projektstatus: `Ready for Codex` – darf durch den Planner-Report-Workflow nicht verändert werden.
- Projektfelder: `Type=Bugfix`, `Codex Mode=Critical`, `Priority=High`, `Risk=High`, `Size=M`
- Aktuelles Label: `0 - codex-candidate`
- Stand bei Planungsbeginn: keine Kommentare und kein vorhandener Codex Planner Report.

## Ziel

Globale SQLite-Integritätsprüfungen aus dem kurzlebigen Retention-WP-Cron-/Web-Request entfernen, ohne die Archive-before-delete-Sicherheit abzuschwächen. Die Archivgesundheit muss weiterhin durch einen separaten, langlebigen externen Prüfprozess kontrolliert werden; Fehler müssen operational sichtbar sein.

## Bereits feststehende Grenzen

- Keine globale SQLite-`quick_check`- oder `integrity_check`-Prüfung im Retention-WP-Cron-/Web-Request.
- Kein MySQL-Delete ohne zuvor erfolgreich committed SQLite-Archivdaten und persistierte Primary-Key-Evidence.
- Coverage Gate, gefrorener `target_max_primary_key` und bestehende Retention-Grenzen bleiben unverändert.
- Keine erneute Recovery des Produktionslaufs `retention_3c4c12cf1b9244868d509ecbc2ffc5e4`.
- Keine Bereinigung oder Neuaufteilung vorhandener Jahresarchive.
- Issue #108 bleibt ein separates Vorhaben für geschäftlich relevante `coverage_gate_failed`-Skips.
- Die abgeschlossene einmalige Produktions-Recovery ist Evidenz, aber nicht Teil der Implementierung.
- Keine GitHub-Änderung, bevor alle Implementierungsentscheidungen geschlossen, der Prototyp ausgewertet und der finale Plan vom Benutzer freigegeben ist.

## Verifizierter aktueller Ablauf

1. `Kiwi_Plugin` registriert den täglichen Scheduler-Hook `kiwi_retention_cleanup_scheduler_daily` und den Single-Event-Worker `kiwi_retention_cleanup_worker`.
2. Der Scheduler führt Coverage Gate und Vorher-Snapshot aus, friert `target_max_primary_key` ein, legt den Audit-Run an und plant den Worker.
3. `Kiwi_Retention_Cleanup_Service::run_worker()` persistiert zunächst `status=running` und `worker_phase=archive_running`.
4. `Kiwi_Retention_Sqlite_Archive_Service::archive_primary_key_chunk()` schreibt Archivzeilen und `archive_batch_rows` gemeinsam in einer SQLite-Transaktion und committet.
5. Direkt nach dem Commit führt dieselbe Methode derzeit ein globales `PRAGMA quick_check` über die Jahresdatei aus.
6. Erst nach der erfolgreichen Rückkehr zum Worker prüft dieser Counts und Primary Keys, persistiert `worker_phase=delete_running`, löscht genau diese Primary Keys aus MySQL und persistiert anschließend den kumulierten Auditfortschritt.
7. Nach dem letzten Chunk führt der Worker zusätzlich ein globales `PRAGMA integrity_check` aus, bevor Snapshot und Abschlussstatus geschrieben werden.

## Belegte Fehlerursache und Sicherheitslücke im Ablauf

- Der Produktionslauf commitete 50.000 Archivzeilen einschließlich SQLite-Primary-Key-Evidence.
- Der anschließende globale `quick_check` gegen die etwa 1,2-GB-Jahresdatei überzog den kurzlebigen Request.
- Deshalb blieb MySQL bei `archive_running` ohne fortgeschriebenen Archiv-/Delete-Cursor, obwohl der SQLite-Commit bereits dauerhaft war.
- Der bestehende Nachweis ist in SQLite vorhanden, aber der MySQL-Auditfortschritt liegt derzeit erst hinter dem globalen Check und dem Delete. Die genaue neue Persistenzreihenfolge ist eine zentrale Planungsentscheidung.

### Read-only Produktions-Revalidierung vom 2026-07-27

- Auf Produktion läuft weiterhin Plugin-Version `0.1.2` mit demselben relevanten Code wie im lokalen Checkout; nach Normalisierung der Zeilenenden stimmen die SHA-256-Werte der beiden Retention-Service-Dateien überein.
- Der deployte Worker führt weiterhin pro Archiv-Chunk `PRAGMA quick_check` und nach dem letzten Chunk `PRAGMA integrity_check` aus. Es wurde noch kein dauerhafter Fix für Issue #110 deployt.
- Die aktive Datei `kiwi_retention_archive_2026.sqlite` ist inzwischen `1.331.638.272` Bytes groß.
- Die Produktionskonfiguration verwendet weiterhin die Code-Defaults:
  - maximal 50.000 Zeilen pro Worker-Chunk;
  - 60 Sekunden Zeitbudget für die Archivierungsschleife;
  - 60 Sekunden Reschedule-Verzögerung.
- Die drei zuletzt abgeschlossenen Runs zeigen:
  - Run 40, die begrenzte Recovery von `retention_3c4c12cf1b9244868d509ecbc2ffc5e4`: 66.418 Zeilen, zwei Worker-Aufrufe; der letzte extern gestartete Recovery-Worker benötigte 272 Sekunden und endete mit `integrity_check=ok`;
  - Run 41 vom 2026-07-25: 14.793 Zeilen, ein Worker-Aufruf, 55 Sekunden Worker-Laufzeit, `completed`, `integrity_check=ok`;
  - Run 42 vom 2026-07-26: 16.779 Zeilen, ein Worker-Aufruf, 57 Sekunden Worker-Laufzeit, `completed`, `integrity_check=ok`.
- Damit ist belegt, dass derselbe globale Check manchmal erfolgreich innerhalb des kurzlebigen Worker-Kontexts endet und manchmal mehrere Minuten benötigt. Die Dateigröße erzeugt keinen deterministischen Fehler bei jedem Lauf; sie macht die Laufzeit im aktuellen Request-Pfad unzuverlässig.
- Die zwei jüngsten automatischen Erfolge beweisen daher nicht, dass das Problem behoben ist. Sie zeigen nur, dass diese konkreten Läufe rechtzeitig fertig wurden.
- Die vorhandenen Auditfelder messen `quick_check` und `integrity_check` nicht getrennt. Schwankende Dateisystem-Cache-, I/O- und Serverlast sind eine plausible Erklärung für die große Laufzeitdifferenz, anhand der aktuellen Auditdaten aber keine separat bewiesene Einzelursache.

## Vorhandene technische Bausteine

- SQLite `archive_batch_rows(archive_batch_id, source_pk)` ist der persistente, eindeutige Batch-Primary-Key-Beleg.
- `archive_batches` hält Batch-Metadaten und kumulierte Archiv-Counts.
- `fetch_archived_primary_key_batch()` kann persistierte Evidence aus SQLite geordnet lesen.
- MySQL-Auditfelder umfassen unter anderem `worker_phase`, `archive_last_primary_key`, `delete_last_primary_key`, Archiv-/Delete-Counts und `archive_integrity_check`.
- Der Worker nutzt bereits gefrorene Zielgrenzen, feste Archivdatei pro Run, Locking, Count-Prüfungen und resumierbare Single-Event-Chunks.
- `Kiwi_Operational_Event_Service` bietet eine append-only Incident-Lifecycle-Abstraktion mit `raised`, `repeated` und `resolved`.
- Repository-eigene externe WP-CLI-Runner werden unter `tools/database/` per `wp --require=...` geladen; aktuell existiert noch kein eingecheckter generischer Archivgesundheits-Runner.

## Neue Prämisse und Vertrag von `tools/database/`

- `Schema zuerst, Feature danach`: Abhängige Runtime-Funktionalität darf erst nach einem erfolgreich verifizierten externen Schema-Deployment aktiviert werden.
- Normale Website-, REST-, Admin-, AJAX-, WP-Cron- und Plugin-Worker-Ausführung darf keine DDL- oder historischen einmaligen Datenänderungen ausführen.
- Externe Repository-Runner werden nicht durch `includes/bootstrap.php` geladen. Sie werden explizit mit WP-CLI `--require` gestartet.
- Der eingecheckte Referenz-Runner `kiwi-database.php` verlangt WP-CLI-2.12-Core-APIs, registriert die Operation für `plugins_loaded`, lädt WordPress genau einmal, verifiziert `plugins_loaded >= 1` und `init == 0` und beendet den Prozess vor normalen Runtime-Nebenwirkungen.
- Runner liefern maschinenlesbares JSON und einen belastbaren Exit Code. Fehler werden begrenzt und sanitisiert; ein fehlender Lifecycle- oder Klassenvertrag stoppt fail-closed.
- `kiwi database status|apply` ist der generische Schema-Deployment-Vertrag. Der Archivgesundheitscheck ist keine Schemaoperation und soll diesen Befehl nicht fachlich vermischen, sondern die gleiche externe Bootstrap-Grenze in einem eigenen Retention-Befehl wiederverwenden.
- Aktuell eingecheckt sind `kiwi-database.php`, `class-database-deployment-service.php` und `schema-contract.php`.
- Die datierten Main-Summary-Backfill- und Retention-Recovery-Runner sind untracked lokale Operationsartefakte. Sie liefern hilfreiche Sicherheitsmuster, bilden aber keine bestehende veröffentlichte Produkt-API und dürfen nicht stillschweigend Teil von #110 werden.

## Vorhandene Tests

- Archiv-Idempotenz und persistierte Batch-Primary-Key-Evidence.
- Chunk-Resume mit unveränderter Archivdatei.
- Archive-before-delete und exakte Primary-Key-Löschung.
- Teilfortschritt über mehrere Worker-Aufrufe.
- Fehler bei unvollständigen Counts, Audit-Persistenz, Delete-Count-Mismatch und Worker-Lock.
- Der aktuelle Test `worker fails quick_check before deleting chunk rows` bildet die Sicherheitskopplung an den globalen Check ab und muss durch die neue Safety-Evidence-Regel ersetzt werden.
- Noch nicht abgedeckt: Abbruchfenster nach SQLite-Commit, vor Delete und nach Delete vor Auditabschluss sowie ein langsamer/großer externer Archivcheck.

## Quellen

- GitHub Issues `#110`, `#88` und `#108`, jeweils einschließlich aktueller Kommentare.
- `docs/INDEX.md`
- `docs/operations/INDEX.md`
- `docs/operations/retention-runbook.md`
- `includes/core/class-plugin.php`
- `includes/services/class-retention-cleanup-service.php`
- `includes/services/class-retention-sqlite-archive-service.php`
- `includes/repositories/class-retention-cleanup-run-repository.php`
- `includes/services/class-operational-event-service.php`
- `tests/run-tests.php`
- Produktions-Recovery-Handoff vom 2026-07-25.
- Bestehender `graphify-out/graph.json`, anschließend gegen die exakten Quellen verifiziert.

## Entscheidungen aus dem Interview

### Entscheidung 1 – externe Ausführungsform

- Issue #110 liefert einen versionierten WP-CLI-Runner, der die SQLite-Archivdatei ausschließlich read-only öffnet und nur eng begrenzte betriebliche Statusartefakte außerhalb der Archivnutzdaten schreiben darf.
- Der Runner liegt an der externen `tools/database/`-Grenze und wird explizit über `wp --require=...` ausgeführt.
- Die Archivgesundheitsprüfung soll regelmäßig automatisch gestartet werden; ein rein manueller Aufruf reicht nicht aus.
- Der konkrete externe Scheduler, Intervall, Locking-Vertrag und die Betriebsverantwortung werden im weiteren Interview festgelegt. Es darf kein kurzlebiger WordPress-Web-/WP-Cron-Request sein.
- Der Runbook-Handoff dokumentiert Aufruf, Voraussetzungen, Exit Codes, Ergebnisprüfung und die Verantwortung des Deployment Codex/Operators.
- Der neue Befehl bleibt fachlich getrennt vom schema-spezifischen `kiwi database status|apply`.

### Verifizierter aktueller Scheduler-Stand

- Der vorhandene WordPress-Retention-Scheduler ist `kiwi_retention_cleanup_scheduler_daily`. Er plant den eigentlichen Single-Event-Worker `kiwi_retention_cleanup_worker`; beide gehören zum WordPress-WP-Cron-System.
- Dieser WP-Cron-Scheduler besitzt keine verlässlich feste Wanduhrzeit: Die erstmalige Registrierung erfolgt relativ mit `time() + 15 Minuten`, anschließend arbeitet WordPress seine fälligen Events nach dem WP-Cron-Ausführungsmodell ab.
- Der geplante Archivgesundheits-Runner soll nicht durch diesen WP-Cron gestartet werden.
- Read-only live geprüft am 2026-07-26: Im Hostinger-Account von `kiwimobile.de` sind aktuell keine serverseitigen Account-Cronjobs konfiguriert.
- Die automatische externe Ausführung muss daher als neuer, ausdrücklich autorisierter Deployment-Codex-/Operator-Schritt eingerichtet und anschließend anhand von Cron-Konfiguration und Lauf-Output verifiziert werden.

### Verifizierter aktueller Jahreswechsel

- Aktuell existiert kein eigener Jahresabschluss-, Archivregister- oder Generationenwechsel-Prozess.
- Wenn der erste Worker-Chunk eines Cleanup-Runs noch keinen persistierten `archive_db_path` besitzt, bildet `Kiwi_Retention_Sqlite_Archive_Service::build_archive_db_path()` den Dateinamen aus dem aktuellen WordPress-/MySQL-Jahr:
  - im Jahr 2026: `kiwi_retention_archive_2026.sqlite`;
  - im Jahr 2027: `kiwi_retention_archive_2027.sqlite`.
- Existiert die neue Jahresdatei noch nicht, öffnet SQLite sie beim ersten Archiv-Chunk; der Service legt anschließend die benötigten Tabellen an.
- Der Wechsel geschieht damit automatisch beim ersten neuen Cleanup-Run des neuen Kalenderjahres. Die alte Jahresdatei wird lediglich nicht mehr als Standardpfad ausgewählt.
- Es gibt heute keinen expliziten Status `closed`, keinen abschließenden speziell an den Jahreswechsel gebundenen `integrity_check` und keine persistierte Vorgänger-/Nachfolgerbeziehung zwischen den Jahresdateien.
- Ein bereits laufender Cleanup-Run bleibt an den bei seinem ersten Chunk persistierten `archive_db_path` gebunden. Beginnt er 2026 und wird 2027 fortgesetzt, schreibt er weiterhin in seine 2026-Datei; nur ein neuer Run ohne vorhandenen Pfad wählt automatisch die 2027-Datei.
- Der aktuelle Dateiname richtet sich nach dem Jahr der Archivierung, nicht nach dem ursprünglichen Erstellungsjahr jedes archivierten Quelldatensatzes.

### Entscheidung 6 – automatischer Starter auf Produktion

- Konkreter automatischer Starter ist ein benutzerdefinierter Hostinger-Account-Cronjob auf demselben Server wie WordPress und die lokale SQLite-Archivdatei.
- Der Hostinger-Cronjob startet einen eigenständigen WP-CLI-/PHP-Prozess; er ruft weder `wp-cron.php` noch den WordPress-WP-Cron auf.
- Der Implementer liefert den versionierten Runner, automatisierte Tests, den dokumentierten Befehlsvertrag und die Deployment-/Verifikationsschritte.
- Der Implementer legt keinen Live-Cronjob an und erhält dadurch keine Production-Autorisierung.
- Der Deployment Codex/Operator:
  1. verifiziert per SSH den absoluten WordPress-Pfad, Plugin-Pfad und die ausführbare WP-CLI-Binary;
  2. führt den deployed Runner zunächst manuell und read-only aus;
  3. legt danach den benutzerdefinierten Hostinger-Account-Cronjob an;
  4. verifiziert Zeitplan, gespeichertes Kommando, Exit Code und erfassten Lauf-Output.
- Im Hostinger-Formular wird `Benutzerdefiniert` verwendet. Ein vorausgefüllter PHP-Aufruf von `wp-cron.php` ist ausdrücklich nicht der Archivgesundheitsprozess.
- Der endgültige Cron-Befehl verwendet verifizierte absolute Pfade beziehungsweise WP-CLI `--path` und `--require`; Platzhalterbefehle werden nicht gespeichert.

### Entscheidung 7 – Cron-Zeitplan und automatische Verschiebeversuche

- Hostinger erhält drei identische Account-Cronjob-Aufrufe pro Tag zu festen UTC-Zeiten:
  - `01:30 UTC`
  - `02:00 UTC`
  - `02:30 UTC`
- Hostinger wertet den Cron-Zeitplan laut aktueller Betriebsdokumentation in `UTC+0` aus. Der Deployment Codex/Operator verifiziert dies im Preflight dennoch gegen die tatsächlich angezeigte Zielumgebung.
- Die lokalen Berliner Ausführungsfenster verschieben sich dadurch bewusst mit der Sommerzeit:
  - Winterzeit: `02:30`, `03:00`, `03:30` in `Europe/Berlin`;
  - Sommerzeit: `03:30`, `04:00`, `04:30` in `Europe/Berlin`.
- Es gibt keine halbjährliche manuelle Cron-Umstellung. Fachlicher Kalendertag, Wochentag, Sonntagsmodus und jährlicher Starttermin werden im Runner weiterhin in `Europe/Berlin` bestimmt.
- Montag bis Samstag ist pro Kalendertag genau ein erfolgreicher `quick_check` fällig.
- Sonntag ist statt des täglichen `quick_check` genau ein erfolgreicher `integrity_check` fällig.
- Alle drei Cronjobs starten denselben idempotenten `scheduled`-Befehl:
  - existiert bereits ein erfolgreicher fälliger Tageslauf, endet der spätere Aufruf als erfolgreicher No-op;
  - ist das Archiv durch den Worker oder einen anderen Health-Check belegt, endet der Aufruf als harmlos `deferred`, sodass der nächste Termin übernimmt;
  - gibt es bis zum finalen dritten Cron-Slot keinen erfolgreichen Lauf, wird die Prüfung als überfällig operational sichtbar und am nächsten Kalendertag vor dem normalen Tagesmodus priorisiert nachgeholt.
- Ein echter Prüffehler ist kein Lock-/Deferred-Fall und darf durch spätere Cron-Aufrufe nicht als erfolgreicher No-op oder unauffälliger Retry verdeckt werden.

### Entscheidung 2 – unmittelbares Delete-Gate und globale Archivprüfung

- MySQL-Deletes hängen nicht mehr von einem globalen SQLite-`quick_check` oder `integrity_check` der gesamten Jahresdatei ab.
- Unmittelbares Delete-Gate ist die kleine, batchbezogene Quittungsprüfung im normalen Retention-Worker.
- Die Quittung besteht aus persistierter SQLite-Primary-Key-Evidence und passenden Counts für genau den gerade archivierten Batch; sie ist keine bloße Datei-Checksumme.
- Nur die durch diese Quittung belegten MySQL-Zeilen dürfen gelöscht werden.
- Die vollständige technische Gesundheitsprüfung der Jahres-Archivdatei läuft regelmäßig und automatisch, aber unabhängig vom Retention-Worker.
- Der Retention-Worker wartet nicht auf die globale Prüfung und führt sie nicht in einem WP-Cron-/Web-Request aus.
- Die globale Prüfung bleibt als langfristiges Sicherheitsnetz erhalten; ihr genauer Prüfmodus, Scheduler, Parallelitätsvertrag und Incident-Verhalten werden noch festgelegt.

### Entscheidung 3 – automatische Wiederaufnahme bei passender Quittung

- Bricht ein Worker nach dem erfolgreichen SQLite-Commit, aber vor dem MySQL-Delete ab, soll der nächste automatische Worker-Lauf denselben Cleanup-Run wiederaufnehmen.
- Der Lauf liest die persistierte Batch-Evidence erneut und darf genau die bereits archivierten MySQL-Zeilen löschen, wenn Primary Keys und Counts vollständig passen.
- Die Wiederaufnahme erzeugt keinen unabhängigen überlappenden Cleanup-Scope und archiviert nicht blind einen neuen Datenbereich.
- Ist die Quittung unvollständig, widersprüchlich oder nicht lesbar, erfolgt kein MySQL-Delete.

### Entscheidung 4 – zweistufige Behandlung einer unpassenden Quittung

1. Derselbe eingefrorene Cleanup-Run versucht eine deterministische automatische Reparatur:
   - pro blockiertem Batch gibt es genau einen automatischen Nachziehversuch; es gibt keine unbegrenzte automatische Reparaturschleife;
   - nur eindeutig fehlende Archivzeilen oder Batch-Evidence werden aus den weiterhin vorhandenen MySQL-Quelldaten idempotent nachgezogen;
   - vorhandene Archivdaten werden nicht blind überschrieben oder gelöscht;
   - anschließend werden Primary-Key-Evidence und Counts erneut vollständig geprüft;
   - bei passender Quittung wird der Delete-Pfad freigegeben.
2. Bleibt die Quittung unpassend:
   - es werden keine MySQL-Quelldaten gelöscht;
   - der Run bleibt sichtbar blockiert und kein überlappender Cleanup-Run überspringt ihn;
   - ein strukturierter Operational Incident wird persistiert; eine normale PHP-Logzeile ist nicht der alleinige Nachweis;
   - eine spätere Reparatur erfolgt nur über einen begrenzten, repository-eigenen externen Recovery-Prozess mit exakten Run-/Cutoff-/Primary-Key-Grenzen, Locking und Postflight – nicht durch improvisiertes direktes SQL;
   - nach erfolgreicher Recovery wird derselbe Run fortgesetzt; danach kann ein späterer Run den zwischenzeitlich aufgelaufenen Rückstand verarbeiten.

### Entscheidung 5 – zweistufige globale Archivgesundheitsprüfung

- Der automatische externe Archivgesundheitsprozess unterstützt zwei klar getrennte Prüfstufen:
  - `quick_check` als häufigere, schnellere Gesamtprüfung;
  - `integrity_check` als seltenere, gründlichere Tiefenprüfung.
- Beide Prüfungen laufen außerhalb von WordPress-Web-/WP-Cron-Requests über den externen WP-CLI-Runner.
- Konkrete Intervalle, Uhrzeiten, Retry-/Deferred-Verhalten, Sonderläufe und die Auswahl aktiver beziehungsweise abgeschlossener Jahresarchive werden noch festgelegt.

## Neu erkannte Reparaturgrenze

- Der aktuelle Repository-Stand kann SQLite-Fehler erkennen, enthält aber keinen dokumentierten oder implementierten Prozess, der eine beschädigte Jahresarchivdatei zuverlässig repariert oder aus einem Backup wiederherstellt.
- `archive_batch_rows` ist eine Löschquittung aus Batch-ID und Source-Primary-Key. Sie enthält keine zweite vollständige Kopie der archivierten Nutzdaten.
- Sind Quelldaten nach erfolgreicher Quittungsprüfung bereits aus MySQL gelöscht, kann eine beschädigte Archivdatei allein aus Quittung und MySQL-Audit nicht vollständig rekonstruiert werden.
- SQLite-Salvage-Verfahren wie `.recover` können möglicherweise lesbare Inhalte in eine neue Datei retten, garantieren aber ohne unabhängige vollständige Referenz weder Vollständigkeit noch Datenidentität.
- Ein verlässlich wiederherstellbarer Prozess braucht daher entweder:
  - eine nachweislich brauchbare unabhängige Sicherung der Archivdaten, oder
  - eine zweite vollständige, unabhängige Kopie der noch nicht gesicherten Batchdaten.
- Der aktuelle Default-Pfad der Jahresarchive liegt unter `/home/.../kiwi-backend-archives/db-retention/sqlite/` und damit außerhalb von `public_html`.
- Hostinger dokumentiert automatische Datei-Backups für Web-/Cloud-Hosting, aber für dieses konkrete Konto und diesen benutzerdefinierten Archivpfad ist noch nicht verifiziert:
  - welcher Backup-Tarif und welche Aufbewahrungsdauer tatsächlich aktiv sind;
  - ob das Archivverzeichnis und eine konsistente SQLite-Datei einschließlich relevanter WAL-Daten enthalten sind;
  - ob sich eine Sicherung herunterladen und in eine separate Testdatei zurückspielen lässt;
  - ob diese Testdatei den erwarteten `integrity_check` sowie die Archiv-/Quittungsabgleiche besteht.
- Eine Hostinger-Sicherung darf deshalb erst nach einem read-only Download-/Restore-Test als Wiederherstellungsquelle in den Plan aufgenommen werden. Eine bloße Anzeige „Backup vorhanden“ genügt nicht.
- Issue #110 führt keinen zusätzlichen Archiv-Backup-Vertrag ein. Statt einer garantierten Wiederherstellung wird bei einem bestätigten Defekt auf eine neue Archivgeneration gewechselt; die Grenzen dieses Modells sind unter Entscheidung 8 festgehalten.

### Entscheidung 8 – automatischer Generationswechsel bei bestätigtem Archivdefekt

- Bestätigt `quick_check` oder `integrity_check` einen echten Defekt, wird genau die betroffene Archivdatei persistent als fehlerhaft und quarantänisiert markiert.
- Die fehlerhafte Datei wird weder überschrieben noch automatisch repariert oder gelöscht. Sie bleibt unverändert für Untersuchung und mögliche spätere Best-Effort-Datenrettung erhalten.
- Der externe Health-Runner verändert die fehlerhafte SQLite-Datei nicht. Er persistiert den Gesundheitsstatus und den Operational Incident.
- Der Archivierer legt für dasselbe Kalenderjahr automatisch eine neue, eindeutig versionierte Archivgeneration an, beispielsweise `kiwi_retention_archive_2026_part_2.sqlite`.
- Neue Cleanup-Runs verwenden ausschließlich die neue aktive Generation. Eine bekannte fehlerhafte Generation darf nicht erneut Ziel neuer Archiv- oder Delete-Arbeit werden.
- Ein bereits laufender Cleanup-Run bleibt an seinen ursprünglich persistierten `archive_db_path` und die dortige Batch-Evidence gebunden. Er darf nicht mitten im Batch still auf die neue Generation wechseln.
- Für einen von der Quarantäne betroffenen laufenden Run gilt:
  - noch nicht aus MySQL gelöschte Daten bleiben erhalten;
  - der Run bleibt blockiert und operational sichtbar;
  - sein genauer begrenzter Übergabe-/Recovery-Pfad in die neue Generation wird noch festgelegt.
- Der Generationswechsel hält die zukünftige Retention arbeitsfähig, stellt aber Daten, die nur noch in der beschädigten alten Datei vorhanden sind, nicht automatisch wieder her.
- Dieses Restrisiko wird bewusst akzeptiert. Ein Archiv-Backup oder eine zweite vollständige Archivkopie ist nicht Teil von Issue #110.

### Entscheidung 9 – Nachfolge-Run für einen von Quarantäne betroffenen laufenden Run

- Wird die Archivgeneration eines laufenden Cleanup-Runs quarantänisiert, darf dieser Run seinen persistierten `archive_db_path` nicht auf die neue Generation umschreiben.
- Der alte Run beendet jede weitere Archiv- und Delete-Arbeit und erhält einen dauerhaft nachvollziehbaren blockierten beziehungsweise abgelösten Abschlusszustand.
- Sobald die neue Archivgeneration aktiv ist, wird automatisch genau ein verknüpfter Nachfolge-Run erzeugt.
- Der Nachfolge-Run erhält eine neue Run-ID, eine neue Archiv-Batch-ID und die neue Archivdatei. Alter und neuer Run bleiben im Audit eindeutig miteinander korreliert.
- Sein Arbeitsbereich bleibt auf die ursprüngliche Source, den Cutoff und den bereits eingefrorenen `target_max_primary_key` begrenzt.
- Er verarbeitet ausschließlich Datensätze aus diesem Scope, die noch in MySQL vorhanden sind:
  - diese Daten werden in die neue Generation archiviert;
  - die neue Batch-Quittung wird vollständig geprüft;
  - nur quittungsbelegte Daten werden anschließend aus MySQL gelöscht.
- Bereits aus MySQL gelöschte Daten werden nicht aus der beschädigten Archivgeneration in die neue Generation kopiert und nicht als erfolgreich wiederhergestellt dargestellt.
- Bis der verknüpfte Nachfolge-Run abgeschlossen oder sichtbar blockiert ist, darf kein unabhängiger überlappender Cleanup-Run denselben Source-Scope überspringen.
- Quarantäne, Ablösung und Nachfolge-Run werden im Operational Incident und im Cleanup-Audit sichtbar; die genaue persistierte Feld-/Statusform wird noch festgelegt.

### Entscheidung 10 – Quarantäne nur bei einem bestätigten SQLite-Defekt

- Quarantäne und automatischer Generationswechsel dürfen nur ausgelöst werden, wenn SQLite die angeforderte Prüfung vollständig ausführt und dabei einen tatsächlichen Defekt der Archivdatei meldet.
- Ein vollständig ausgeführter Check mit dem Ergebnis `ok` bestätigt den erfolgreichen Gesundheitslauf.
- Folgende Ergebnisse sind ausdrücklich kein bestätigter Archivdefekt und lösen keinen Generationswechsel aus:
  - Health-Runner konnte nicht gestartet oder nicht korrekt gebootstrapped werden;
  - SQLite/PDO oder die Archivdatei konnte technisch nicht geöffnet werden;
  - der Prozess wurde unterbrochen oder lief in ein Zeitlimit;
  - ein Worker-/Health-Lock war aktiv und der Lauf wurde `deferred`;
  - das Ergebnis fehlt oder ist nicht eindeutig als vollständig ausgeführter SQLite-Check klassifizierbar.
- Solche unvollständigen oder technischen Läufe werden als `deferred` beziehungsweise `inconclusive` sichtbar gemacht und nach dem festgelegten Cron-/Retry-Vertrag erneut versucht.
- Ein `inconclusive`-Ergebnis darf den letzten belegten Gesundheitsstatus nicht als Defekt überschreiben und darf keinen neuen aktiven Archivpfad erzeugen.
- Ein echter SQLite-Defektbefund bleibt ein Operational Incident und führt genau einmal zur Quarantäne der betroffenen Generation sowie zur Aktivierung des Generationswechsel-Pfads.

### Entscheidung 11 – Deletes laufen bei unklarer Prüfung weiter

- Wiederholte `inconclusive`- oder `deferred`-Gesundheitsläufe blockieren keine MySQL-Deletes, sofern die unmittelbare Batch-Quittungsprüfung des Retention-Workers vollständig erfolgreich ist.
- Damit bleibt die festgelegte Trennung bestehen: Der globale Archivcheck ist ein unabhängiges langfristiges Sicherheitsnetz und kein indirektes Delete-Gate.
- Sobald der fällige Health-Check nach dem letzten Cron-Zeitfenster nicht erfolgreich abgeschlossen ist, bleibt ein Operational Incident offen.
- Weitere gleichartige unklare Läufe werden unter derselben Korrelation als Wiederholungen sichtbar; sie erzeugen weder täglich unabhängige Incidents noch einen falschen Defektbefund.
- Der Incident wird erst nach einer späteren vollständig ausgeführten erfolgreichen SQLite-Prüfung qualifiziert aufgelöst. Ein bloßer Prozessstart, Retry oder `deferred`-Lauf reicht nicht.
- Meldet eine später vollständig ausgeführte Prüfung stattdessen einen echten Defekt, wechselt der Ablauf vom unklaren Health-Incident in den Quarantäne-/Generationswechsel-Pfad.

### Entscheidung 12 – aktive Generation regelmäßig, alle Archive einmal jährlich

- Die regelmäßigen automatischen `quick_check`-/`integrity_check`-Läufe nach Entscheidung 7 prüfen die aktuell aktive Archivgeneration.
- Zusätzlich wird einmal pro Kalenderjahr ein vollständiger `integrity_check` über alle vorhandenen, nicht bereits quarantänisierten SQLite-Archivdateien durchgeführt.
- Der jährliche Lauf arbeitet strikt seriell. Eine vollständige Archivdatei ist jeweils eine Arbeitseinheit beziehungsweise der vom Benutzer gemeinte „Chunk“; mehrere Dateien werden niemals parallel geprüft.
- `PRAGMA integrity_check` wird innerhalb einer einzelnen Archivdatei vollständig ausgeführt. Die Prüfung einer Datei wird nicht künstlich in Datenbereiche aufgeteilt, weil dies nicht mehr derselbe vollständige SQLite-Integritätsnachweis wäre.
- Erst nach Abschluss oder eindeutigem `deferred`-/`inconclusive`-Ergebnis einer Datei darf der jährliche Prozess zur nächsten Datei übergehen beziehungsweise bei einem späteren Cron-Aufruf fortsetzen.
- Bereits quarantänisierte Dateien bleiben mit ihrem bekannten Defektstatus sichtbar und werden im jährlichen Normaldurchlauf übersprungen; eine erneute Prüfung erfolgt nur gezielt für Best-Effort-Datenrettung.
- Der genaue jährliche Starttermin, die Fortsetzung über mehrere Cron-Aufrufe und der minimale Fortschrittsnachweis werden noch festgelegt.

### Entscheidung 13 – nicht wartender gemeinsamer Archiv-Lock

- Health-Check und Retention-Archivierer verwenden denselben exklusiven Lock für die aktive Archivgeneration; gleichzeitiges Prüfen und Schreiben ist ausgeschlossen.
- Kein Prozess wartet blockierend auf die Freigabe dieses Locks.
- Findet der Health-Runner das Archiv belegt, führt er keine SQLite-Prüfung aus und beendet den Versuch als harmlos `deferred`.
- Die drei festgelegten Hostinger-Cron-Termine um `01:30`, `02:00` und `02:30 UTC` sind die vollständigen automatischen Versuche des jeweiligen Tages.
- Findet der WordPress-Retention-Worker einen laufenden Health-Check vor, führt er in diesem Aufruf weder Archivierung noch MySQL-Delete aus und plant seinen Worker-Aufruf automatisch neu.
- Ein belegter Lock ist kein Defektbefund und erzeugt für sich allein keinen Fehler-Incident.
- Gibt es bis einschließlich des finalen dritten Cron-Slots keinen vollständig erfolgreichen fälligen Health-Check, bleibt die Prüfung überfällig, der Operational Incident wird geöffnet beziehungsweise wiederholt und der nächste Tageslauf priorisiert den Rückstand.
- Es wird kein länger laufender wartender CLI-Prozess eingesetzt; dadurch bleiben Prozessanzahl, Laufzeit und Cron-Verhalten einfach und nachvollziehbar.

### Entscheidung 14 – betriebssystemgestützte Sperrdatei pro Archivgeneration

- Jede Archivgeneration erhält eine kleine dedizierte Sperrdatei in ihrem Archivverzeichnis.
- Health-Runner und Retention-Archivierer verwenden dieselbe zentrale Lock-Abstraktion und versuchen vor jedem Öffnen beziehungsweise Bearbeiten der SQLite-Datei einen exklusiven, nicht blockierenden Betriebssystem-Lock zu erhalten.
- Der Lock wird über den geöffneten Datei-Handle für die vollständige Prüf- oder Schreiboperation gehalten und anschließend explizit freigegeben.
- Endet oder stürzt der Prozess ab, gibt das Betriebssystem den Lock automatisch frei. Die physische Sperrdatei darf bestehen bleiben; „besetzt“ bedeutet ausschließlich, dass ein Prozess den Betriebssystem-Lock aktuell hält.
- Die Sperrdatei wird nicht als Statusflag erstellt und gelöscht. Dadurch kann eine liegengebliebene Datei keinen falschen dauerhaften Besetzt-Zustand erzeugen.
- Kann der Lock nicht sofort erworben werden, gelten die nicht wartenden `deferred`-/Reschedule-Regeln aus Entscheidung 13.
- Dateipfad, Schreibrechte und die tatsächliche Unterstützung des Lock-Verhaltens werden im lokalen Mehrprozess-Prototyp und im Deployment-Preflight auf Hostinger verifiziert.
- Schlägt die Lock-Infrastruktur selbst technisch fehl, darf der Prozess SQLite nicht ohne Schutz öffnen; der Lauf endet fail-closed als technischer beziehungsweise `inconclusive`-Fehler, nicht als Archivdefekt.

### Entscheidung 15 – keine zusätzliche Archivregister-Tabelle

- Issue #110 führt keine weitere MySQL-Tabelle für Archivgenerationen, `closed`-Status oder Vorgänger-/Nachfolgerbeziehungen ein.
- Der normale Jahreswechsel bleibt aus dem Kalenderjahr und dem Dateinamen ableitbar:
  - `kiwi_retention_archive_2026.sqlite`;
  - `kiwi_retention_archive_2027.sqlite`.
- Für einen Defekt innerhalb des aktiven Kalenderjahres werden weitere Generationen durch eine einfache Teilnummer benannt, beispielsweise `kiwi_retention_archive_2027_part_2.sqlite`.
- Ein kleiner atomar geschriebener Quarantäne-Marker neben der betroffenen Datei kennzeichnet einen bestätigten Defekt. Die ausführlichen Fehler- und Lifecycle-Daten bleiben im bestehenden Operational-Event-System; der Marker ist kein neues Datenregister.
- Für das aktuelle Kalenderjahr ist die höchste vorhandene, nicht quarantänisierte Generation der aktive Zielkandidat. Ist die höchste Generation quarantänisiert, legt der Archivierer vor weiterer Arbeit die nächste Teilnummer an.
- Ein neuer Teil wird nur für eine beschädigte aktuell aktive Jahresgeneration erzeugt. Wird beim jährlichen Gesamtlauf ein altes Archiv als defekt bestätigt, wird dieses quarantänisiert und als Incident sichtbar, aber es wird keine neue leere Generation für das alte Jahr angelegt.
- Ein laufender Cleanup-Run verwendet weiterhin ausschließlich seinen bereits persistierten exakten `archive_db_path`; die Dateinamensableitung darf keinen laufenden Run still umleiten.
- Ein gesonderter `closed`-Status und eine gespeicherte Beziehung „2026 ist Vorgänger von 2027“ werden bewusst nicht eingeführt, weil diese Information für den gewählten einfachen Ablauf aus dem Dateinamen und Kalenderjahr hervorgeht.

### Entscheidung 16 – jährlicher Gesamtlauf ab 2. Januar

- Der jährliche vollständige Archivcheck beginnt fachlich am 2. Januar in der Zeitzone `Europe/Berlin`.
- Er verwendet dieselben drei Hostinger-Cron-Aufrufe wie der normale Health-Prozess:
  - der erste Cron-Slot bleibt für die fällige regelmäßige Prüfung der aktiven Archivgeneration reserviert;
  - der zweite Cron-Slot verarbeitet höchstens eine vollständige noch ausstehende Archivdatei des jährlichen Laufs;
  - der finale dritte Cron-Slot verarbeitet höchstens die nächste vollständige noch ausstehende Archivdatei.
- Sind danach noch Dateien offen, wird der jährliche Lauf an den folgenden Kalendertagen nach demselben Muster fortgesetzt, bis alle zu Beginn des Zyklus gefundenen, nicht quarantänisierten SQLite-Archivdateien vollständig geprüft wurden.
- Eine durch Lock belegte Datei wird nicht übersprungen oder parallel geprüft. Sie bleibt ausstehend und wird bei einem späteren Cron-Aufruf erneut versucht.
- Der Fortschritt wird ohne neue Datenbanktabelle in einer kleinen atomar ersetzten Statusdatei im Archivverzeichnis gespeichert. Sie enthält mindestens:
  - Zyklusjahr;
  - Start- und Abschlusszeit;
  - stabil sortierte relative Archivdateinamen;
  - Status und Prüfmodus pro Datei;
  - letztes eindeutig abgeschlossenes Ergebnis.
- Die Statusdatei enthält keine Archivnutzdaten, Credentials oder frei manipulierbare absolute Fremdpfade.
- Wiederholte Cron-Aufrufe sind idempotent: Bereits im aktuellen Jahreszyklus erfolgreich geprüfte Dateien werden nicht erneut bearbeitet; `deferred`- oder `inconclusive`-Dateien bleiben offen.
- Erst wenn jede eingeplante Datei ein endgültiges Ergebnis besitzt, wird der Jahreszyklus als abgeschlossen dokumentiert. Ein bestätigter Defekt folgt weiterhin dem Quarantäne-/Incident-Vertrag.

### Entscheidung 17 – korrigierte Quittungs- und Delete-Reihenfolge

Pro Retention-Batch gilt folgende eindeutige Reihenfolge:

1. SQLite speichert die vollständigen Archivdatensätze und die zugehörigen `archive_batch_rows` mit den exakten Source-Primary-Keys gemeinsam in einer Transaktion.
2. Nach dem erfolgreichen Commit liest der Worker die persistierte SQLite-Quittung und prüft Batch-ID, erwartete Primary Keys und Counts vollständig.
3. Erst nach dieser erfolgreichen Prüfung aktualisiert der Worker die bereits vorhandene MySQL-Auditzeile in `wp_kiwi_retention_cleanup_runs`:
   - `worker_phase=receipt_verified`;
   - `archive_batch_id`;
   - `archive_db_path`;
   - geprüfte kumulierte Archiv-Counts und Archivcursor.
4. Die MySQL-Auditzeile speichert keine zweite Kopie der vollständigen Primary-Key-Liste. Sie ist nur der persistierte Zwischenstand, dass die weiterhin in SQLite vorhandene Quittung erfolgreich geprüft wurde.
5. Vor einem Delete liest beziehungsweise bestätigt der Worker die SQLite-Quittung erneut, wenn der Prozess zwischenzeitlich neu gestartet wurde. Das MySQL-Audit allein autorisiert keinen Delete.
6. Der Worker verwendet die Primary Keys aus der verifizierten SQLite-Quittung, um genau die zugehörigen vollständigen Datensätze aus der jeweiligen MySQL-Originaltabelle zu löschen.
7. Weder die archivierten Nutzdaten noch die `archive_batch_rows` werden dabei aus SQLite gelöscht oder verändert; beides bleibt dauerhaft erhalten.
8. Erst nach dem MySQL-Delete persistiert der Worker den abgeschlossenen Delete-Fortschritt, die Counts und den Delete-Cursor in der bestehenden Auditzeile.

Die Formulierung „der Worker löscht die IDs aus der Quittung“ ist ausdrücklich falsch und darf weder in Implementierungskommentaren noch Dokumentation verwendet werden.

### Entscheidung 18 – Reconciliation nach Delete ohne abschließenden Auditfortschritt

- Dieser Sonderpfad wird nur verwendet, wenn ein neu gestarteter Worker aus der persistierten Run-Phase erkennt, dass die Quittung erfolgreich geprüft wurde, aber der abschließende Delete-Fortschritt fehlt.
- Der Worker darf nicht annehmen, ob der vorherige Prozess vor oder nach dem MySQL-Delete abgebrochen ist.
- Er liest und validiert deshalb erneut die persistierte SQLite-Quittung als maßgebliche Liste der betroffenen Source-Primary-Keys.
- Anschließend ermittelt er in der MySQL-Originaltabelle, welche dieser quittungsbelegten Datensätze tatsächlich noch vorhanden sind:
  - sind alle noch vorhanden, hat der Delete noch nicht stattgefunden;
  - ist nur ein Teil vorhanden, wurde der Delete nur teilweise abgeschlossen;
  - ist keiner mehr vorhanden, war der Delete vollständig erfolgreich und nur der Auditabschluss fehlt.
- Der Worker löscht ausschließlich die noch vorhandenen, erneut quittungsgeprüften MySQL-Originaldatensätze.
- Sind bereits keine quittungsbelegten Source-Datensätze mehr vorhanden, führt der Worker keinen weiteren Delete aus und reconciliert nur den fehlenden Auditfortschritt.
- Nach einem erforderlichen Rest-Delete prüft der Worker, dass keine quittungsbelegten Datensätze mehr in der MySQL-Originaltabelle vorhanden sind, bevor er den Batch als vollständig gelöscht notiert.
- Die kumulierten Audit-Counts dürfen beim Wiederanlauf nicht doppelt erhöht werden. Der Batch wird unabhängig von der Zahl der im Wiederanlauf noch gelöschten Zeilen genau einmal als vollständig verarbeitet angerechnet.
- Kann die SQLite-Quittung nicht erneut vollständig bestätigt werden, bleibt der Delete gesperrt und der Ablauf folgt dem bereits beschlossenen Quittungsmismatch-/Incident-Pfad.
- Ein normal vollständig auditierter Delete durchläuft diese Reconciliation nicht; sie dient ausschließlich der Rekonstruktion eines wegen Prozessabbruchs nicht dokumentierten Ergebnisses.

### Entscheidung 19 – sichere automatische Wiederaufnahme desselben Cleanup-Runs

- Ein unvollständig zurückgelassener Cleanup-Run wird nicht allein aufgrund seines Alters terminal als `failed` abgeschlossen.
- „Unvollständig zurückgelassen“ bedeutet:
  - der zuvor gestartete Worker-Prozess existiert nicht mehr;
  - sein exklusiver Lock ist frei;
  - die Auditzeile wurde länger als die konfigurierte Stale-Schwelle nicht aktualisiert;
  - der Run besitzt weiterhin keinen qualifizierten Abschluss.
- Ist der Lock noch belegt oder der Audit-Heartbeat frisch, wird der Run nicht übernommen, weil der ursprüngliche Prozess noch aktiv sein kann.
- Besitzt der zurückgelassene Run einen vollständigen eingefrorenen Scope und ausreichend persistierte Archiv-/Quittungsevidence, wird genau derselbe Run mit derselben Run-ID automatisch wiederaufgenommen.
- Die Wiederaufnahme ermittelt anhand von Auditphase, SQLite-Quittung und tatsächlichem MySQL-Source-Zustand, ob sie archivieren, die Quittung nachziehen, quittungsbelegte Source-Datensätze löschen oder nur fehlenden Auditfortschritt reconciliieren muss.
- Nur wenn die benötigte Evidence fehlt, widersprüchlich ist oder auch der einmalige automatische Nachziehversuch scheitert, bleibt der Run blockiert und ein Operational Incident wird geöffnet.
- Ein normaler Lock-Konflikt oder ein noch aktiver Worker darf nicht als stale Recovery fehlklassifiziert werden.

### Entscheidung 20 – Incident-Typ für nicht abgeschlossene Archivprüfung

- Der sprechende Event-Typ für einen bis zum Ende des täglichen Prüfzeitfensters nicht erfolgreich abgeschlossenen Health-Check lautet:
  - `retention_archive_health_check_incomplete`
- Der Event-Typ beschreibt bewusst den gemeinsamen Zustand „keine vollständig abgeschlossene eindeutige SQLite-Prüfung“ und nicht nur dessen zeitliche Überfälligkeit.
- Er umfasst insbesondere:
  - Prüfung wegen Lock nicht gestartet;
  - Runner-/Bootstrap-Startfehler;
  - Prozessabbruch oder Timeout;
  - vollständig verwertbares Ergebnis fehlt beziehungsweise bleibt `inconclusive`.
- Der konkrete technische Grund wird separat und normalisiert gespeichert, beispielsweise:
  - `lock_deferred`;
  - `runner_start_failed`;
  - `timeout`;
  - `result_inconclusive`.
- Die ersten beiden Cron-Versuche erzeugen noch keinen Incident, solange der finale Versuch desselben Tages offen ist. Erst wenn bis einschließlich des finalen dritten Cron-Slots kein eindeutiger Erfolg oder bestätigter Defekt vorliegt, wird der Incident `raised` beziehungsweise für eine bereits offene Korrelation `repeated`.
- Ein bestätigter SQLite-Defekt verwendet einen getrennten Event-Typ; `retention_archive_health_check_incomplete` darf niemals einen Defekt vortäuschen.

### Entscheidung 21 – Auflösung des Incomplete-Incidents

- Ein offener `retention_archive_health_check_incomplete`-Incident wird nur durch eine später vollständig ausgeführte SQLite-Prüfung qualifiziert aufgelöst.
- Endet die vollständige Prüfung mit `ok`, wird der Incomplete-Incident als `resolved` dokumentiert.
- Bestätigt die vollständige Prüfung stattdessen einen SQLite-Defekt, wird der Incomplete-Incident ebenfalls als `resolved` dokumentiert, weil der Zustand „Prüfung nicht abgeschlossen“ nicht mehr besteht.
- Im Defektfall wird im selben Health-Lauf zusätzlich der getrennte Corruption-Incident für genau die betroffene Archivgeneration `raised`.
- Ein weiterer Startversuch, ein Lock-`deferred`, ein Timeout oder ein anderes `inconclusive`-Ergebnis darf den Incomplete-Incident nicht auflösen.
- Wiederholungen und Auflösung verwenden eine stabile Korrelation für die betroffene Archivgeneration, sodass nicht täglich unabhängige Incidents entstehen.

### Entscheidung 22 – Event-Typ für bestätigten SQLite-Defekt

- Der sprechende Event-Typ für einen durch eine vollständig ausgeführte SQLite-Prüfung tatsächlich bestätigten Defekt lautet:
  - `retention_archive_corruption_detected`
- Der Event darf ausschließlich für einen echten SQLite-Defektbefund verwendet werden.
- Technische Startfehler, Locks, Timeouts, fehlende Resultate und andere unvollständige Prüfungen dürfen diesen Event-Typ nicht erzeugen; sie verbleiben im `retention_archive_health_check_incomplete`-Vertrag.
- Die Event-Korrelation identifiziert die exakte betroffene Archivgeneration und darf nicht still auf die anschließend neu angelegte Generation übertragen werden.
- Der Event-Kontext enthält mindestens den relativen Archivdateinamen, Prüfmodus, normalisierten SQLite-Befund, Prüfzeitpunkt und den Status von Quarantäne beziehungsweise Generationswechsel. Absolute sensitive Serverpfade werden nicht unmaskiert persistiert.

### Entscheidung 23 – bestehender append-only Operational-Event-Speichervertrag

- `retention_archive_health_check_incomplete` und `retention_archive_corruption_detected` werden in der vorhandenen Tabelle `wp_kiwi_operational_events` gespeichert; der tatsächliche WordPress-Tabellenpräfix bleibt installationsabhängig.
- Es werden dafür weder eine neue Tabelle noch eine neue `resolution_reason`-Spalte eingeführt.
- Der bestehende append-only Lifecycle wird verwendet:
  - `event_type` speichert den normalisierten Incident-Typ;
  - `lifecycle_action` speichert `raised`, `repeated` oder `resolved`;
  - `correlation_key` verbindet alle Zeilen desselben fortlaufenden Incidents;
  - `reference_type=retention_archive` und `reference_id` identifizieren die betroffene relative Archivgeneration;
  - `message` enthält die kurze menschenlesbare Zusammenfassung;
  - `context_json` enthält kleine strukturierte Diagnosen und bei der Auflösung `resolution_reason`.
- Die ursprüngliche `raised`- oder `repeated`-Zeile wird niemals aktualisiert oder überschrieben. Eine Auflösung wird als neue `resolved`-Zeile mit demselben `event_type` und derselben `correlation_key` angehängt.
- Für den vorgesehenen Corruption-Abschluss enthält `context_json` mindestens:
  - `resolution_reason=quarantined_and_replaced`;
  - relativer Dateiname der quarantänisierten Generation;
  - relativer Dateiname der Ersatzgeneration;
  - qualifizierender Recovery-/Nachfolge-Run, soweit vorhanden.
- Stabile Idempotency Keys verhindern doppelte Event-Zeilen bei einem wiederholten Cron- oder Worker-Aufruf.
- Der vorhandene zentrale Credential- und Kontext-Sanitizer bleibt verbindlich; absolute sensitive Pfade und Secrets dürfen nicht unmaskiert persistiert werden.

### Entscheidung 24 – qualifizierte Auflösung des Corruption-Incidents

- `retention_archive_corruption_detected` wird beim vollständig bestätigten SQLite-Defekt als `raised` angelegt und bleibt während Quarantäne und Generationswechsel offen.
- Das bloße Schreiben des Quarantäne-Markers oder Anlegen einer leeren Ersatzdatei qualifiziert noch keine Auflösung.
- Der Incident wird erst als `resolved` dokumentiert, wenn die Ersatzgeneration ihren ersten vollständigen Retention-Batch erfolgreich durchlaufen hat:
  - Archivdaten und SQLite-Quittung committed;
  - Quittung vollständig verifiziert;
  - zugehörige MySQL-Originaldatensätze sicher verarbeitet;
  - abschließender Auditfortschritt persistiert.
- Die `resolved`-Zeile verwendet `resolution_reason=quarantined_and_replaced`.
- `resolved` bedeutet ausschließlich, dass die betriebliche Retention-Funktion über die Ersatzgeneration wiederhergestellt ist. Es bedeutet nicht, dass die alte Archivdatei repariert oder wieder gesund ist.
- Die alte Generation bleibt auch nach der Auflösung dauerhaft automatisch quarantänisiert. Ein späterer erfolgreicher manueller Check reaktiviert sie nicht automatisch.
- Eine Reaktivierung einer quarantänisierten Generation wäre eine ausdrücklich autorisierte manuelle Ausnahmeoperation und ist nicht Bestandteil von Issue #110.

### Entscheidung 25 – genau eine maschinenlesbare Runner-Ausgabe

- Jeder Aufruf des externen Archiv-Health-Runners schreibt genau ein vollständiges JSON-Dokument an die Standardausgabe (`stdout`).
- Vor oder nach diesem JSON werden keine zusätzlichen unstrukturierten Status-, Hinweis- oder Fehlerzeilen ausgegeben. Technische Hinweise, Warnungen und Fehlerdetails müssen als normalisierte Felder innerhalb desselben JSON-Dokuments erscheinen.
- Der Runner legt keine zusätzliche JSON-Logdatei an.
- Bei einem manuellen WP-CLI-Aufruf erscheint das JSON im Terminal; beim Hostinger-Cron-Aufruf bleibt die Standardausgabe als dort einsehbare Cron-Ausgabe erhalten. Der Deployment-Befehl darf die Ausgabe deshalb nicht nach `/dev/null` umleiten.
- Das JSON beschreibt das Ergebnis genau dieses Aufrufs und ist die maschinenlesbare Schnittstelle für Deployment-Prüfung und Fehlerdiagnose. Es ersetzt keine dauerhafte fachliche Zustandsführung.
- Dauerhafter Zustand verbleibt an den bereits festgelegten Stellen:
  - Incidents und deren Lifecycle in `wp_kiwi_operational_events`;
  - Jahreskampagnen-Fortschritt und Quarantäne in kleinen atomar geschriebenen Status- beziehungsweise Markerdateien;
  - Cleanup-/Quittungs-/Delete-Fortschritt in `wp_kiwi_retention_cleanup_runs` und der SQLite-Quittung.
- Auch bei einem PHP-/WP-CLI-Fehler, soweit der Runner den Fehler kontrolliert behandeln kann, soll er genau dieses eine JSON-Dokument mit einem eindeutigen Fehlerstatus ausgeben. Unerwartete Fehler vor erfolgreichem Runner-Bootstrap müssen zusätzlich über Exit Code und Operations-Anleitung diagnostizierbar bleiben.

### Entscheidung 26 – einfacher dreistufiger Exit-Code-Vertrag

- Der Runner verwendet drei dokumentierte Exit Codes; die detaillierte Ursache bleibt Bestandteil des einen JSON-Dokuments:
  - Exit Code `0`: Der Runner-Aufruf wurde ordnungsgemäß und mit einem verwertbaren Ergebnis abgeschlossen.
  - Exit Code `1`: Es kam zu keinem vollständigen Prüfergebnis, weil der Versuch vorübergehend nicht ausgeführt oder nicht abgeschlossen werden konnte; ein späterer Cron-Termin darf erneut versuchen.
  - Exit Code `2`: Ein technischer, Bootstrap-, Konfigurations- oder Vertragsfehler erfordert Untersuchung.
- Exit Code `0` bewertet die korrekte Ausführung des Runners, nicht die Gesundheit der geprüften Archivdatei. Daher endet auch ein vollständig bestätigter Defekt mit `0`, wenn Befund, Incident und vorgesehene Quarantäne-/Generationsreaktion erfolgreich persistiert wurden.
- Typische Exit-Code-`1`-Fälle sind eine belegte Archiv-Sperre, ein kontrolliert abgebrochener Prüflauf beziehungsweise ein Timeout ohne eindeutigen SQLite-Befund.
- Typische Exit-Code-`2`-Fälle sind ungültige Konfiguration, fehlgeschlagener WordPress-/Runner-Bootstrap, nicht lesbarer erforderlicher Zustand, ungültige Statusdatei oder ein Fehler beim Persistieren eines verpflichtenden Incidents beziehungsweise Ergebnisses.
- Ein kontrollierter Exit `1` oder `2` muss nach Möglichkeit weiterhin genau ein valides JSON-Dokument liefern. Ein Fehler, der bereits vor der kontrollierten Fehlerbehandlung auftritt, kann einen abweichenden prozessseitigen Exit Code erzeugen und wird im Operations-Runbook als Sonderfall beschrieben.
- Für die Incident-Erzeugung bleibt das gesamte tägliche Versuchsfenster maßgeblich:
  - Exit `1` oder `2` beim ersten beziehungsweise zweiten Cron-Slot führt noch nicht allein zu `retention_archive_health_check_incomplete`;
  - liegt bis einschließlich des finalen dritten Cron-Slots kein eindeutiger Abschluss vor, wird der bereits beschlossene Incomplete-Incident erzeugt beziehungsweise wiederholt.

### Entscheidung 27 – feste versionierte JSON-Struktur

- Die genau eine `stdout`-Ausgabe ist ein kompaktes einzeiliges JSON-Dokument mit einer stabilen und explizit versionierten Struktur.
- Pflichtfelder sind mindestens:
  - `schema_version`: Version des Ausgabeversprechens, initial `1`;
  - `status`: zusammenfassender Zustand des Runner-Aufrufs;
  - `exit_code`: der vom kontrollierten Runner zurückgegebene Code `0`, `1` oder `2`;
  - `check`: `quick_check`, `integrity_check` oder `null`, falls keine SQLite-Prüfung gestartet werden konnte;
  - `scope`: beispielsweise `active` oder `annual`;
  - `archive`: ausschließlich der relative Archivdateiname oder `null`;
  - `result`: normalisiertes Ergebnis des Aufrufs;
  - `reason_code`: normalisierter Detailgrund oder `null`;
  - `started_at` und `finished_at`: eindeutige Zeitstempel;
  - `duration_seconds`: gemessene Gesamtdauer;
  - `incident_action`: normalisierte ausgeführte Lifecycle-Aktion oder `null`.
- Alle Pflichtfelder bleiben auch dann vorhanden, wenn ihr Wert für einen konkreten Pfad `null` ist. Dadurch müssen Auswertung und Tests nicht zwischen verschiedenen Ausgabeformen unterscheiden.
- Die erlaubten Enum-Werte und ihre Kombinationen werden in Code, Tests und Operations-Dokumentation als ein gemeinsamer Vertrag definiert. Freie technische Diagnosen dürfen nur in einem begrenzten, sanitisierten Zusatzfeld erscheinen.
- Die Struktur darf weder absolute Serverpfade noch Credentials, Secrets, vollständige Archivdatensätze oder andere sensible Nutzdaten enthalten.
- Eine spätere inkompatible Änderung der Feldbedeutung oder Pflichtfelder erhöht `schema_version`; optionale rückwärtskompatible Ergänzungen dürfen Version `1` nicht in ihrer bestehenden Bedeutung verändern.

### Entscheidung 28 – erfolgreicher `no_work`-Zustand

- Stellt der Runner nach vollständiger und erfolgreicher Zustandsauswertung fest, dass für diesen Aufruf ordnungsgemäß keine Prüfung auszuführen ist, meldet er:
  - `status=completed`;
  - `exit_code=0`;
  - `result=no_work`.
- Ein legitimer `no_work`-Pfad erzeugt, wiederholt oder löst keinen Operational Incident allein aufgrund dieses Ergebnisses aus.
- Beispiele sind ein bereits vollständig abgeschlossener Jahresprüfzyklus oder ein nach dem dokumentierten Zeit-/Scope-Vertrag nicht fälliger Arbeitsschritt.
- `no_work` darf nicht verwendet werden, um einen Fehler zu verdecken. War nach dem persistierten Zustand eine Archivdatei oder Prüfung zu erwarten, die Datei ist aber nicht auffindbar, nicht lesbar oder der Zustand widersprüchlich, muss der Runner einen normalisierten Fehlerpfad mit Exit Code `2` verwenden.
- Der Runner muss den Unterschied zwischen „nachweislich nichts fällig“ und „erwartete Arbeit nicht ausführbar“ automatisiert testen.

### Entscheidung 29 – geschlossene Liste normalisierter Runner-Ergebnisse

- `result` darf ausschließlich einen der folgenden sechs Werte enthalten:
  - `ok`: Die SQLite-Prüfung wurde vollständig ausgeführt und meldet ein gesundes Archiv; Exit Code `0`.
  - `corruption_detected`: Die SQLite-Prüfung wurde vollständig ausgeführt, bestätigt einen echten Defekt und die vorgeschriebene Incident-/Quarantäne-/Generationsreaktion wurde erfolgreich persistiert; Exit Code `0`.
  - `no_work`: Nach vollständiger Zustandsauswertung ist ordnungsgemäß keine Arbeit fällig; Exit Code `0`.
  - `deferred`: Die SQLite-Prüfung wurde nicht gestartet, beispielsweise weil die exklusive Archiv-Sperre belegt ist; Exit Code `1`.
  - `inconclusive`: Die SQLite-Prüfung wurde begonnen, lieferte aber kein vollständig verwertbares eindeutiges Endergebnis; Exit Code `1`.
  - `error`: Ein technischer, Konfigurations-, Persistenz- oder Zustandswiderspruch verhindert eine vertragsgemäße Ausführung; Exit Code `2`.
- Die zusammenfassenden `status`-Werte werden eindeutig daraus abgeleitet:
  - `completed` für `ok`, `corruption_detected` und `no_work`;
  - `incomplete` für `deferred` und `inconclusive`;
  - `failed` für `error`.
- `corruption_detected` mit Exit Code `0` bedeutet ausschließlich, dass der Runner den Defekt zuverlässig erkannt und den vereinbarten Reaktionspfad erfolgreich ausgeführt hat. Scheitert eine verpflichtende Reaktion oder Persistenz, ist das Ergebnis stattdessen `error` mit Exit Code `2`.
- Implementierung und Tests dürfen keine zusätzlichen freien `result`- oder `status`-Werte erzeugen. Technische Untergründe werden ausschließlich über dokumentierte `reason_code`-Werte und begrenzte sanitiserte Diagnosen differenziert.

### Entscheidung 30 – Reaktion auf einen offenen Incomplete-Incident

- Wird nach dem finalen dritten täglichen Cron-Slot `retention_archive_health_check_incomplete` `raised` oder `repeated`, soll ein Operator den Incident innerhalb eines Arbeitstages prüfen.
- Die automatischen Cron-Versuche laufen am nächsten Tag unabhängig von dieser manuellen Diagnose weiter. Der Operator stoppt sie nicht allein aufgrund des offenen Incomplete-Incidents.
- Der erste Diagnoseumfang ist ausdrücklich read-only und umfasst:
  - die drei Hostinger-Cron-Ausgaben des betroffenen Tages;
  - `result`, `reason_code`, Zeitpunkte und Dauer der Runner-JSONs;
  - die korrelierte Zeile beziehungsweise Incident-Folge in `wp_kiwi_operational_events`;
  - die Einordnung als Sperre, Timeout, Runner-/Bootstrap-Fehler, Konfigurationsproblem oder widersprüchlicher Zustand.
- Ein Incomplete-Incident autorisiert keine manuelle Löschung, Reparatur, Quarantäne, Freigabe oder Reaktivierung einer Archivdatei.
- Beendet eine spätere automatische vollständige Prüfung den unklaren Zustand, gilt weiterhin der beschlossene Lifecycle: Der Incomplete-Incident wird qualifiziert aufgelöst; bei bestätigtem Defekt wird zusätzlich der getrennte Corruption-Incident eröffnet.
- Das Operations-Runbook muss den Diagnoseweg, zulässige read-only Befehle, verbotene Mutationen und den Eskalationsweg für wiederholte beziehungsweise technische Fehler konkret dokumentieren.

### Entscheidung 31 – Benachrichtigung bleibt getrennte Folgearbeit

- Issue #110 führt keine neue E-Mail-, Admin-UI-, REST- oder sonstige aktive Benachrichtigungsfunktion für Operational Events ein.
- Der aktuelle Systemstand ist ausdrücklich zu berücksichtigen: Operational Events werden append-only gespeichert, können aber derzeit nur über interne Repository-Methoden beziehungsweise SQL gelesen werden. Sie lösen keine automatische Benachrichtigung aus.
- Bis eine allgemeine Benachrichtigungsfunktion existiert, setzt die Reaktionsfrist aus Entscheidung 30 deshalb eine dokumentierte regelmäßige Operator-Kontrolle der offenen Operational Incidents voraus.
- Eine wiederverwendbare automatische E-Mail-Benachrichtigung für offene Operational Incidents wird als getrennte Folgearbeit in `TODO.md` aufgenommen.
- Die Folgearbeit soll auf dem allgemeinen Operational-Event-Lifecycle aufbauen und nicht als Retention-spezifischer E-Mail-Sonderweg im Health-Runner implementiert werden.

### Entscheidung 32 – Operator-Prüfung eines bestätigten Archivdefekts

- Ein neu `raised`er `retention_archive_corruption_detected`-Incident muss innerhalb von drei Arbeitstagen durch einen Operator geprüft werden.
- Diese Frist ist eine verbindliche betriebliche Vorgabe im Operations-Runbook, wird innerhalb von Issue #110 aber nicht technisch überwacht oder automatisch angemahnt.
- Die automatische Quarantäne, der Generationswechsel und die weiteren Retention-Läufe warten nicht auf diese Operator-Prüfung.
- Der initiale Prüfauftrag umfasst:
  - bestätigen, dass die betroffene alte Generation korrekt markiert und unverändert quarantänisiert bleibt;
  - bestätigen, dass die vorgesehene Ersatzgeneration aktiviert wurde;
  - den erkennbaren Umfang und Zeitraum potenziell beschädigter Altdaten festhalten;
  - JSON-Ausgabe, Operational-Event-Lifecycle und Folge-Run auf Widersprüche prüfen.
- Die Prüfung autorisiert keine manuelle Löschung, Reparatur, Reaktivierung oder Freigabe der quarantänisierten Datei.
- Die Operator-Prüfung selbst löst den Corruption-Incident nicht auf. Es gilt weiterhin ausschließlich die qualifizierte automatische Auflösung aus Entscheidung 24 nach dem ersten vollständig erfolgreichen Archiv-/Quittungs-/MySQL-Delete-/Audit-Batch der Ersatzgeneration.
- Issue #110 führt keinen neuen technischen Acknowledgement- oder Fristnachweis ein. Bis zur späteren allgemeinen Benachrichtigungsfunktion ist die Kontrolle Teil des dokumentierten Operator-Prozesses.
- Die Drei-Arbeitstage-Frist gilt für bestätigte Corruption-Incidents. Die getrennte Ein-Arbeitstag-Frist aus Entscheidung 30 für `retention_archive_health_check_incomplete` bleibt unverändert.

### Entscheidung 33 – vollständig lokaler, wegwerfbarer Logic-Prototyp

- Der vor dem finalen Planner-Report auszuführende Prototyp läuft vollständig lokal und verwendet ausschließlich künstliche, jederzeit löschbare Testdaten.
- Er verbindet sich weder mit Hostinger noch mit einer Produktionsdatenbank oder einer echten Produktions-Archivdatei.
- Weil die offenen Fragen ausdrücklich Persistenz, SQLite-Commit, Quittung, MySQL-Delete und Wiederanlauf betreffen, darf der Prototyp isolierte lokale Scratch-Dateien beziehungsweise eine eindeutig als wegwerfbar markierte lokale Testdatenbank verwenden.
- Der Prototyp wird als Logic-/State-Machine-Prototyp gebaut. Er macht nach jedem simulierten Schritt Auditphase, SQLite-Quittung, vorhandene MySQL-Source-IDs, Lock-/Quarantänezustand und zulässige nächste Aktion sichtbar.
- Er muss mit einem dokumentierten Befehl reproduzierbar startbar sein und seine eigenen Scratch-Artefakte gezielt in einem klar begrenzten Prototypverzeichnis anlegen.
- Er bleibt ausdrücklich Nicht-Produktionscode. Nach Auswertung werden nur Messergebnisse, bestätigte Zustandsregeln und erkannte Caveats in den Planner-Report übernommen; der Prototyp wird anschließend gelöscht oder bewusst in Tests beziehungsweise Implementierungsartefakte überführt.
- Die lokale Validierung beweist die Logik und relative Engpassverteilung, nicht die absolute Laufzeit oder Belastbarkeit des Hostinger-Produktionsservers.

### Entscheidung 34 – feingranulares Performance- und Ressourcenprofil

- Der Prototyp muss neben der Zustandslogik ein reproduzierbares, maschinenlesbares Performance-Profil erzeugen. Eine reine Gesamtlaufzeit reicht nicht aus.
- Mindestens folgende Phasen werden einzeln mit Start, Ende und Wall-Clock-Dauer gemessen:
  - Setup beziehungsweise Generierung und Öffnen der Scratch-Daten;
  - Auffinden und Auswählen der Archivgeneration;
  - Lock-Akquisition beziehungsweise Lock-`deferred`;
  - SQLite-Open und relevante Pragmas;
  - Archivdaten schreiben;
  - SQLite-Quittungszeilen schreiben;
  - SQLite-Transaktions-Commit;
  - Quittung erneut lesen;
  - Primary Keys und Counts validieren;
  - MySQL-Source-Zustand für quittungsbelegte IDs ermitteln;
  - verbleibende MySQL-Source-Datensätze löschen;
  - Delete-Ergebnis verifizieren;
  - MySQL-Auditfortschritt persistieren;
  - `quick_check` vollständig ausführen;
  - `integrity_check` vollständig ausführen;
  - Status-/Kampagnendatei atomar lesen und schreiben;
  - Operational Event beziehungsweise simulierten Eventzustand persistieren;
  - Quarantäne-Marker und Auswahl der Ersatzgeneration;
  - gesamte Invocation.
- Zu jeder datenabhängigen Phase werden, soweit sinnvoll, zusätzlich erfasst:
  - verarbeitete Zeilen und IDs;
  - Dateigröße, SQLite-Seitenzahl und Seitengröße;
  - Durchsatz in Zeilen beziehungsweise MiB pro Sekunde;
  - Prozess-CPU-Zeit;
  - Peak-Memory;
  - gelesene und geschriebene Bytes, soweit die lokale Plattform sie zuverlässig bereitstellt;
  - Betriebssystem-, PHP-, SQLite- und Datenbankversion sowie relevante Testparameter.
- `quick_check` und `integrity_check` werden nicht nur als Teil einer Gesamtkette, sondern isoliert pro vollständiger Archivdatei gemessen.
- Messungen werden mehrfach wiederholt. Erster Lauf und Folgeläufe werden getrennt ausgewiesen, weil Betriebssystem- und Dateisystem-Caches die Dauer stark verändern können. Ein Lauf darf nur dann als „cold cache“ bezeichnet werden, wenn dies tatsächlich kontrolliert wurde.
- Der Bericht zeigt Einzelmessungen sowie geeignete Zusammenfassungen wie Minimum, Median und Maximum; bei ausreichend vielen Wiederholungen zusätzlich ein hohes Perzentil. Ausreißer dürfen nicht still entfernt werden.
- Lokale Systemlast wird soweit zuverlässig möglich als Prozess-CPU, Gesamtsystem-CPU, Speicherdruck und Datenträgeraktivität während der einzelnen Phasen erfasst. Nicht verfügbare Messwerte werden als nicht verfügbar ausgewiesen und nicht geschätzt.
- Die spätere Produktionsimplementierung soll dieselben wesentlichen Phasendauern im sanitisierten Runner-JSON verfügbar machen. Hostinger-spezifische Serverlastwerte werden nur ausgegeben, wenn sie auf dem Zielsystem zuverlässig und ohne zusätzliche Privilegien messbar sind.
- Der Prototypbericht trennt ausdrücklich:
  - lokal gemessene absolute Werte;
  - relative Engpässe und Skalierungstrends;
  - noch auf Hostinger zu validierende Produktionsannahmen.

### Entscheidung 35 – zweistufige Performance-Validierung bis etwa 1,3 GB

- Die Performance-Validierung besteht aus zwei getrennt ausgewiesenen Stufen:
  1. Kleine und mittlere künstliche Datensätze werden häufig wiederholt, um Zustandslogik, Messstabilität, Skalierung und einzelne Phasenkosten schnell zu vergleichen.
  2. Zusätzlich wird mindestens eine strukturell repräsentative synthetische SQLite-Archivdatei von ungefähr 1,3 GB erzeugt und geprüft, entsprechend der bei der Produktionsanalyse beobachteten Größenordnung.
- Die große Testdatei enthält ausschließlich künstliche Daten, verwendet aber soweit für die Messfrage relevant dieselben Tabellen-, Index-, Seiten- und Quittungsstrukturen wie das echte Archiv. Eine bloße unstrukturierte Füllerdatei gilt nicht als repräsentativer Belastungstest.
- `quick_check` und `integrity_check` werden auf der großen Datei getrennt gemessen. Archivaufbau und Datengenerierung werden ebenfalls separat ausgewiesen und nicht in die eigentliche Check-Dauer eingerechnet.
- Vor Erzeugung der großen Datei prüft der Prototyp den verfügbaren Speicherplatz und verwendet ausschließlich ein klar begrenztes Scratch-Verzeichnis. Er darf nicht starten, wenn der benötigte Sicherheitsabstand nicht vorhanden ist.
- Scratch-Daten werden nach Bestätigung beziehungsweise am Ende über einen gezielten Cleanup-Befehl entfernbar gemacht; der Prototyp darf keine unklaren großen Restdateien im Repository verteilen.
- Die Produktionsgröße dient der Erkennung von Größenabhängigkeit und relativen Engpässen. Die lokale 1,3-GB-Laufzeit wird nicht als Hostinger-Laufzeit ausgegeben oder hochgerechnet.

### Entscheidung 36 – konkrete Größen- und Wiederholungsmatrix

- Die Quittungs-/Delete-/Audit-Pipeline wird mit künstlichen Batches von `100`, `10.000` und `50.000` Source-Datensätzen gemessen.
- Die SQLite-Check-Skalierung wird mit strukturell vergleichbaren künstlichen Archivdateien von ungefähr `50 MB`, `250 MB` und `1,3 GB` gemessen. Tatsächliche Bytegröße, Seitenzahl und Zeilenzahl werden pro erzeugter Datei dokumentiert.
- Für `100` und `10.000` beziehungsweise `50 MB` und `250 MB` werden je Szenario zehn gemessene Wiederholungen ausgeführt, sofern die Prototyp-Preflight-Prüfung keine lokale Ressourcenbegrenzung feststellt.
- Auf der ungefähr `1,3 GB` großen Datei werden `quick_check` und `integrity_check` jeweils dreimal gemessen:
  - ein ausdrücklich als erster Lauf gekennzeichneter Durchgang;
  - zwei unmittelbar folgende Wiederholungen.
- Die drei großen Messungen werden einzeln ausgewiesen. Aus lediglich drei Werten wird kein statistisch belastbares hohes Perzentil behauptet; Minimum, Median und Maximum bleiben zulässig.
- Kleine und mittlere zehnfache Wiederholungen weisen mindestens Einzelwerte, Minimum, Median, Maximum und ein transparent berechnetes hohes Perzentil aus.
- Vor der großen Matrix prüft der Prototyp freien Speicherplatz und benötigten Sicherheitsabstand für SQLite-Datei, Journal-/WAL-Artefakte, Scratch-MySQL-Daten und Ergebnisdateien.
- Reicht Speicherplatz oder eine zwingende lokale Laufzeitvoraussetzung nicht aus, wird die große Stufe nicht still verkleinert. Sie wird mit konkretem Grund als nicht ausgeführt dokumentiert und vor dem Planner-Report neu entschieden.

### Entscheidung 37 – sechs verbindliche Prozessabbruch-Szenarien

- Der Logic-Prototyp muss einen plötzlichen Prozessverlust an sechs persistenzrelevanten Grenzen simulieren und anschließend einen neuen Prozess mit demselben Run-Zustand starten:
  1. **Vor SQLite-Commit:** Archivdaten und Quittung gelten nicht als persistiert; MySQL-Source-Daten bleiben vollständig erhalten.
  2. **Nach SQLite-Commit, vor persistierter Quittungsprüfung:** Archivdaten und Quittung sind gemeinsam vorhanden; derselbe Run findet und verifiziert sie beim Wiederanlauf.
  3. **Nach Quittungsprüfung, vor MySQL-Delete:** Der Wiederanlauf vertraut nicht allein dem Audit, liest die SQLite-Quittung erneut und darf danach den Delete fortsetzen.
  4. **Während eines teilweise ausgeführten MySQL-Deletes:** Der Wiederanlauf ermittelt die noch vorhandenen quittungsbelegten Source-Datensätze und löscht ausschließlich diesen Rest.
  5. **Nach vollständigem MySQL-Delete, vor abschließendem Auditfortschritt:** Der Wiederanlauf findet keine verbleibenden Source-Datensätze, führt keinen zweiten Delete aus und reconciliert nur den Auditstand.
  6. **Nach abschließendem Auditfortschritt, vor normalem Prozessende:** Der Wiederanlauf erkennt den abgeschlossenen Batch und führt weder Archivierung noch Delete noch Count-Erhöhung erneut aus.
- Für jedes Szenario werden vor und nach dem Wiederanlauf vollständig sichtbar gemacht:
  - Run-ID und Auditphase;
  - persistierte Archivzeilen und Quittungs-IDs;
  - noch vorhandene MySQL-Source-IDs;
  - kumulierte Archiv- und Delete-Counts;
  - gewählte Recovery-Aktion und Endzustand.
- Jedes Szenario muss folgende Invarianten bestätigen:
  - kein MySQL-Delete ohne vollständig erneut bestätigbare SQLite-Quittung;
  - keine doppelte Archivnutzdaten- oder Quittungszeile;
  - keine doppelte Verarbeitung oder kumulierte Count-Erhöhung;
  - Löschung ausschließlich noch vorhandener quittungsbelegter Source-Datensätze;
  - sichere Fortsetzung desselben Cleanup-Runs mit derselben Run-ID, solange die Evidence konsistent ist.
- Die Abbruchinjektion und der Wiederanlauf werden separat von den normalen Performance-Wiederholungen ausgewiesen, damit simulierte Fehlerpfade die Baseline-Messwerte nicht verfälschen.

### Entscheidung 38 – sechs Cron-/Lock-/Incident-Szenarien

- Der Logic-Prototyp bildet die drei festen UTC-Cron-Slots als deterministisch steuerbare Zeitpunkte ab und berechnet den zugehörigen fachlichen Tag in `Europe/Berlin`.
- Folgende sechs Abläufe sind verbindlich:
  1. **Erster Slot, Lock belegt:** Der Prüfer wartet nicht, meldet `status=incomplete`, `result=deferred`, Exit Code `1` und erzeugt noch keinen Incident.
  2. **Zweiter Slot, Lock frei und Check erfolgreich:** Der spätere Versuch meldet `status=completed`, `result=ok`, Exit Code `0`; für diesen Tag wird kein Incomplete-Incident erzeugt.
  3. **Alle drei Versuche ohne eindeutigen Abschluss:** Erst nach dem finalen dritten Versuch entsteht genau ein `retention_archive_health_check_incomplete` mit `lifecycle_action=raised`.
  4. **Auch am Folgetag kein eindeutiger Abschluss:** Derselbe korrelierte Incident erhält eine `repeated`-Zeile; es entsteht kein unabhängiger täglicher Incident.
  5. **Spätere vollständige Prüfung meldet `ok`:** Der offene Incomplete-Incident erhält genau eine qualifizierte `resolved`-Zeile.
  6. **Spätere vollständige Prüfung bestätigt Defekt:** Der Incomplete-Incident erhält `resolved`; im selben Lauf wird für dieselbe betroffene Archivgeneration `retention_archive_corruption_detected` mit `raised` angelegt.
- Pro Versuch werden JSON-Pflichtfelder, Exit Code, Lockzustand, gestartete beziehungsweise nicht gestartete SQLite-Prüfung und der komplette append-only Incident-Lifecycle sichtbar gemacht.
- Der Prototyp bestätigt zusätzlich:
  - kein Prüfer wartet auf die Sperre;
  - die ersten beiden Versuche erzeugen allein noch keinen Incident;
  - stabile Korrelation und Idempotency verhindern doppelte Lifecycle-Zeilen;
  - `deferred` und `inconclusive` quarantänisieren keine Datei;
  - nur ein vollständig verwertbares `ok`- oder Defektergebnis kann den Incomplete-Incident auflösen.

### Entscheidung 39 – echter SQLite-Check und sieben Quarantäne-/Ersatzszenarien

- Der Prototyp beschränkt sich nicht auf simulierte Check-Ergebnisse. Er führt auf den erzeugten Scratch-Archivdateien tatsächlich aus:
  - `PRAGMA quick_check`;
  - `PRAGMA integrity_check`.
- Beide Pragmas werden auf gesunden Dateien einschließlich der ungefähr 1,3-GB-Datei real ausgeführt und nach Entscheidung 34/36 zeitlich und ressourcenseitig gemessen.
- Zusätzlich wird eine kontrolliert beschädigte Kopie einer synthetischen Archivdatei verwendet. Die Beschädigungsmethode und das unveränderte Ausgangsartefakt werden dokumentiert.
- Nur wenn der tatsächlich aufgerufene SQLite-Check einen eindeutigen Defektbefund liefert, darf dieser reale Versuch den `corruption_detected`-Pfad qualifizieren. Bricht SQLite mit einem nicht eindeutig klassifizierbaren Fehler ab, bleibt das Ergebnis `inconclusive` beziehungsweise `error` und darf keine Quarantäne auslösen.
- Die State Machine darf ergänzend kontrollierte Resultate injizieren, um jeden Übergang deterministisch zu demonstrieren; solche Simulationen werden klar von realen SQLite-Ausführungen getrennt ausgewiesen.
- Folgende sieben Abläufe sind verbindlich:
  1. **Check nicht gestartet oder ohne eindeutiges Resultat abgebrochen:** keine Quarantäne und keine Ersatzgeneration.
  2. **Vollständiger Check bestätigt Defekt:** Corruption-Incident, atomarer Quarantäne-Marker und Auswahl einer neuen Generation.
  3. **Quarantäne wirksam:** Die alte Datei bleibt unverändert vorhanden und wird von automatischen Retention-Läufen nicht erneut ausgewählt.
  4. **Cleanup-Run an defekter Generation:** Der alte Run wird blockiert; genau ein verknüpfter Nachfolge-Run mit neuer Run-ID verarbeitet ausschließlich die unter dem ursprünglichen eingefrorenen Scope noch in MySQL vorhandenen Source-Datensätze.
  5. **Ersatzgeneration nur angelegt:** Eine leere beziehungsweise noch nicht qualifiziert verwendete Ersatzdatei löst den Corruption-Incident nicht auf.
  6. **Erster vollständiger Ersatzbatch:** Erst Archiv-Commit, Quittungsprüfung, MySQL-Delete und Auditabschluss erzeugen die `resolved`-Zeile mit `resolution_reason=quarantined_and_replaced`.
  7. **Späterer erfolgreicher Check der alten Generation:** Die Quarantäne bleibt bestehen; es erfolgt keine automatische Reaktivierung.
- Für alle sieben Abläufe werden Archive, Marker, aktive Generation, alter und neuer Cleanup-Run, noch vorhandene Source-IDs sowie der vollständige append-only Incident-Lifecycle sichtbar gemacht.

### Entscheidung 40 – Jahreswechsel und jährliche Mehrarchiv-Kampagne im Prototyp

- Der Logic-Prototyp bildet sowohl den normalen Jahreswechsel der aktiven Archivdatei als auch die ab 2. Januar gestartete jährliche Integritätskampagne über mehrere vorhandene Archivgenerationen ab.
- Der Testbestand enthält mindestens:
  - eine gesunde abgeschlossene Generation aus einem Vorjahr;
  - eine weitere gesunde Vorjahresgeneration;
  - eine quarantänisierte Vorjahresgeneration;
  - eine aktive Generation des aktuellen Jahres.
- Beim normalen Jahreswechsel wird bestätigt:
  - ein neuer Run ohne bereits eingefrorenen Archivpfad wählt beziehungsweise erzeugt die aktive Generation des neuen Kalenderjahres;
  - ein bereits im Vorjahr gestarteter und sicher wiederaufnehmbarer Run behält seinen eingefrorenen Vorjahrespfad;
  - dafür ist keine neue Registry-Tabelle und keine manuell gepflegte Vorgängerbeziehung erforderlich.
- Die jährliche Kampagne bestätigt:
  - Start am 2. Januar mit einer stabil sortierten Momentaufnahme aller zu Beginn vorhandenen, nicht quarantänisierten Archivgenerationen;
  - höchstens eine vollständige Archivdatei pro dafür vorgesehenem Cron-Slot;
  - Verarbeitung nacheinander und niemals parallel;
  - Fortsetzung mit der nächsten offenen Datei im finalen dritten Cron-Slot beziehungsweise an den folgenden Kalendertagen;
  - eine gesperrte Datei bleibt ausstehend und wird später erneut versucht, statt übersprungen zu werden;
  - quarantänisierte Dateien werden nicht automatisch geprüft oder reaktiviert;
  - bereits im aktuellen Zyklus vollständig abgeschlossene Dateien werden bei wiederholtem Cron-Aufruf nicht erneut geprüft;
  - der Zyklus wird erst abgeschlossen, wenn jede eingeplante Datei ein eindeutiges Ergebnis besitzt.
- Die regelmäßige Prüfung der aktiven Generation bleibt während einer laufenden Jahreskampagne als eigener Scope bestehen und darf nicht versehentlich durch den Kampagnenfortschritt deaktiviert werden.
- Der Prototyp zeigt nach jedem Slot Kampagnenjahr, stabile Dateiliste, Status pro Datei, nächste ausstehende Datei, aktive Generation und erzeugte Incidents vollständig an.

### Entscheidung 41 – Crash-Sicherheit der atomaren Kampagnen-Statusdatei

- Der Prototyp verwendet für den Kampagnenfortschritt eine kleine JSON-Statusdatei und demonstriert den vorgesehenen atomaren Schreibvertrag real auf dem lokalen Dateisystem.
- Der Writer erzeugt zunächst eine vollständige temporäre Datei im selben Verzeichnis, schließt beziehungsweise spült sie soweit plattformseitig vorgesehen und ersetzt erst danach atomar den veröffentlichten Statuspfad.
- Mindestens folgende Abbruchpunkte werden mit neuem Prozesswiederanlauf geprüft:
  - vor Erstellung der temporären Datei;
  - während des Schreibens der temporären Datei;
  - nach vollständiger temporärer Datei, aber vor dem atomaren Austausch;
  - unmittelbar nach dem Austausch, aber vor normalem Prozessende.
- Nach jedem Abbruch darf der Reader ausschließlich einen vollständigen alten oder vollständigen neuen validen Status akzeptieren. Eine teilweise geschriebene Datei darf nie als Kampagnenzustand verwendet werden.
- Eine übrig gebliebene temporäre Datei ist nicht autoritativ, wird beim Lesen ignoriert und kann über den dokumentierten sicheren Cleanup entfernt werden.
- Schema-, Zyklus- und Dateilistenvalidierung erfolgen vor Verwendung. Eine beschädigte oder widersprüchliche veröffentlichte Statusdatei führt zu `error`, nicht zu `no_work` und nicht zu einem stillen Neustart der Kampagne.
- Der Wiederanlauf bestätigt, dass bereits vollständig geprüfte Archivgenerationen nicht aufgrund des Abbruchs erneut geprüft und offene Dateien nicht verloren werden.
- Die lokale Demonstration der Atomizität ersetzt nicht den Hostinger-Preflight: Der Deployment-Codex muss dasselbe Dateisystem-/Rename-Verhalten mit einem kleinen ungefährlichen Scratch-Artefakt auf dem Zielsystem bestätigen.

### Entscheidung 42 – echter Mehrprozess-Test des Betriebssystem-Locks

- Der Prototyp prüft die exklusive Archiv-Sperre nicht nur als simulierten Boolean, sondern zusätzlich mit echten getrennten lokalen Prozessen und einer Sperrdatei pro Archivgeneration.
- Der reale Ablauf ist:
  1. Prozess A öffnet die Lockdatei und hält den exklusiven nicht blockierenden Betriebssystem-Lock.
  2. Prozess B versucht denselben Lock und muss sofort ohne Warten mit `result=deferred` und Exit Code `1` enden.
  3. Prozess A wird absichtlich hart beendet, ohne einen normalen Anwendungscleanup auszuführen.
  4. Prozess C muss denselben Lock anschließend erfolgreich übernehmen können.
  5. Die physische Lockdatei darf weiter existieren; ihre bloße Existenz gilt niemals als belegte Sperre.
- Gemessen werden mindestens Lock-Akquisitionsdauer, Zeit bis zum `deferred`-Ergebnis und Zeit bis zur erfolgreichen Übernahme nach Prozessende.
- Der Test bestätigt, dass weder ein Worker noch ein Prüfer auf einen Lock wartet und dass ein Prozessabsturz keine dauerhafte Zombie-Sperre hinterlässt.
- Das Testprogramm darf ausschließlich zuvor aufgelöste Pfade innerhalb seines klar begrenzten Scratch-Verzeichnisses sperren oder bereinigen.
- Der lokale Windows-Test beweist nicht automatisch dasselbe Verhalten auf Hostingers Linux-/Hosting-Dateisystem. Der Deployment-Codex muss deshalb vor Aktivierung der echten Cronjobs mit kleinen Scratch-Dateien zusätzlich bestätigen:
  - verwendete Lock-Funktion verfügbar;
  - Sperre zwischen zwei PHP-/WP-CLI-Prozessen wirksam;
  - nicht blockierender Versuch kehrt unmittelbar zurück;
  - Sperre wird nach hart beendetem Halterprozess freigegeben.

### Entscheidung 43 – ausführbare Validierung des JSON-/Exit-Code-Vertrags

- Der Prototyp führt für jeden der sechs erlaubten `result`-Werte mindestens einen vollständigen Runner-Aufruf als separaten Prozess aus:
  - `ok`;
  - `corruption_detected`;
  - `no_work`;
  - `deferred`;
  - `inconclusive`;
  - `error`.
- Für jeden Aufruf wird maschinell bestätigt:
  - `stdout` enthält exakt eine einzige vollständige JSON-Zeile und keine weiteren Zeichen oder Zeilen;
  - das JSON ist syntaktisch parsebar;
  - `schema_version=1` und alle Pflichtfelder sind vorhanden, auch wenn einzelne Werte `null` sind;
  - `status`, `result` und tatsächlicher Prozess-Exit-Code entsprechen der festgelegten Matrix;
  - Zeit-, Dauer-, Check-, Scope-, Archiv-, Reason- und Incident-Felder besitzen den vereinbarten Typ und erlaubte Werte.
- Kontrolliert ausgelöste PHP-Warnungen, WP-CLI-Hinweise oder interne Statusmeldungen dürfen `stdout` nicht verschmutzen. Kontrollierbare Diagnosen werden begrenzt und sanitisiert im JSON erfasst; technische Laufzeitausgaben werden nicht unstrukturiert vor oder nach dem Dokument ausgegeben.
- Der Sanitizing-Test verwendet ausschließlich künstliche Test-Secrets und künstliche absolute Pfade und bestätigt, dass sie weder im JSON noch in anderer kontrollierter Ausgabe unmaskiert erscheinen.
- Ein tatsächlicher Fehler vor erfolgreichem Runner-Bootstrap wird als dokumentierter Sonderfall separat demonstriert: Er kann den Ein-JSON-Vertrag technisch nicht garantieren, muss aber mit Nicht-Null-Exit und klarer Operations-Diagnose erkennbar sein.
- Die JSON-Vertragsprüfung validiert die Produktionsschnittstelle; die interaktive Prototypansicht darf daneben menschenlesbare Zustände anzeigen, wird aber als getrennte TUI und nicht als Runner-`stdout` ausgeführt.

### Entscheidung 44 – zweigeteilte Prototyp-Ergebnisartefakte

- Der Prototyp erzeugt nach einem nicht interaktiven vollständigen Validierungs-/Benchmarklauf zwei klar getrennte Ergebnisdateien in seinem begrenzten Ergebnisverzeichnis:
  1. eine maschinenlesbare JSON-Datei mit sämtlichen Einzelmessungen, Testparametern, Zustandsübergängen, tatsächlichen Dateigrößen, Ressourcenwerten und Szenarioergebnissen;
  2. eine kompakte Markdown-Auswertung für Menschen.
- Die Markdown-Auswertung enthält mindestens:
  - ausgeführte und nicht ausgeführte Szenarien mit Gründen;
  - Testumgebung und relevante Versionen;
  - Tabellen für Phasendauern und Ressourcenwerte;
  - langsamste Phasen und Größen-/Durchsatzvergleich;
  - erster Lauf gegenüber wiederholten Läufen;
  - Ergebnisse der realen `quick_check`- und `integrity_check`-Ausführungen;
  - bestätigte Zustandsinvarianten;
  - Abweichungen, Ausreißer und erkannte Risiken;
  - klare Grenzen dessen, was lokal nicht für Hostinger bewiesen wurde.
- Die JSON-Datei bleibt die vollständige Rohquelle; die Markdown-Datei darf Werte zusammenfassen, aber keine ungünstigen Einzelmessungen oder Fehlschläge verschweigen.
- Beide Dateien enthalten ausschließlich künstliche beziehungsweise sanitiserte Daten und keine Produktionsdaten, Secrets oder unmaskierten sensitiven absoluten Pfade.
- Die Ergebnisdateien sind Prototyp-Artefakte und werden nicht vollständig in das GitHub-Issue kopiert. Die für Architektur und Umsetzung relevanten Erkenntnisse, Messwerte und Caveats werden vor dem finalen Planner-Report in diese Planungsnotiz übernommen.
- Nach Abschluss wird entschieden, ob die kleinen Ergebnisdateien als Evidenz erhalten bleiben oder zusammen mit dem übrigen Prototyp gelöscht werden. Große Scratch-Datenbanken und künstliche 1,3-GB-Archive werden nicht dauerhaft versioniert.

### Entscheidung 45 – bestehendes Retention-Runbook als einzige Betriebsanleitung

- Die dauerhafte Betriebsanleitung für den neuen Archiv-Health-Prozess wird in `docs/operations/retention-runbook.md` ergänzt. Es wird kein zweites separates Archiv-Health-Runbook angelegt.
- Das bestehende Runbook wird mindestens um folgende zusammenhängende Bereiche erweitert:
  - Zweck und Trennung von unmittelbarer Batch-Quittungsprüfung und globalem SQLite-Health-Check;
  - exakter versionierter WP-CLI-/Runner-Befehl unter `tools/database`;
  - Hostinger-Cron-Slots, Zeitzonenhinweis und Verantwortungsgrenze zwischen Implementer und Deployment-Codex/Operator;
  - JSON-Schema, erlaubte Ergebnisse, Reason Codes und Exit Codes;
  - Deployment-Preflight, manueller Smoke-Test und Aktivierung;
  - regelmäßige aktive Prüfung und jährliche Mehrarchiv-Kampagne;
  - Lock-, Deferred-, Inconclusive- und `no_work`-Verhalten;
  - Abfragen und Interpretation der beiden Operational-Incident-Typen;
  - read-only Diagnose, Reaktionsfristen und verbotene manuelle Mutationen;
  - Quarantäne, Ersatzgeneration, Nachfolge-Run und qualifizierte Incident-Auflösung;
  - sichere Deaktivierung, Eskalation und Wiederinbetriebnahme.
- Bestehende Retention-Inhalte werden aktualisiert, wenn der neue Vertrag sie ersetzt; widersprüchliche alte Aussagen über globale Checks im Worker dürfen nicht parallel stehen bleiben.
- `docs/operations/INDEX.md` verweist weiterhin auf dieses zentrale Runbook; ein zusätzlicher Indexeintrag ist mangels neuem Dokument nicht erforderlich.
- Der Code beziehungsweise die Runner-Hilfe verweist auf das zentrale Runbook, statt umfangreiche Betriebsanweisungen zu duplizieren.

### Entscheidung 46 – sicherer Not-Aus des externen Health-Runners

- Muss der neue Archiv-Gesundheitsprüfer wegen eines vermuteten Runner-Fehlers sofort gestoppt werden, entfernt der Deployment-Codex/Operator vorübergehend die drei Hostinger-Account-Cronjobs für `01:30`, `02:00` und `02:30 UTC`.
- Der normale Retention-Worker bleibt aktiv. Er erzeugt und prüft weiterhin pro Archivierungsbatch seine eigene SQLite-Batch-Quittung und löscht MySQL-Source-Datensätze ausschließlich nach erfolgreicher unmittelbarer Quittungsprüfung.
- Die Batch-Quittung wird vom Retention-Worker gemeinsam mit den Archivnutzdaten und `archive_batch_rows` in derselben SQLite-Transaktion erzeugt. Sie wird weder von den Hostinger-Cronjobs noch vom globalen Health-Runner geliefert.
- Während des Not-Aus pausieren ausschließlich die unabhängigen vollständigen Archiv-Gesundheitsprüfungen (`quick_check`/`integrity_check`) und die jährliche Mehrarchiv-Kampagne.
- Ein Not-Aus führt nicht dazu, die langsamen globalen SQLite-Prüfungen wieder in den Retention-Worker zurückzurollen. Ein Code-Rollback in diesen alten Risikopfad erfolgt niemals automatisch.
- Die Abschaltung ist ein offener betrieblicher Wartungszustand und muss bis zur Wiederinbetriebnahme aktiv verfolgt werden. Sie gilt nicht als normaler `no_work`-Dauerzustand.
- Vor Wiederanlage der drei Cronjobs müssen mindestens erfolgreich sein:
  - Runner-Bootstrap und genau eine valide JSON-Ausgabe;
  - Exit-Code-Vertrag;
  - kleiner ungefährlicher SQLite-Health-Check;
  - nicht blockierender Lock-Preflight;
  - Prüfung der konfigurierten Hostinger-Zeiten und tatsächlichen Zeitzone.
- Das zentrale Retention-Runbook verwendet die Begriffe konsequent:
  - **Batch-Quittungsprüfung:** unmittelbare Worker-Prüfung, die den MySQL-Delete autorisiert;
  - **Archiv-Gesundheitsprüfung:** unabhängiger vollständiger `quick_check` beziehungsweise `integrity_check` durch den externen Runner.

### Entscheidung 47 – dauerhafte schnelle Tests und gezielter großer Benchmark

- „Dauerhafte automatisierte Tests“ bedeutet für Issue #110:
  - der Testcode bleibt als Bestandteil des Repositorys erhalten;
  - die Szenarien laufen reproduzierbar ohne manuelle Klickfolge;
  - sie verwenden kleine künstliche Daten und enden deterministisch mit Erfolg oder Fehler;
  - sie werden nicht zusammen mit dem wegwerfbaren Prototyp gelöscht.
- Der aktuelle Repository-Stand besitzt noch keinen GitHub-Actions-Workflow. Deshalb gilt zunächst:
  - der Implementer führt die relevanten schnellen Tests während der Entwicklung nach betroffenen Änderungen lokal aus;
  - vor Handoff beziehungsweise Pull Request ist der vollständige bestehende PHP-Testbefehl verpflichtend;
  - tatsächlich ausgeführte Befehle und Ergebnisse werden im Implementierungs-Handoff dokumentiert.
- Die bereits separat in `TODO.md` vorgesehene GitHub-Actions-Einrichtung bleibt außerhalb von Issue #110. Sobald sie umgesetzt ist, sollen die neuen schnellen Tests über den gemeinsamen Testeinstieg automatisch bei jedem Pull Request gegen `main` und bei jedem Push auf `main` laufen.
- Ein zusätzlicher nächtlicher Lauf wird für diese schnellen deterministischen Tests nicht verlangt.
- Der große Performance-Benchmark mit `50 MB`, `250 MB` und ungefähr `1,3 GB` wird ausdrücklich nicht Bestandteil jedes normalen Testlaufs oder künftigen Pull-Request-Checks.
- Der große Benchmark wird ausgeführt:
  - einmal im vereinbarten Prototyp vor dem finalen Planner-Report;
  - später gezielt vor einem Produktionsrelease dieser Änderung;
  - erneut bei Änderungen, die SQLite-Archivschema, Check-Modus, Quittungspipeline, Locking oder relevante Retention-Performance beeinflussen.
- Ein nicht ausgeführter großer Benchmark darf nicht als bestanden ausgegeben werden; Anlass, Umgebung und Ergebnisse werden jeweils separat dokumentiert.

### Entscheidung 48 – fokussierte Testdatei im bestehenden Gesamt-Testeinstieg

- Die neuen schnellen dauerhaften Tests werden in einer klar benannten fokussierten Testdatei organisiert, beispielsweise `tests/retention-archive-health-tests.php`, statt den bereits sehr großen `tests/run-tests.php` mit sämtlichen neuen Szenarien weiter aufzublähen.
- Der bestehende verpflichtende Gesamt-Testeinstieg `php tests/run-tests.php` bindet die fokussierte Datei ein und führt sie automatisch mit aus. Es entsteht kein zweiter verpflichtender Gesamt-Testbefehl.
- Die fokussierte Datei beziehungsweise ein kleiner dokumentierter Einstieg darf während der Entwicklung separat ausführbar sein, damit der Implementer die Retention-Szenarien schnell wiederholen kann.
- Der fokussierte Testbereich enthält die kleinen deterministischen Fälle für:
  - Batch-Quittung, Delete und Recovery;
  - Lock-Vertrag;
  - JSON-/Exit-Code-Matrix;
  - Cron-/Incident-Lifecycle;
  - Quarantäne und Ersatzgeneration;
  - Jahreswechsel, Jahreskampagne und atomare Statusdatei.
- Scratch-Dateien werden ausschließlich in einem eindeutig begrenzten Test-Temp-Verzeichnis erzeugt und über `finally`-/Teardown-Verhalten gezielt bereinigt. Fehlschläge dürfen keine unklaren großen Dateien im Repository hinterlassen.
- Der große Performance-Benchmark und seine 50-MB-/250-MB-/1,3-GB-Artefakte werden nicht aus `tests/run-tests.php` gestartet und verbleiben im getrennten Prototyp-/Benchmark-Pfad.
- Die genaue Dateibenennung darf der Implementer an bestehende Testkonventionen anpassen, solange Fokus, Einbindung in den Gesamt-Testeinstieg und Trennung vom großen Benchmark erhalten bleiben.

### Entscheidung 49 – Archiv read-only mit eng begrenzten Statusschreibrechten

- Die frühere Kurzbezeichnung „read-only Runner“ wird präzisiert zu:
  - **Archiv-read-only Health-Runner mit eng begrenzten betrieblichen Statusschreibrechten.**
- Der Runner öffnet jede SQLite-Archivdatei technisch read-only und darf weder Archivnutzdaten noch `archive_batch_rows`, Schema, Indizes oder sonstige SQLite-Inhalte verändern.
- Der Runner darf keine MySQL-Source-Datensätze löschen oder verändern und keine Retention-Quittung erzeugen, ersetzen oder reparieren.
- Seine Schreibrechte sind auf folgende betriebliche Artefakte begrenzt:
  - Sperrdatei beziehungsweise Halten/Freigeben des Betriebssystem-Locks;
  - atomare Statusdatei der jährlichen Prüfkampagne;
  - atomarer Quarantäne-Marker für eine eindeutig als defekt bestätigte Generation;
  - append-only `raised`-/`repeated`-/`resolved`-Zeilen im bestehenden Operational-Event-System.
- Das Blockieren eines an die quarantänisierte Generation gebundenen Cleanup-Runs und die Erstellung genau eines Nachfolge-Runs erfolgen anschließend im normalen Retention-Worker anhand des Quarantäne-Markers und der persistierten Run-Evidence.
- Der Health-Runner legt nicht selbst vorsorglich eine leere Ersatz-Archivdatei an. Die nächste aktive Generation wird vom normalen Archivierer erst bei tatsächlicher weiterer Archivarbeit erzeugt.
- Scheitert eines der verpflichtenden begrenzten Statusschreiben nach einem Defektbefund, darf der Aufruf nicht `corruption_detected` mit Exit Code `0` melden; er endet als `error` mit Exit Code `2`.
- In menschenlesbarer Dokumentation wird der Begriff **Sperrdatei** verwendet. „Lock-Sidecar“ ist kein erforderlicher Benutzerbegriff.

### Entscheidung 50 – vorläufiger Zielvertrag für Datei und vier Produktionsbefehle

- Folgender Vertrag beschreibt den später durch den Implementer zu bauenden Produktions-Runner. Er ist nicht der Dateiname oder Startbefehl des wegwerfbaren Prototyps.
- Runner-Datei:
  - `tools/database/kiwi-retention-archive-health.php`
- Gemeinsamer WP-CLI-Namespace:
  - `wp kiwi retention archive-health`
- Vier Unterbefehle:
  1. `scheduled`
     - einziger Hostinger-Cron-Einstieg;
     - entscheidet idempotent über fälligen `quick_check`, `integrity_check`, Rückstand und Jahreskampagne;
     - darf die in Entscheidung 49 begrenzten Status-, Incident- und Quarantäne-Schreibvorgänge ausführen.
  2. `status`
     - read-only Übersicht über aktive Generation, Kampagnenfortschritt, Marker, letzte Ergebnisse und relevante offene Incidents;
     - verändert keine Archive oder betrieblichen Zustände.
  3. `diagnose --archive=<Dateiname> --check=<quick|integrity>`
     - manuelle streng read-only Prüfung einer ausdrücklich ausgewählten Archivgeneration;
     - erzeugt weder Quarantäne noch Incident-Lifecycle-Aktionen noch Kampagnenfortschritt.
  4. `preflight`
     - ungefährlicher Zielumgebungstest für WordPress-/WP-CLI-Bootstrap, SQLite-Unterstützung, Sperrdatei und atomaren Dateiaustausch;
     - schreibt ausschließlich eigene klar begrenzte Scratch-Artefakte und entfernt sie gezielt.
- Jeder Unterbefehl verwendet den vereinbarten Ein-Zeilen-JSON- und Exit-Code-Vertrag.
- Der wegwerfbare Prototyp validiert die wesentlichen Annahmen und Schnittstellen dieses Zielvertrags, wird aber nicht unverändert als Produktions-Runner übernommen.
- Zeigt der Prototyp einen Widerspruch oder eine Plattformgrenze, wird dieser Zielvertrag vor dem finalen Planner-Report ausdrücklich angepasst; die Anpassung darf nicht still erfolgen.

### Entscheidung 51 – sichere Verzeichnisgrenze ohne zu starre Namensregel

- `diagnose --archive=...` akzeptiert niemals einen freien Dateisystempfad, sondern ausschließlich einen relativen Dateinamen aus der vom `status`-Befehl ausgegebenen Liste `diagnosable_archives`.
- `status` entdeckt vorhandene Retention-Archivdateien ausschließlich innerhalb des konfigurierten Retention-Archivverzeichnisses und gibt nur relative Dateinamen aus.
- Die Sicherheitsgrenze bleibt streng:
  - keine absoluten Pfade;
  - keine Pfadtrenner, Unterverzeichnisse oder `..`;
  - nur reguläre Dateien;
  - nach Auflösung muss der reale Pfad innerhalb des konfigurierten Archivverzeichnisses verbleiben;
  - symbolische beziehungsweise sonstige Links nach außerhalb werden abgelehnt.
- Die Erkennung wird nicht ausschließlich an eine unveränderliche Regex für das heutige Muster `kiwi_retention_archive_YYYY[_part_N].sqlite` gekoppelt. Bestehende ältere oder künftig kontrolliert weiterentwickelte Retention-Archivnamen können aufgenommen werden, sofern sie durch die Retention-eigene Discovery als Archiv identifiziert werden.
- Quarantänisierte Generationen bleiben in `diagnosable_archives` sichtbar und dürfen ausdrücklich read-only untersucht werden.
- Eine Diagnoseaufnahme oder ein späteres `ok` verändert weder Quarantäne-Marker noch aktive Generation noch Incident-Lifecycle.
- Eine Datei, die nicht in der unmittelbar zuvor beziehungsweise aktuell ermittelten sicheren Discovery-Liste enthalten ist, wird fail-closed abgelehnt statt als freier Pfad geöffnet.

### Entscheidung 52 – Prototyp-Standardwert plus dokumentierter Deployment-Override

- Die konkrete Runner-Laufzeitgrenze wird nicht vor den Performance-Messungen geraten.
- Vor dem finalen Planner-Report leitet der Prototyp aus den gemessenen realen `quick_check`-/`integrity_check`-Laufzeiten, insbesondere auf ungefähr 1,3 GB, einen begründeten initialen Standardwert beziehungsweise eine begründete Standardstrategie ab.
- Der spätere Produktionscode hardcodiert keine unveränderliche Hostinger-Annahme. Er stellt eine sicher validierte Konfiguration für die Laufzeitgrenze bereit und dokumentiert Standard, erlaubten Wertebereich und Verhalten.
- Der vollständige Zielsystem-Preflight findet erst nach Implementierung und Deployment statt. Der Deployment-Codex/Operator:
  - prüft tatsächliche Hostinger-/PHP-/CLI-Laufzeitgrenzen;
  - führt einen kleinen ungefährlichen Check aus;
  - setzt nur bei belegtem Bedarf vor Cron-Aktivierung einen dokumentierten umgebungsspezifischen Override.
- Override-Wert, Quelle, Begründung und Verifikation werden im Deployment-Handoff festgehalten. Eine stille Abweichung vom Code-Standard ist unzulässig.
- Unabhängig vom konkreten Grenzwert gilt:
  - Timeout beziehungsweise externer Prozessabbruch ist `inconclusive` oder bei technischem Steuerungsfehler `error`, niemals `corruption_detected`;
  - ein Timeout darf keine Quarantäne auslösen;
  - die Betriebssystem-Sperre muss nach Prozessende freigegeben sein;
  - spätere Cron-Aufrufe warten nicht auf einen noch laufenden Check, sondern melden `deferred`.
- Zeigt der Prototyp, dass ein sinnvoller vollständiger Check innerhalb realistischer Prozessgrenzen nicht zuverlässig möglich ist, wird nicht nur der Zahlenwert erhöht. Die Architekturfrage wird vor dem Planner-Report erneut geöffnet.

### Entscheidung 53 – fester UTC-Zeitplan, Berliner Fachkalender

- Die drei produktiven Hostinger-Aufrufe werden ganzjährig fest auf `01:30`, `02:00` und `02:30 UTC` gesetzt.
- Die saisonale Verschiebung der lokalen Berliner Uhrzeit wird bewusst akzeptiert; eine manuelle Umstellung zur Sommer- oder Winterzeit ist nicht erforderlich.
- Der Runner ordnet alle Fälligkeiten und Ergebnisse einem fachlichen Kalendertag in `Europe/Berlin` zu. Das gilt insbesondere für:
  - Montag-bis-Samstag-`quick_check` versus Sonntags-`integrity_check`;
  - den Start der jährlichen Kampagne am 2. Januar;
  - die Entscheidung, ob für denselben fachlichen Tag bereits ein erfolgreicher Lauf vorliegt;
  - Korrelation, Eskalation und Wiederholung eines unvollständigen Tagesfensters.
- Der Deployment-Preflight zeigt die gespeicherten UTC-Cron-Ausdrücke und die daraus zu diesem Zeitpunkt resultierenden Berliner Uhrzeiten ausdrücklich an beziehungsweise dokumentiert sie.
- Betriebsquelle für die UTC-Festlegung: [Hostinger – How to Set Up a Cron Job](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/).

### Entscheidung 54 – echte wegwerfbare MariaDB im lokalen Prototyp

- Der Prototyp verwendet für die MySQL-Seite keine reine Attrappe, sondern eine echte lokale, vollständig wegwerfbare MariaDB mit kleinen künstlichen Retention-Daten.
- Die Prototyp-Datenbank hat keinerlei Verbindung zu Hostinger oder Production. Zugangsdaten, Datenbankname und Speicherort müssen sie eindeutig als lokale Scratch-Umgebung kennzeichnen.
- Damit werden neben der Zustandslogik auch reale Datenbankoperationen und ihre kleinteiligen Laufzeiten gemessen, insbesondere:
  - Auswahl der fälligen Source-Zeilen;
  - Abgleich der quittungsbelegten IDs mit noch vorhandenen Source-Zeilen;
  - MySQL-Delete der exakt freigegebenen Zeilen;
  - Audit-/Run-Status-Persistenz;
  - Wiederaufnahme nach den festgelegten Crash-Fenstern.
- Die SQLite-Archive bleiben ebenfalls künstliche lokale Scratch-Dateien. Keine Produktionsdatei wird für den Prototyp kopiert oder geöffnet.
- Vor dem Bau wird zunächst geprüft, ob auf dem Entwicklungsrechner bereits eine geeignete lokale MariaDB-Laufzeit vorhanden ist. Die konkrete isolierte Startmethode wird erst danach festgelegt.

### Entscheidung 55 – portable MariaDB ohne dauerhafte Installation

- Weil auf dem Entwicklungsrechner keine vorhandene MariaDB-, MySQL-, Docker- oder Podman-Laufzeit auffindbar ist, verwendet der Prototyp eine portable MariaDB.
- Die Binärdateien, Datenbankdateien und künstlichen Testdaten liegen ausschließlich in einem eindeutig als wegwerfbar gekennzeichneten lokalen Scratch-Verzeichnis.
- Der Prototyp startet und stoppt MariaDB selbst mit einer isolierten Konfiguration. Es wird kein dauerhafter Windows-Dienst eingerichtet und keine systemweite MariaDB-Installation vorausgesetzt.
- Nach Abschluss der Messungen werden Prozess und Scratch-Daten kontrolliert beendet beziehungsweise entfernt; dauerhaft erhalten bleiben nur die vereinbarten Messresultate und daraus abgeleiteten Planentscheidungen.
- Die portable Laufzeit ist ein Hilfsmittel des lokalen Prototyps und kein auszuliefernder Bestandteil des späteren Production-Runners.

## Offene Planungsfragen

1. Welcher initiale Timeout-Standard beziehungsweise welche Laufzeitstrategie ergibt sich aus dem Prototyp?
2. Welche weiteren dokumentierten Betriebsabläufe braucht der Runner?

## Geplanter Prototyp nach dem Interview

Der Prototyp wird erst nach Abschluss der Entscheidungen konkretisiert. Er bleibt lokal und wegwerfbar, simuliert mindestens die neue Zustandsfolge sowie die Abbruchfenster um SQLite-Commit, MySQL-Delete und Audit-Persistenz und erzeugt zusätzlich das in Entscheidung 34 beschriebene Performance- und Ressourcenprofil. Relevante Ergebnisse, Zustandsübergänge, Messwerte und Caveats werden vollständig in den finalen Planner-Report übernommen.
