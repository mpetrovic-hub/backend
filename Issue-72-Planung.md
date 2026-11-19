# Issue #72 – Planung: sichere Retention für Engagement-Daten

Stand: **13.10.2026**. Der vollständige Planner-Report wurde nach der Nutzerfreigabe an derselben Stelle auf GitHub aktualisiert und zurückgelesen. Genau **ein** Codex Planner Report ist vorhanden; der Inhalt dieser lokalen Kopie entspricht dem veröffentlichten Stand, mit der unten beschriebenen Ausnahme der operativen Checkboxen.

## Planungsstatus

**Die freigegebene Planfassung ist auf GitHub gespeichert.** Die Entscheidung „Groß-/Kleinschreibung unterscheiden“ vom 12.10.2026 ist ersetzt: Das bestehende MySQL-Zuordnungsverhalten bleibt erhalten. Der neue Archiv-Paarvergleich verwendet gezielt NOCASE für `landing_key` und `session_token`, mit passendem Index und konsistenter Paar-/Tageszuordnung.

Der vollständige Bestandsvergleich vom 13.10.2026 mit **1.091.581 Engagements** und **1.155.096 live/archivierten Sessions** ergab **0 abweichende Zuordnungen** für Main, TK-zone und den Live-Session-Schutz; **0 zusätzliche betroffene Tage** durch NOCASE. Die bekannte Unicode-/Leerzeichen-Grenze ist im Report ausdrücklich beschrieben. NOCASE wird nicht als universeller MySQL-Kollationsersatz ausgegeben; für die abgeleitete Archivstatistik gelten weiterhin die freigegebenen Tages-Toleranzen. Originalkennungen, vorhandene MySQL-Prozesse und Unique-Keys werden nicht geändert; Snapshot-, Receipt- und Delete-Verifikation bleibt bytegenau.

Dieser Stand bestätigt den Planungs-/Veröffentlichungsauftrag. Die neuen Code-/Index-/Nebenläufigkeitsprüfungen müssen während der Umsetzung tatsächlich ausgeführt werden; die Prototypen sind kein Nachweis einer bereits korrigierten Implementierung. Diese Veröffentlichung autorisiert keine Backend-Codeänderung, PR, Merge, Deployment oder Production-Aktivierung.

## Quellen und Verwendung

- [GitHub-Issue #72](https://github.com/mpetrovic-hub/backend/issues/72)
- [Einziger aktueller Codex Planner Report](https://github.com/mpetrovic-hub/backend/issues/72#issuecomment-6062755086)
- [Korrigierte Nutzerentscheidung](https://github.com/mpetrovic-hub/backend/issues/72#issuecomment-6131929763)
- [Historischer Implementierungsblocker mit aktuellem Planungsstatus](https://github.com/mpetrovic-hub/backend/issues/72#issuecomment-6131274362)
- [Aktueller Einstellungsschlüssel](https://github.com/mpetrovic-hub/backend/issues/72#issuecomment-4810403033)
- [Private NOCASE-Test-Auswertung](C:/Users/ncsta/.codex/tmp/issue-72-nocase-prototype-20261013/NOCASE-Test-Auswertung.md)

Die 21 Checkbox-Zustände im Issue unter `Akzeptanzkriterien` und `Manuelle Tests` blieben unverändert. `0 - codex-candidate` und `1b - codex-planned` sind erhalten, `2 - codex-implement-ready` ist gesetzt, `needs-human` für den nun geklärten Planungsblocker entfernt. Projektfelder sind weiterhin **Risk=High, Size=L, Status=Ready for Codex**. Kein Planner-Report und kein Diskussionskommentar wurde gelöscht.

Der GitHub-Report ist die verbindliche Implementer-Quelle. Die tatsächlichen späteren Abnahme-Checkboxen stehen ausschließlich dort unter `Abnahme nach erfolgreichem Merge`; diese lokale Kopie gibt die Aufgaben als beschreibende Liste wieder, ohne konkurrierende Checkboxen. Die bisherigen Abnahmezustände blieben unverändert. Offene spätere Abnahme blockiert die PR-Erstellung nicht; sie beginnt nach belegtem erfolgreichem Merge und den jeweils nötigen Betriebsfreigaben.

---

## Codex Planner Report

### Ergebnis

Kann vermutlich umgesetzt werden

Konsolidierter Planungsstand: 13.10.2026. Die 2-%-Tagesregel, die CTA-Mindesttoleranz von fünf Zählungen sowie der Entfall des verpflichtenden Gruppenabgleichs und der Sonderbehandlung gleicher Zeitstempel sind ausdrücklich vom Nutzer freigegeben.

Die zwischenzeitliche Entscheidung „Groß-/Kleinschreibung unterscheiden“ vom 12.10.2026 ist ersetzt: Das bestehende MySQL-Zuordnungsverhalten bleibt erhalten. Der neue Archiv-Paarvergleich verwendet gezielt SQLite NOCASE; der vollständige Bestandsvergleich bestätigt dafür 0 abweichende Zuordnungen. Die unten dokumentierte Unicode-/Leerzeichen-Grenze wird nicht als vollständige MySQL-Kollationsgleichheit ausgegeben.

Die Planung ist fachlich abgestimmt und für eine spätere Implementierung ausreichend konkret. Die Freigabe dieser Planungsrunde umfasst ausschließlich das Speichern dieses Reports und die vereinbarten Issue-/Projektanpassungen. Backend-Code, PR, Merge, Deployment und aktiver Cleanup gehören zu späteren, getrennt freizugebenden Schritten.

### Kurzfassung

Die bestehende Retention-Infrastruktur wird um `landing_session_engagements` erweitert: standardmäßig 14 vollständige WordPress-Kalendertage nach der letzten Änderung (`updated_at`), konfigurierbar, mindestens sieben Tage. Vorhandener Einstellungsschlüssel: `kiwi_retention_settings.landing_session_engagements`, standardmäßig `enabled=false`, `dry_run=true`, `retention_days=14`.

Engagement bleibt in MySQL, solange irgendeine dort vorhandene Session dieselbe Kombination aus `landing_key` und `session_token` besitzt. Nach dieser Abhängigkeit wird vor Archivierung und unmittelbar beim Delete geprüft. Ein eigener Main-/TK-zone-Abgleich prüft ausschließlich die sieben vollständigen Tageskennzahlen des ursprünglichen `created_at`-Tages aus Live- und Archivdaten, einschließlich passender kanonischer Sessions und zurückgehaltener Engagement-Zeilen. Page-loads dürfen je Tageskennzahl um höchstens 2 % des größeren Vergleichswerts abweichen; für CTA gilt der größere Wert aus fünf Zählungen und 2 %. Main und TK-zone werden getrennt geprüft. Verpflichtende Gruppenabgleiche und besondere Behandlung gleicher Session-Zeitstempel entfallen; die Tagesabweichungen stehen im Cleanup-Audit.

Nur für Engagement mit `created_at` vom 23.04.2026 bis einschließlich 05.07.2026 gilt die ausdrücklich vereinbarte historische Ausnahme vom Statistik-Nachweis. Vollständige Archivierung, erneut verifizierte Receipts, Altersschwelle, Session-Abhängigkeit, Änderungsschutz und Corruption-Gates bleiben auch dort verpflichtend.

Für die Session-Abhängigkeit und die live Session-Zuordnung gelten unverändert die vorhandenen MySQL-Spaltenvergleiche; keine BINARY-Umstellung, keine Änderung von Unique-Keys, Session-/Summary-Prozessen oder gespeicherten Kennungen. Im neuen Archivlesebaustein werden `landing_key` und `session_token` ausdrücklich mit `COLLATE NOCASE` verglichen und entsprechend indiziert. Das bildet die ASCII-Groß-/Kleinschreibungsregel für die nachgewiesenen Kennungen nach; es ist kein universeller MySQL-Kollationsersatz. Die begrenzte Nachbildung betrifft ausschließlich die abgeleitete Archivstatistik, für die weiterhin die genehmigten Tages-Toleranzen gelten. Snapshot-, Receipt- und Delete-Verifikation bleiben NULL-sicher und bytegenau.

### Suchstrategie / Quellen

Recherche am 08.10.2026: zuerst Glossar, Dokumentationsindizes und Architekturentscheidungen; anschließend gezielt Registry, Cleanup, Archiv-/Receipt-Verifikation, Coverage-Gate, Engagement-Repository und die beiden Funnel-Aggregationen sowie deren Tests. Der vorhandene Graph wurde zur Navigation verwendet, Aussagen wurden am Code und an der Dokumentation geprüft.

- [Issue #72](https://github.com/mpetrovic-hub/backend/issues/72), seine drei bestehenden Kommentare und [Parent #69](https://github.com/mpetrovic-hub/backend/issues/69) definieren Quelle, Vollarchivierung und gemeinsamen Retention-Vertrag.
- [Abgeschlossener Audit #70: Engagement-Empfehlung](https://github.com/mpetrovic-hub/backend/issues/70#issuecomment-4810431707): 14 Tage sparen im damaligen Audit etwa 293,21 MiB; sieben Tage brächten nur etwa 23,59 MiB zusätzlich. Das sind historische Audit-Werte, keine aktuelle Laufzeitmessung.
- [Retention-Architektur](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/docs/architecture/retention-architecture.md), [Runbook](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/docs/operations/retention-runbook.md), [Konfigurationsreferenz](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/docs/operations/configuration-reference.md) und [externe Datenbankmigrationen](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/docs/operations/database-migrations.md).
- [Quellenregistrierung](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/includes/services/class-retention-source-registry.php), [Cleanup-Service](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/includes/services/class-retention-cleanup-service.php), [SQLite-Archiv und Receipts](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/includes/services/class-retention-sqlite-archive-service.php) und [Coverage-Gate](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/includes/services/class-retention-coverage-gate.php).
- [Engagement-Repository](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/includes/repositories/class-landing-session-engagement-repository.php), [Main-Aggregation](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/includes/services/class-landing-funnel-daily-summary-aggregation-service.php), [TK-zone-Aggregation](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/includes/services/class-landing-funnel-daily-tkzone-summary-aggregation-service.php) und [Funnel-Analytics-Vertrag](https://github.com/mpetrovic-hub/backend/blob/609e54fbe834721623678e7c2f26f60661307d7e/docs/operations/landing-funnel-analytics.md).
- [Verbindlicher Architekturkommentar zu #72](https://github.com/mpetrovic-hub/backend/issues/72#issuecomment-5204517388), [Issue #113](https://github.com/mpetrovic-hub/backend/issues/113), [PR #114](https://github.com/mpetrovic-hub/backend/pull/114) und der aktualisierte Archiv-Leseschutz aus [PR #141](https://github.com/mpetrovic-hub/backend/pull/141).

Recherchierter GitHub-main: `609e54fbe834721623678e7c2f26f60661307d7e`. Der unveränderte lokale Teststand lag auf `dc837f8564710375aa1c34dba62d023a9a3a8e9d`; main war mit PR #141 einen Commit voraus. Die für Engagement relevanten Registry-/Cleanup-/Coverage-/Repository-Dateien wurden gegen main abgeglichen. Die neuere Retention-Architektur samt Leseschutz wurde zusätzlich direkt aus main gelesen.

Ergänzungen am 09.–11.10.2026: private Archivkopien für SQLite-Index-/Laufzeitversuche, schreibfreie Tagesbeobachtungen auf Hostinger und eine vorab festgelegte Stichprobe mit 20 Zufallstagen plus acht gezielt auffälligen Tagen. Am 11.10. wurden Issue, alle vier Kommentare, Project-Felder, lokale Aggregations-/Gate-Verträge und die gespeicherten Messnachweise zurückgelesen. Die freigegebene 2-%-Regel wurde auf den gespeicherten Tageswerten erneut berechnet. Der lokale Backend-Stand bleibt unverändert auf `dc837f8564710375aa1c34dba62d023a9a3a8e9d`; die Quellen vom 08.10. oben bleiben als datierter Recherchestand gekennzeichnet.

Ergänzung am 13.10.2026: [Implementierungsblocker](https://github.com/mpetrovic-hub/backend/issues/72#issuecomment-6131274362), [korrigierte Nutzerentscheidung](https://github.com/mpetrovic-hub/backend/issues/72#issuecomment-6131929763), vollständiger schreibfreier Schlüsselvergleich des verfügbaren MySQL-/Archivbestands und lokale Gegenprobe mit MariaDB 11.8.9/SQLite 3.50.4. Der Desktop-Checkout wurde nur gelesen und liegt beim Veröffentlichungsabgleich auf `609e54fbe834721623678e7c2f26f60661307d7e`; die zuvor genannten Stände bleiben historische Nachweise.

### Prototype / Evidence Details

**Schreibfreie Production-Befunde vom 08.10.2026**

MySQL-Daten, Einstellungen, vorhandene Cleanup-Audits und SQLite-Schema wurden ausschließlich gelesen. Es wurden keine Originaldaten geändert und kein Cleanup beziehungsweise synthetischer Request ausgelöst.

| Befund | Beobachteter Stand | Bedeutung |
| --- | --- | --- |
| Datenbank | `11.8.9-MariaDB-log`, WordPress-Präfix `wp_` | Nebenläufigkeit später gegen MariaDB 11.8.9 prüfen; Tabellenauflösung dynamisch behalten. |
| WordPress-Zeit | `timezone_string` leer, `gmt_offset=2` | Kalenderberechnung muss auch mit WordPress-Offset statt benannter Zeitzone funktionieren. |
| Älteste live Session | `2026-09-23 00:00:22` | Frühere kanonische Session-Informationen müssen aus dem Archiv stammen. |
| Engagement | ältestes `created_at` und `updated_at`: `2026-04-23 12:51:39`; neuestes beobachtetes `updated_at`: `2026-10-08 15:20:35` | Erstellung und letzte Änderung sind getrennte fachliche Zeitachsen. |
| Summary-Beginn | Main `2026-07-06`, TK-zone `2026-05-29` | Main-Nachweis fehlt für die ältere Engagement-Historie. |
| Engagement vor Main-Beginn | **590.606 Zeilen**, exakt gezählt | Begründet die vereinbarte feste historische Ausnahme bis einschließlich 05.07.2026. |
| Engagement-Volumen | ca. 1.120.625 Zeilen, ca. 993,22 MiB | Schätzungen aus Tabellenmetadaten; keine exakten Kandidatenzahlen. |
| Live-Session-Volumen | ca. 21.547 Zeilen, ca. 171,30 MiB | Ebenfalls Metadaten-Schätzungen. |
| Gesamte Datenbanktabellen | `3.064.184.832` Bytes | Aktuelles gemessenes Tabellenvolumen; keine Aussage über ein aktuell bestätigtes Hosting-Limit. |
| Vorhandenes SQLite-Archiv | `4.415.217.664` Bytes | Zusätzlicher Archivspeicher und Indexvorbereitungsdauer sind relevante Betriebsrisiken. |
| Session-Lauf 160, 07.10.2026 | Cutoff `2026-09-23 00:00:00`; 1.807 eligible / archived / deleted; `gate_status=passed` | Belegt vorhandene Infrastruktur, nicht die neue Engagement-Funktion. |
| Handoff-Lauf 161, 07.10.2026 | Cutoff `2026-09-16 00:00:00`; jeweils 4.032 eligible / archived / deleted | Bestehender Handoff-Ablauf bleibt im Scope unverändert. |

Das über Lauf 160 belegte Archiv ist `/home/u367252972/kiwi-backend-archives/db-retention/sqlite/kiwi_retention_archive_2026.sqlite`. Die unter dem vorhandenen Archiv-Lock gelesene `sqlite_master` zeigte für die Session-Tabelle nur den Unique-Index auf `_source_pk`, keinen geeigneten Landing-/Session-/Datums-Leseindex. Eine Engagement-Archivtabelle existierte dort noch nicht. Bestehende aktivierte Production-Einstellungen betrafen ausschließlich Sessions (14 Tage) und Handoffs (21 Tage), jeweils `dry_run=false`; Engagement-Cleanup war nicht aktiviert.

**Warum Vorbereitung und Dry-run fortsetzbar sein müssen**

Folgende Kandidatenform wurde nur mit `EXPLAIN` untersucht, nicht als vollständiger Production-Count ausgeführt:

```sql
EXPLAIN
SELECT COUNT(*)
FROM wp_kiwi_landing_session_engagements e
WHERE e.updated_at < '2026-09-24 00:00:00'
  AND NOT EXISTS (
    SELECT 1 FROM wp_kiwi_landing_page_sessions s
    WHERE s.landing_key = e.landing_key
      AND s.session_token = e.session_token
  );
```

Ergebnis für `e`: `type=ALL`, `key=NULL`, `rows=1120665`, `Using where`; die Session-Unterabfrage wurde materialisiert und verwendete `created_landing_session` mit geschätzten 21.618 Zeilen. Ein vorhandener `updated_at`-Index garantiert somit keinen begrenzten kombinierten COUNT-/Anti-Join-Plan. Daraus folgt: Altersgrenze und höchste Source-ID über bestehende Indizes bestimmen; Session-Abhängigkeiten, Counts und Coverage anschließend in begrenzten Worker-Seiten prüfen. Kein großer vorgeschalteter Anti-Join-COUNT im Scheduler und kein neuer MySQL-Index als ungeprüfte Speicherlösung.

**Kleiner SQLite-Versuch**

In-Memory-Fixture: archivierte Session-ID 1 plus dieselbe live ID 1 sowie live ID 2; archiviertes Engagement ID 1 mit zwei CTA1-Klicks, dieselbe live ID 1 mit drei Klicks und neuerem `updated_at` am 25.09.; live ID 2 mit einem Klick und altem `updated_at` am 06.07.; eine verwaiste Engagement-ID 3 mit neun Klicks ohne kanonische Session.

Für den vollständigen 06.07.-Tag ergaben sich **zwei kanonische Engagement-Zeilen und vier CTA1-Klickereignisse**: Live-ID 1 ersetzt ihren älteren Archivstand, die kürzlich geänderte und daher zurückgehaltene Zeile zählt beim Tagesvergleich mit, das verwaiste Engagement wird nicht zusätzlich in eine kanonische Session-Metrik hineingeraten.

Nach Änderung von ID 2 auf `updated_at=08.10.` und zwei Klicks löschte ein gegen den alten Snapshot abgesicherter Delete **null Zeilen**. Sechs geprüfte Fälle bestanden: Source-ID-Deduplizierung, vollständige Tageszählung einschließlich zurückgehaltener Zeile, kanonische Session-Zuordnung, Altersschutz anhand `updated_at`, Änderungsschutz sowie geeigneter Archiv-Lookup-Index.

```sql
CREATE INDEX session_read
ON sessions_archive(landing_key, session_token, created_at, id, pid);

EXPLAIN QUERY PLAN
SELECT id, created_at, landing_key, session_token, pid
FROM sessions_archive
WHERE landing_key = ? AND session_token = ?
  AND created_at >= ? AND created_at < ?;
```

Plan: `SEARCH sessions_archive USING COVERING INDEX session_read (landing_key=? AND session_token=? AND created_at>? AND created_at<?)`. Dies beweist die Indexform für die kleine Fixture. Für den freigegebenen Tagesabgleich genügt beim Session-Lookup die Paar-/Tageszugehörigkeit einschließlich PID-Filter und stabiler `_source_pk`. Browser-, Geräte-, Source- und TK-zone-Dimensionen müssen dafür nicht rekonstruiert werden. Die folgende erweiterte Evidenz unterscheidet die ursprünglich für Gruppendiagnosen getesteten breiten Indizes vom nun kleineren Pflichtumfang. Die tatsächlichen Produktionsdefinitionen und Seitenabfragen werden vor PR-Erstellung mit realen SQLite-Fixtures geprüft.

Der Versuch ist kein Nachweis für MariaDB-Nebenläufigkeit, WordPress-Integration oder Production-Leistung.

**Bestandsvalidierung und Grenzen**

Der unveränderte lokale Stand besteht unter WSL/Linux mit PHP 8.1.2, realem PDO SQLite und `php -d date.timezone=UTC tests/run-tests.php` (Exit 0). Die vorhandene WSL-Testumgebung heißt `codex-backend-issue-138`. Windows-PHP 8.5.1 meldete beim normalen Lauf zwar Exit 0 und 404 PASS-Zeilen, hatte jedoch keine PDO-Treiber; einzelne SQLite-Prüfungen liefen daher nicht tatsächlich. Ein zusätzlicher Windows-Versuch scheiterte daran, dass Child-Prozesse die aktivierten Erweiterungen nicht übernahmen.

Mit der Linux-Standardzeitzone Europe/Berlin zeigte der vorhandene Teststand einen bereits bestehenden Datumsfehler im allgemeinen Summary-Pfad (erwarteter Folgetag 23.05., erhalten 22.05.). Eine allgemeine Reparatur dieses anderen Pfads gehört nicht zu #72. Die neue Engagement-Cutoff-Berechnung muss unabhängig von der PHP-Standardzeitzone explizit getestet werden.

Eine lokale MariaDB-Engine wurde in der vorhandenen Testumgebung noch nicht gefunden. Die spätere Umsetzung stellt eine minimale temporäre MariaDB-11.8.9-Testdatenbank für zwei Verbindungen bereit; eine vollständige WordPress-Testplattform ist nicht nötig. Neue Engagement-Retention, reale MariaDB-Schreib-/Delete-Rennen, vollständige Anwendungseinbindung und Production-Performance sind noch nicht validiert. Der erfolgreiche Bestandstest bezieht sich auf den genannten lokalen Commit, nicht auf eine bereits umgesetzte neue Funktion.

**Archiv-/Indexprototypen vom 09.10.2026**

Versuche liefen ausschließlich auf privaten Kopien außerhalb von WordPress und dem aktiven Archivverzeichnis. Das aktive Original `/home/u367252972/kiwi-backend-archives/db-retention/sqlite/kiwi_retention_archive_2026.sqlite` wurde nicht verändert. Die vom Nutzer angegebene Webadresse diente als Hinweis auf den Speicherort; tatsächlich gelesen wurde die verifizierte lokale Server-Datei über SSH.

Die ältere lokale Kopie hatte 4.137.742.336 Bytes, 1.029.071 Session-Zeilen und 1.252.734 Handoff-Zeilen. Lokale Umgebung: WSL/ext4, PHP 8.1.2, SQLite 3.37.2, Cache 8 MiB, mmap aus. `quick_check` auf der Testkopie: `ok` nach 1,227 Sekunden.

| Vergleich auf der älteren lokalen Kopie | Aufbau beider Indizes | Mehrbedarf | Abfrageplan |
| --- | ---: | ---: | --- |
| Ohne Leseindizes | – | – | vollständiger `SCAN` und temporäre Sortierung |
| Schmale Paar-/Tagesindizes | 1,629 s | 112.992.256 Bytes / 107,76 MiB | `SEARCH ... USING INDEX` |
| Breite Paar-/Tagesindizes | 2,946 s | 393.744.384 Bytes / 375,50 MiB | `SEARCH ... USING COVERING INDEX` |

Schmal wurden `(landing_key, session_token, created_at, _source_pk)` und `(created_at, _source_pk)` getestet. Die breiten Session-Varianten enthalten zusätzlich `service_key, provider_key, flow_key, country, pid, tksource, tkzone, device_brand, os, os_version, browser, client_ip_version, client_ip_prefix`; der Tagesindex außerdem Landing-/Session-Schlüssel. Roh-UA und Raw-context sind keine Indexspalten. Diese breiten Indizes dienten der damaligen vollständigen Gruppendiagnose und sind keine Pflichtdefinition für den nun freigegebenen Tagesabgleich.

Ohne neuen Index brauchte ein einzelner erfolgreicher Lookup 0,275 s, ein erfolgloser 0,246 s und eine Tagesseite mit 500 Zeilen 0,260 s. Warme Lookup-Mediane: schmal etwa 4,0 µs, breit 3,6 µs; diese Werte profitieren vom Cache. Für drei weitere lokale Durchläufe wurde nur die Testdatei mit `POSIX_FADV_DONTNEED` vom Dateicache entlastet, nicht der globale Cache. Je Durchlauf 500 zufällige Paare, identische 729 Ergebniszeilen:

| Lokaler Dateicache-Vergleich, Median | schmal | breit |
| --- | ---: | ---: |
| 500 verteilte Lookups | 0,245 s | 0,115 s |
| Kernel-Lesebytes dieser Lookups | 6.574.080 | 3.977.216 |
| 29.08., 6.444 Session-Zeilen in 13 Seiten | 0,0220 s | 0,00948 s |
| Kernel-Lesebytes dieses Tages | 14.741.504 | 1.241.088 |

Der breite Index reduzierte in diesem Versuch die Tages-Lesebytes um rund 92 %. Gemessen wurden lokale Prozess-/Kernel-Ressourcen, keine Hostinger-Account-/LVE-IOPS.

Auf Hostinger (PHP 8.3.33, SQLite 3.45.2) bestätigte eine getrennte künstliche 20.000-Zeilen-Fixture den Ansatz: beide Indizes schmal 0,09596 s / 2.166.784 zusätzliche Bytes, breit 0,15957 s / 6.586.368 Bytes; 500 warme Lookups schmal 0,01544 s, breit 0,00831 s. Keine lineare Hochrechnung auf das echte Archiv.

Anschließend wurde eine vollständige aktuelle Hostinger-Kopie mit 1.125.163 Session-Zeilen hergestellt. Originalgröße 4.423.864.320 Bytes; schreibfreies Kopieren mit 8 MiB/s und begrenzten Generation-Lock-Paketen dauerte 528,02 s. Diese Kopierdauer gehört zum Versuch; die spätere freigegebene externe Vorbereitung arbeitet direkt am benannten Archiv.

Auf dieser Kopie liefen die beiden breiten Testindizes unter eigenem Lock, `nice=15`, `ionice` Klasse 3, Cache 8 MiB, mmap aus und Prozess-I/O-Pausen mit ungefähr 8 MiB/s im Mittel:

| Vollständige Hostinger-Testkopie | Dauer | Wachstum |
| --- | ---: | ---: |
| Breiter Paarindex | 51,210 s | 214.573.056 Bytes |
| Breiter Tagesindex | 51,415 s | 215.261.184 Bytes |
| Vorbereitung einschließlich Count-Prüfung | 102,642 s | 429.834.240 Bytes / 409,92 MiB |

Endgröße 4.853.698.560 Bytes; Session-Count unverändert 1.125.163; ASC/BINARY-Definitionen und `COVERING INDEX` nachgewiesen. Danach: 500 Lookups 0,006675 s, 06.07. mit 17.312 Session-Zeilen 0,10444 s, 29.08. mit 6.444 Zeilen 0,02385 s. Indexprozess abgetastet maximal 81,75 MiB RSS. Die Pausen begrenzten die mittlere Prozess-I/O, keine einzelnen Bursts und keine Hosting-Account-IOPS. Hostinger-Zeiten sind kein gesicherter Kaltcache-Nachweis.

**Kontrollierte Zustands-/Worker-Versuche**

55 kontrollierte Szenarien beziehungsweise Zustandsprüfungen lieferten die erwarteten Ergebnisse für den damaligen Gate-Stand: Live gewinnt über Archiv, Source-ID-Deduplizierung, vollständige Tage inklusive früher archivierter beziehungsweise zurückgehaltener Engagements, historische Datumskanten, fehlende Coverage, widersprüchliche Snapshots, technische Fehler, Generation-Lock und Indexdefinitionen. Die dort getesteten exakten Page-loads und CTA-0,1-%-Grenzen dokumentieren den alten Prototyp, nicht die aktuelle Anforderung. Fehlende Indizes sowie falsche Spalten, DESC, NOCASE, Partial- und unerwartete UNIQUE-Definitionen wurden mit echten SQLite-Metadaten für den damaligen BINARY-Prototyp erkannt; diese frühere NOCASE-Ablehnung ist durch den unten festgelegten NOCASE-Paarvertrag vom 13.10.2026 ersetzt; erneute korrekte Vorbereitung war ein No-op.

Ganze Tageszeilen zugleich zu materialisieren benötigte lokal 112 MiB PHP-Spitzenspeicher. 500er-Seiten mit kompakten Metrikzählern und zurückgelesenen Zwischenständen brauchten lokal 24 MiB bei identischen Ergebnissen: 06.07. drei fortgesetzte Aufrufe mit künstlich auf 5.000 Zeilen gekürztem Budget, zusammen 2,439 s; 29.08. ein Aufruf, 0,580 s. Auf der vollständigen Hostinger-Testkopie: 0,89405 s beziehungsweise 0,21813 s, 18 MiB PHP-Spitze / 90,75 MiB Prozess-RSS. Diese Zeiten umfassen Testdateien und Archivabgleich, nicht die vorgelagerte MySQL-Erhebung oder einen vollständigen WordPress-Worker.

Eine Fixture mit 50.001 Zeilen verarbeitete im ersten Aufruf exakt 50.000 und nach persistiertem/zurückgelesenem Checkpoint eine weitere Zeile, maximal 500 je Batch. Ergebnis 500 Session-Zurückstellungen / 49.501 Freigaben, keine Originalarchivierung/-löschung. Ein künstliches Zeitbudget von 0,005 s stellte nach 2.000 Zeilen zurück; kein tatsächlich 60 Sekunden langer Worker-Test. Die Umsetzung verwendet das vorhandene Cleanup-Audit für Zwischenstände; private Prototype-Dateien sind keine neue Runtime-Abhängigkeit.

**Häufigkeit und vollständige Tagesstichprobe vom 09.10.2026**

Ein vollständiger schreibfreier Scan der älteren lokalen Kopie über 91 Tage dauerte 4,77867 s bei 500er-Seiten. Im Zeitraum 06.07.–29.08. wurden unter 483.306 Landing-/Session-/Tagespaaren 36 Browser-/TK-zone-Mehrdeutigkeiten gefunden: **0,007448697 %**. Gemeint sind mögliche unterschiedliche Dimensionszuordnungen bei gleichem frühestem Session-Zeitstempel; das ist keine Tagesfehlerquote und kein Beweis für einen konkreten Endgeräte- oder Backend-Bug. TK war dabei auf PID 106 begrenzt. Die acht Browser-/TK-zone-auffälligen Tage waren 06.–13.07.

Die vollständige Statistikstichprobe wurde vor Ergebniserhebung festgelegt: Grundgesamtheit 55 Tage (06.07.–29.08.); vier Zeitabschnitte mit 14/14/14/13 Tagen, je fünf zufällig ohne Zurücklegen ausgewählt. Bedingung: jeder Wochentag zwei- oder dreimal; tatsächlich Montag–Samstag dreimal, Sonntag zweimal. Zufallsstartwert `13432524207568262775`, eingefroren vor Messung; keine ergebnisabhängige Neuziehung.

Zufallstage: 08.07., 12.07., 16.07., 17.07., 18.07., 23.07., 24.07., 25.07., 27.07., 01.08., 03.08., 06.08., 09.08., 11.08., 14.08., 18.08., 19.08., 24.08., 25.08. und 26.08.2026. Zusätzlich die acht gezielten Tage 06.–13.07.; 08.07. und 12.07. überschneiden sich. **26 unterschiedliche Tage, 227.277 Engagement-Zeilen.**

Pro Tag wurde MySQL mit `REPEATABLE READ`, explizit schreibgeschützter Verbindung und anschließendem Rollback gelesen; kein WordPress-Bootstrap. Seiten höchstens 500, Einzelabfragen maximal 20 s, Tagesbudget 50.000 Zeilen / 60 s; 20 ms Pause nach vollen Seiten. Alle Engagements des `created_at`-Tages wurden berücksichtigt, unabhängig von ihrem Alter nach `updated_at`. Counts und alle sieben einzeln errechneten Summen stimmen für alle 26 Tage mit einer unabhängigen SQL-Aggregation desselben konsistenten Tagesstands überein. Session-Lookups verwendeten die verifizierte schreibfreie SQLite-Testkopie; Größe/Inode/Zeitstempel blieben unverändert.

Im untersuchten Zeitraum existierten keine live Session-Zeilen mehr. Die Testkopie hatte keine Engagement-Archivtabelle; bei deren Vorhandensein hätte der Versuch eine Zusammenführung verlangt. Bereits archivierte Engagements und Live-/Archiv-Überlagerung sind deshalb hier durch kontrollierte Fixtures, nicht durch diesen historischen Production-Datensatz nachgewiesen. Main- und TK-Tagesnachweise waren vorhanden; die gespeicherte TK-PID-Konfiguration wurde pro Tag gegen SHA256 von `106` geprüft.

| Getrennte Auswahl | Tage | Tagesprüfung bestanden (damaliger Gate-Stand) | Strikte Gruppenprüfung sperrt | Umgekehrte Gleichstandsreihenfolge beseitigt Sperre |
| --- | ---: | ---: | ---: | ---: |
| Zufallstage | 20 | 20 | 7 | 4 der 7 |
| Gezielte Tage | 8 | 8 | 8 | 8 der 8 |

Gesperrte Zufallstage bei Gruppenprüfung: 08.07., 12.07., 17.07., 23.07., 25.07., 27.07., 26.08. Am 23.07. und 26.08. betraf die Dimensionsmehrdeutigkeit auch PID. Unter den 26 eindeutigen Tagen waren 13 Gruppensperren, davon zehn durch andere Gleichstandsreihenfolge beseitigt. Die gezielten Tage und ihre Überschneidungen sind keine allgemeine Production-Sperrquote.

An drei Tagen blieb jeweils eine kleine Main-Gruppe mit sechs CTA1-Klicks Unterschied bei damals erlaubten fünf:

| Tag | Kleine Gruppe raw / Summary | CTA1-Tageswert raw / Summary |
| --- | ---: | ---: |
| 17.07. | 12 / 6 | 12.382 / 12.374 |
| 25.07. | 18 / 12 | 29.264 / 29.256 |
| 27.07. | 12 / 6 | 36.764 / 36.756 |

Die Tagesabweichung ist jeweils acht Klicks und schon innerhalb der früheren Tages-CTA-Toleranz. Eine gezielte zweite schreibfreie Messung reproduzierte alle drei Befunde. Betroffene Engagements zuletzt geändert am 04.08./16.08./10.09., Summary-Gruppen am 24.07./01.08./03.08.; das passt zu späteren Rohdatenänderungen, beweist aber keinen konkreten verursachenden Schreibvorgang. Ein Engagement vom 06.07. hatte kein kanonisches Session-Paar und wurde nach bestehendem Statistikvertrag nicht als zusätzliche Session gezählt; keine weiteren solchen Zeilen in den 26 Tagen.

Tagesläufe zusammen 48,71513065 s plus Aufbau 2,616825458 s = **51,331956108 s**. Median 1,203315276 s, Minimum 0,472454465 s, Maximum 12,734009856 s beim zuerst gelesenen 06.07.; kein Nachweis eines vollständig kalten/warmen Caches. Maximal 500 Engagements je Seite und 14 Session-Zeilen je Lookup; keine Grenze überschritten. PHP-Spitze 30 MiB, Prozess-RSS 91,38 MiB. Die drei Nachdiagnosen brauchten zusätzlich 3,204 s plus 2,530 s Verbindungsaufbau, maximal 94,95 MiB RSS.

**Freigegebene Neubewertung am 11.10.2026**

Die gespeicherten vollständigen Tageswerte wurden mit der ausdrücklich freigegebenen Regel `difference * 100 <= 2 * max(abs(raw), abs(summary))`, bei CTA zusätzlich `difference <= 5`, erneut berechnet: **26 von 26 Tagen bestehen; 364 von 364 Main-/TK-Metrikvergleichen bestehen.** Keine neue Production-Abfrage und kein schon implementierter Worker. Damit wird die 2-%-Regel ohne zusätzliche Prozentaufrundung geprüft; bei CTA greift der genehmigte Fünferpuffer.

Die Ergebnisse begründen den Entfall des Pflicht-Gruppenabgleichs und spezieller Gleichstandslogik. Ein verpflichtender diagnostischer Gruppenabgleich wird ebenfalls nicht eingeführt. Der Audit enthält die Tageswerte und ihre Abweichungen. Die seltenen Unterschiede der abgeleiteten Statistik werden ausdrücklich akzeptiert; Vollarchivierung, Session-Schutz, aktuelle vollständige Snapshots, Receipts und technische Fehlerzustände werden dadurch nicht gelockert.

**Reproduzierbarkeit und Aussagegrenzen**

Private Helfer/Nachweise liegen unter `C:\Users\ncsta\.codex\tmp\issue-72-archive-prototype-20261009`, darunter `archive-benchmark-results.jsonl`, `cold-cache-results.jsonl`, `hostinger-full-preparation-results.jsonl`, `index-preparation-results.jsonl`, `worker-budget-results.jsonl`, `session-ambiguity-frequency-results.json`, `coverage-sample-manifest.json`, `coverage-sample-results.jsonl`, `coverage-sample-residual-diagnostics.jsonl`, `coverage-sample-summary.json` und `coverage-2pct-rescore-approved-20261011.json`. Diese Dateien sind ergänzende private Evidence, keine Voraussetzung, um diesen eigenständigen Implementierungsplan zu verstehen; Originalarchive bleiben privat.

Zurückgelesene SHA256-Nachweise: Tagesmessungen `775886291b096846104351e266b9578e961cc98b8f43069ccef99aedd6a3f4f3`; Nachdiagnose `cceb6a9a1c941adff277b2bbab91b90b4afd8571157fe55cacfb0c2be82019b2`; vorab fixierte Auswahl `ae828737babd2f2c716989f37d717aee36534c7b5c1680aa67ae3de1dea3d811`.

Gemessen wurde der begrenzte Prototyp auf diesen Daten und Umgebungen. Nicht nachgewiesen sind der neue vollständige WordPress-Worker, seine MariaDB-Writer-/Delete-Nebenläufigkeit, tatsächliche neue Archivierung/Receipt-Freigabe/Löschung, vollständig kalter Hostinger-Aufbau, Hosting-Account-/LVE-IOPS und der endgültige Speicher-/Laufzeitbedarf der reduzierten Pflichtindizes sowie des Engagement-Backlogs. Die breite Indexmessung bleibt Vergleichsevidence, keine verbindliche Dimensionserweiterung. Die neuen konkreten Pfade und Abfragepläne werden vor PR mit realen SQLite-Fixtures validiert.

**Vollständiger MySQL-/NOCASE-Bestandsvergleich vom 13.10.2026**

Entscheidende Frage: Wie viele tatsächlich gespeicherte Engagements werden durch die begrenzten Vergleichsregeln von SQLite NOCASE anders zugeordnet als durch die vorhandene MySQL-Regel? Es wurden alle verfügbaren Engagements und Session-Source-IDs untersucht, keine Stichprobe. Die Prüfung ersetzt weder den vollständigen neuen Worker noch eine neue Abnahme der sieben Summary-Metriken.

- Tatsächliche Production-Regel der Schlüsselspalten: `utf8mb4_unicode_520_ci`, MariaDB 11.8.9. Die lokalen Minimal-Tabellen verwendeten dieselben Spaltentypen, Längen und Kollationen; Import ohne Warnungen oder Abschneiden.
- Konsistenter, schreibfreier MySQL-Snapshot: `2026-10-13T12:07:22+00:00`; anschließend Rollback.
- **1.091.581 live Engagements**, **23.921 live Sessions**, **1.131.175 archivierte Sessions**; Session-Vereinigung **1.155.096**.
- Archivbasis war die unveränderte private Kopie vom 09.10.2026. Alle Source-IDs des aktuellen Originals wurden unter dem vorhandenen Generation-Lock in höchstens 500er-Seiten über den bestehenden Source-PK-Index abgeglichen; **6.012 zusätzliche Snapshots** nachgeladen. Vollständige Source-ID-Vereinigung geprüft; live gewinnt bei gleicher Source-ID. Das verwendet den unveränderlichen Session-Archivvertrag und ist kein neuer Bytevergleich sämtlicher älterer Payloads.
- Engagements an **172 vorhandenen Tagen**, `created_at` vom 23.04. bis 13.10.2026; Sessions vom 31.05. bis 13.10.2026.
- **4.493.354** `landing_key`-/`session_token`-Werte untersucht: alle kleine ASCII-Buchstaben, Ziffern, Unterstrich oder Bindestrich. Nicht-ASCII, NULL/leere Werte, Steuerzeichen, zusätzliche Leerzeichen und Großbuchstaben jeweils **0**.

| Zuordnung je Engagement-Source-ID | MySQL | SQLite NOCASE | Abweichende Zeilen |
| --- | ---: | ---: | ---: |
| Main: passendes Paar am ursprünglichen `created_at`-Tag | 744.558 | 744.558 | 0 |
| TK-zone: gleicher Tag und derzeitiger PID-Filter `106` | 214.556 | 214.556 | 0 |
| Schutz durch passende live Session, unabhängig vom Tag | 13.440 | 13.440 | 0 |

**0 von 1.091.581 Engagements abweichend (0 %); 0 zusätzlich betroffene Tage durch NOCASE.** Nicht nur Summen, sondern jede einzelne Zugehörigkeit wurde verglichen; damit können sich gegensätzliche Fehler nicht gegenseitig verbergen. Nicht zugeordnete Zeilen in beiden Speichern sind keine NOCASE-Abweichung. Ein unabhängiger Nachlauf bestätigte Quellzahlen, vollständige Ergebniszeilen ohne NULL-Lücken, Zeicheninventar, 172 Tage, SHA-256 und unveränderte Originalarchiv-Metadaten.

Die Grenze von NOCASE wurde direkt gegen schreibfreie Production-MySQL-Vergleiche und lokal kontrolliert: ASCII `Token-AbC`/`TOKEN-abc` ist in beiden gleich. Bei **7 von 9 gezielt künstlichen Grenzfällen** unterscheiden sich die Regeln, etwa `ä`/`Ä`, `café`/`cafe`, `œ`/`oe`, `straße`/`strasse`, nachgestelltes normales oder geschütztes Leerzeichen und eingebettetes NUL. MySQL setzt diese getesteten Paare gleich, NOCASE nicht. `a-b`/`ab` ist in beiden verschieden. Diese künstliche Auswahl misst keine reale Häufigkeit. [SQLite-Dokumentation](https://www.sqlite.org/datatype3.html#collating_sequences): NOCASE faltet ASCII-Buchstaben, keine allgemeine Unicode-Kollation.

Gemessener Aufwand: Erhebung/private Projektion **100,09 s**; vollständiger lokaler MariaDB-Zuordnungsvergleich **6,69 s**, SQLite **13,53 s**; lokaler Gesamtversuch inklusive Export/Import/Kontrollen **47,34 s**. SQLite-Projektion **309.702.656 Bytes**, komprimiert **109.297.984 Bytes**. Diese Zahlen gehören zum Schlüsselprototyp, nicht zum Production-Worker, einer vollständigen Originalarchiv-Indexvorbereitung oder Hosting-Account-/LVE-IOPS.

Getesteter Lookup-Plan: `SEARCH sessions USING COVERING INDEX sessions_nocase (landing_key=? AND session_token=? AND created_at>? AND created_at<?)`. Der Projektionsindex enthielt `(landing_key COLLATE NOCASE, session_token COLLATE NOCASE, created_at, id, pid, origin)`; `origin` wird im Originalarchiv nicht als neue Spalte eingeführt. Der unten festgelegte Originalarchiv-Index bleibt auf die fünf benötigten Felder beschränkt.

Production-Verbindungen waren read-only, das Originalarchiv wurde nicht verändert, keine synthetischen Kundenrequests und kein Cleanup. Frische lokale MariaDB anschließend regulär beendet. Private Aggregate/Skripte: `C:\Users\ncsta\.codex\tmp\issue-72-nocase-prototype-20261013`, insbesondere `comparison-results.json`, `verification-results.json`, `export-results.json`, `key-inventory.json` und `NOCASE-Test-Auswertung.md`. SHA-256 der Schlüsselprojektion: `58bb9383c88a7ad0fad48eedf2a0ba3b2c23227c1135adf0b41eeda561094380`. Rohprojektionen, echte Session-Tokens und Zugangskonfiguration bleiben privat.

**Folgerung und Begrenzung:** Der getestete Bestand rechtfertigt keine allgemeine Unicode-Normalisierung, ICU-/Custom-Collation, zusätzliche Schlüsselspalten oder Sonderfallindizes. Die Entscheidung ist die kleine NOCASE-Archivlösung bei unverändertem MySQL-Verhalten; künftige Sonderzeichen sind dadurch nicht ausgeschlossen. Für die abgeleitete Statistik bleibt ausschließlich der bestehende vollständige Tagesvergleich mit 2 % beziehungsweise CTA mindestens fünf maßgeblich. Keine zusätzliche Zeichen-/Gruppen-Sperre und keine neue pauschale Freigabe: fehlende Coverage, technische Fehler, Überschreiten der Tagesgrenze und Archivkonflikte blockieren weiterhin. Geschäftliche Originaldaten und der native MySQL-Session-Schutz werden von dieser Archivstatistik-Grenze nicht verändert.

### Entscheidungen

1. **Generische Quelle:** `landing_session_engagements`; der ältere Kommentarschlüssel `premium_sms_landing_engagements` ist durch die vorhandene generische Repository-Konfiguration überholt. WordPress-Präfixe werden aufgelöst und nicht fest einprogrammiert.
2. **Zeitachsen:** Aufbewahrung nach `updated_at`; Statistik nach ursprünglichem `created_at`-Tag. Cutoff ist WordPress-Kalendermittnacht minus die genehmigte Anzahl Tage, mit strengem `<`. Bei WordPress-Tag 08.10. und 14 Tagen bleibt der 24.09. vollständig erhalten; Kandidaten liegen vor `2026-09-24 00:00:00`. Offset-Zeitzonen und DST sind zu prüfen.
3. **Session-Schutz und Schlüsselvergleich:** Jeder live Session-Datensatz mit demselben Landing-/Session-Schlüssel nach der vorhandenen MySQL-Kollation schützt das Engagement, unabhängig von seinem Tag. Native `=`-/`EXISTS`-/`NOT EXISTS`-Vergleiche beibehalten, nicht durch BINARY, `strcmp` oder neue Normalisierung ersetzen. Die Engagement-Zeile bleibt in MySQL; kein Engagement-Archiv-/Delete-Schritt gibt sie frei, solange eine passende live Session existiert. Bestehende Session-Prüflogik, MySQL-Kollationen und Unique-Keys werden nicht geändert. Für die neue abgeleitete Archivstatistik beide Schlüssel mit SQLite NOCASE vergleichen; ASCII-casegleiche Treffer nicht anschließend durch bytegenaue Paarfilter verwerfen. Eine ausschließlich intern abgeleitete ASCII-casegleiche Paar-/Tages-Deduplizierung darf Originalwerte nicht verändern. NOCASE bildet nicht sämtliche Unicode-/Leerzeichen-Regeln von MySQL nach; die dokumentierte Grenze bleibt innerhalb des bestehenden Tages-Toleranzvertrags, ohne neue Sonderfallmechanik. Vollständige Snapshot-/Receipt-/Delete-Verifikation bleibt unabhängig davon bytegenau. Diese Entscheidung vom 13.10. ersetzt „Groß-/Kleinschreibung unterscheiden“ vom 12.10.
4. **Eigenständiger Tagesnachweis:** Engagement verwendet vollständige Live-/Archivdaten und die bestehende kanonische Session-Paar-/Tageszugehörigkeit. Main und TK-zone werden getrennt als Tageskennzahlen geprüft. TK verwendet den bestehenden PID-Filter vor der Paarzusammenfassung und den gespeicherten PID-Set-Nachweis. Keine Wiederherstellung sämtlicher Browser-/Geräte-/TK-zone-Gruppen und kein Kopieren des Session-`effective_cutoff` auf diese mutable Quelle.
5. **Freigegebene Statistikregel:** Die sieben Kennzahlen sind `page_loaded_sessions`, `cta1_sessions`, `cta1_click_events`, `cta2_sessions`, `cta2_click_events`, `cta3_sessions`, `cta3_click_events`. Für jedes `created_at`-Datum und jede Kennzahl separat in Main und TK: `basis=max(abs(raw),abs(summary))`, `difference=abs(raw-summary)`. Page-loads bestehen bei `difference <= 0.02 * basis`; CTA bei `difference <= max(5,0.02*basis)`. Die Grenze ist einschließlich; ohne zusätzliche Prozentaufrundung exakt über `difference*100 <= basis*2` und bei CTA alternativ `difference<=5` vergleichen. Bei beiden Werten null ist die numerische Abweichung null; vorhandene Tages-Coverage bleibt unabhängig erforderlich. Keine Verrechnung zwischen Kennzahlen, Tagen oder Main/TK.
6. **Gruppen/Gleichstände entfallen:** Kein verpflichtender Gruppenabgleich, auch nicht als zusätzliche Pflichtdiagnose; keine Sonderlogik, neue globale Sortierregel oder historische Summary-Reparatur für gleiche Session-Zeitstempel. Im vorhandenen Audit pro Tag, Modus und Kennzahl Rohwert, Summary-Wert, absolute/prozentuale Abweichung, zulässige Toleranz und Ergebnis festhalten. Höhere Tagesabweichungen, fehlende Coverage und technische Fehler blockieren. Die 2-%-Regel gilt ausschließlich für abgeleitete Engagement-Statistik; Archive, Receipts, originale Sale-/Billing-Daten und bestehende Session-Gates bleiben streng beziehungsweise unverändert.
7. **Historische Ausnahme:** Ausschließlich `landing_session_engagements` mit `created_at >= '2026-04-23 00:00:00' AND created_at < '2026-07-06 00:00:00'` darf ohne Statistik-Nachweis freigegeben werden. Im Audit ausdrücklich als historische Ausnahme ausweisen. Keine dynamische Erweiterung anhand künftig fehlender Summary-Tage; alle übrigen Sicherheitsbedingungen gelten weiterhin.
8. **Fachliche Freigabe:** Vor Aktivierung einmalige human Fraud-/Billing-Freigabe der Frist und separate konkrete Cleanup-Aktivierung. Die Aktivierung ist eine Freigabe des künftig regelmäßig automatisch laufenden Mechanismus, keine erneute Zustimmung vor jedem Lauf. Jede Verkürzung benötigt erneute Fristfreigabe. Keine zusätzliche fallbezogene Hold-Verwaltung.
9. **Nebenläufigkeit:** `upsert_event()` erhält eine kurze tabellenbezogene Transaktion, damit ein schon gelesener alter Stand nicht nach einem Cleanup-Delete unbemerkt verloren geht. Archivzugriffe gehören ausschließlich in den Cleanup, nicht in diese Writer-Transaktion oder den Klick-/Sale-Flow.

### Empfohlener Implementierungsplan

1. **Quelle registrieren.** An die bestehende Scheduler-Reihenfolge nach Sessions und Handoffs anhängen. Vorhandene Defaults und den bestehenden generischen Einstellungsschlüssel nutzen. Source-Locks, Generation-Lock, Corruption-Gates, begrenzte Worker sowie getrennte Archive-/Delete-Cursor wiederverwenden. Source-spezifische Eligibility-Regeln gezielt ergänzen; Verhalten und Reihenfolge der bestehenden Quellen erhalten.

2. **Archivlesen und externe Indexvorbereitung ergänzen.** Vereinbarte neue Dateien:
   - `includes/services/class-retention-archive-reader.php`
   - `tools/database/migrations/retention-archive-read-indexes.php`
   - `tools/database/migrations/class-retention-archive-read-indexes-migration-service.php`

   Der Reader verwendet aus verifizierbarer Cleanup-/Archiv-Evidence abgeleitete, unter dem zulässigen Archivverzeichnis validierte Pfade und prüft Source-/Tabellenidentität. Kalenderjahr im Dateinamen ist kein Beweis für die enthaltenen Datenjahre. Abfragen laufen schreibfrei in begrenzten Keyset-Seiten und verwenden passende Indizes für Landing-/Session-Lookup sowie Engagement-Tagesseiten. Für die reine Tageskennzahl-Zuordnung benötigt der Session-Reader nur `landing_key`, `session_token`, `created_at`, `_source_pk` und `pid`: Main zählt ein passendes Paar am Tag einmal, TK zusätzlich nur ein nach bestehender PID-Normalisierung/Allowlist passendes Paar. Die vorhandene Pflicht zur Prüfung widersprüchlicher Source-ID-Snapshots bleibt erhalten; bei solchen Konflikten genügen diese fünf Zählfelder nicht als Vollständigkeitsnachweis. Der verpflichtende deckende Paarindex hat die Form `(landing_key COLLATE NOCASE ASC, session_token COLLATE NOCASE ASC, created_at COLLATE BINARY ASC, _source_pk COLLATE BINARY ASC, pid COLLATE BINARY ASC)`, nicht UNIQUE und nicht partiell. NOCASE ist ausschließlich für die beiden Zuordnungsschlüssel vorgesehen; Payload-/Receipt-Prüfungen werden dadurch nicht gelockert. Engagement-Tagesseiten verwenden `(created_at COLLATE BINARY ASC, _source_pk COLLATE BINARY ASC)`, ebenfalls nicht UNIQUE und nicht partiell; sämtliche Engagement-Felder bleiben beim Archivieren erhalten. Die Prototype-Indizes für komplette Session-Dimensionen und Session-Tages-Gruppenscans sind kein Pflichtumfang. Gleiche Source-IDs werden über Archive hinweg dedupliziert; live MySQL gewinnt über ältere archivierte Versionen. Widersprüchliche Archivstände, unlesbare Archive, fehlende Indexdefinitionen oder technische Fehler dürfen nicht als leere Datenmenge beziehungsweise bestandene Coverage gelten.

   Die Session-Paarabfrage vergleicht beide Parameter mit NOCASE und paginiert innerhalb des ausgewählten Tages nach `(created_at, _source_pk)`:

   ```sql
   SELECT landing_key, session_token, created_at, _source_pk, pid
   FROM <verifizierte Session-Archivtabelle>
   WHERE landing_key = :landing_key COLLATE NOCASE
     AND session_token = :session_token COLLATE NOCASE
     AND created_at >= :day_start
     AND created_at < :day_end
     -- ab der zweiten Seite:
     AND (created_at > :last_created_at
          OR (created_at = :last_created_at AND _source_pk > :last_source_pk))
   ORDER BY created_at ASC, _source_pk ASC
   LIMIT :page_size;
   ```

   `page_size <= 500`; auf der ersten Seite entfällt nur die Cursor-Bedingung. Beide Lookup-Prädikate und die Index-Key-Kollationen müssen übereinstimmen. Keine nachgeschaltete BINARY-Gleichheitsprüfung für die Session-Zuordnung. Source-ID-Deduplizierung und die vollständige Payload-Konfliktprüfung bleiben eigenständige Prüfungen.

   Das externe Werkzeug bietet schreibfreies `status` und ausdrücklich mutierendes `apply`, prüft vollständige Indexdefinitionen mit `index_list`/`index_xinfo` beziehungsweise `sqlite_master` statt nur Indexnamen (Spaltenfolge, ASC, NOCASE ausschließlich an den beiden Session-Schlüsseln, sonst BINARY, nicht UNIQUE, nicht partiell), ist idempotent und verwendet den vorhandenen Generation-Lock. Ein bereits gleichnamiger BINARY-Paarindex erfüllt den neuen Vertrag nicht: `status` meldet ihn als abweichend; ausschließlich externes `apply` ersetzt gezielt den betreffenden Leseindex unter Lock. Kein Umbau des vorhandenen Source-PK-Unique-Index, keine Payload-Umschreibung und keine Runtime-Reparatur. Lock-Konflikte beenden die Vorbereitung nachvollziehbar. Bestehende Archive werden ausschließlich extern vorbereitet; keine verdeckten runtime DDL-/Migrationsversuche und kein unbegrenzter Scan als Ersatz für fehlende Indizes. Beim regulären Anlegen einer neuen Jahres-/Quellarchivtabelle sind die erforderlichen Leseindizes Teil ihres Archivschemas. Globale SQLite-Health-Prüfungen bleiben extern; der bestehende Leseschutz wird eingehalten. Bei der externen Vorbereitung Speicherreserve, Lock-Dauer und niedrige Prozesspriorität berücksichtigen; die im Prototype gemessene mittlere I/O-Drosselung ist keine Zusage für Hosting-Account-IOPS.

3. **Engagement-Coverage auf vollständigen Tagen aufbauen.** Die betroffenen Original-`created_at`-Tage aus Alterskandidaten ermitteln. Für jeden Tag alle Engagements aus MySQL und Archiv zusammenführen, nicht nur die gerade löschbaren Zeilen. Kürzlich geänderte und durch Sessions zurückgehaltene Zeilen zählen ebenfalls mit. Nach früheren Cleanup-Läufen archivierte Engagements bleiben im Tagesvergleich enthalten. Passende kanonische Session-Paare des Tages aus live MySQL und Session-Archiven verwenden; Main benötigt nur die Paar-/Tageszugehörigkeit, TK zusätzlich die bestehende PID-Normalisierung und Allowlist vor der Paarzusammenfassung. Ein Engagement ohne passende kanonische Session erzeugt keine zusätzliche Session. Aus den bestehenden Main-/TK-Summaries nur die sieben Tageskennzahlen über sämtliche vorhandenen Gruppen summieren. Page-load und CTA1/2/3 nach der unter Entscheidungen festgelegten 2-%-/Fünferregel vergleichen; keine vollständige Gruppenrekonstruktion und keine Sonderbehandlung gleicher Zeitstempel; fehlende Coverage, Konflikte und technische Fehler sperren den Lauf außerhalb der ausdrücklich begrenzten historischen Ausnahme.

4. **Vorbereitung und Dry-run begrenzen.** Cutoff und höchste Source-ID für den Lauf festhalten; die Altersgrenze anhand bestehender Indizes bestimmen. Session-Abhängigkeiten, Counts, betroffene Tage und Coverage in fortsetzbaren Worker-Schritten verarbeiten. Standards: höchstens 500 Zeilen je Batch sowie 50.000 verarbeitete Zeilen oder 60 Sekunden je Aufruf, je nachdem was zuerst erreicht wird. Auch Reader- und Gate-Arbeit wird checkpointbar begrenzt, damit ein großer Tag kein unbegrenzter Spezialfall wird. Fortschritt, Ergebnisse, Ursachen und die Tagesabweichungen je Main-/TK-Kennzahl stehen im bestehenden Cleanup-Audit. Dry-run nutzt denselben vollständigen Prüfpfad und weist Alterskandidaten, Session-/Änderungszurückstellungen und Freigaben aus, archiviert und löscht aber keine Originaldaten. Vor vollständiger Gate-Freigabe keine Archiv-/Delete-Phase.

5. **Writer und mutable Snapshots schützen.** `upsert_event()` über den vorhandenen Unique-Key `(landing_key, session_token)` transaktional absichern: Zeile sicher anlegen beziehungsweise sperren, unter Sperre lesen, die bestehende Ereignislogik anwenden, aktualisieren und committen; Fehler rollbacken. Ein mögliches Verfahren ist ein idempotentes Anlegen über den Unique-Key mit anschließendem `SELECT ... FOR UPDATE`. Keine Archivzugriffe in dieser kurzen Transaktion und keine Änderung der CTA-, UA-, Source- oder Soft-flag-Fachregeln.

   Vor Archivierung Session-Schutz und aktuelle Zeile prüfen. Für diese mutable Quelle erneuert eine spätere Archivierung den vollständigen Snapshot derselben `_source_pk`; ein unveränderliches `INSERT OR IGNORE` darf einen neuen CTA-Stand nicht verwerfen. Vollständige Zeile und Receipt gemeinsam persistieren und den tatsächlichen Archivstand erneut prüfen. Unmittelbar vor Delete aktuelle MySQL-Zeile gegen alle archivierten Felder vergleichen, NULL-sicher und für Text ohne kollationsbedingtes Gleichsetzen verschiedener Inhalte. Delete zusätzlich atomar an die Altersgrenze und das Fehlen passender live Sessions binden. Geänderte oder neu geschützte Zeilen bleiben erhalten und werden im Audit nachvollziehbar zurückgestellt. Nie allein anhand einer archivierten ID oder lediglich `updated_at` löschen.

6. **Wiederaufnahme und Zähler konsistent machen.** Archive-/Delete-Cursor getrennt halten; belegte Batch-Receipts nach Abbrüchen erneut verwenden und verifizieren. Wiederholung darf weder erneut zählen noch geänderte Zeilen löschen. Tatsächlich entfernte Zeilen, bereits entfernte belegte Zeilen und Zurückstellungen so im bestehenden Audit erfassen, dass ein Abbruch zwischen Delete und Audit-Fortschritt korrekt fortsetzbar bleibt. Keine neue zusätzliche WP-Last-result-Option. Das ist Teil der notwendigen Engagement-Erweiterung, kein allgemeiner Retention-Umbau.

7. **Tests und fokussierte Dokumentation fertigstellen.** Die maßgeblichen Issue-Abschnitte `Akzeptanzkriterien` und `Manuelle Tests` vor PR-Erstellung mit Belegen abarbeiten und nur tatsächlich bestandene Prüfungen abhaken. Den gemeinsamen Receipt-/Corruption-Vertrag und den Archiv-Leseschutz der aktuellen main-Version berücksichtigen. Spätere Deployment-/Production-Schritte ausschließlich im nachfolgenden Abnahmeabschnitt belassen.

### Risiken

- Vollständige Engagement-Snapshots und Pflicht-Leseindizes brauchen zusätzlichen SQLite-Speicher. Für die damaligen zwei breiten Testindizes wurden auf der vollständigen Hostinger-Kopie 409,92 MiB und 102,642 s gedrosselte Vorbereitung gemessen; das ist keine Messung der endgültigen kleineren Indexauswahl oder des vollständigen Engagement-Backlogs.
- Fehlende Main-/TK-Tages-Coverage, Überschreiten der freigegebenen Tages-Toleranzen, technische Fehler und Archivkonflikte sperren weiterhin. In den 26 gemessenen Tagen ergibt die freigegebene Tagesregel keine Statistik-Sperre; daraus folgt keine Garantie für andere Tage oder Konfigurationen.
- SQLite NOCASE ist außerhalb der nachgewiesenen ASCII-Kennungen kein vollständiger Ersatz für `utf8mb4_unicode_520_ci`; künftige Sonderzeichen können abgeleitete Archiv-Tageszahlen verändern. Die vereinbarten Tages-Toleranzen beziehungsweise bestehenden Coverage-Sperren gelten weiter. Die Bestandsmessung mit 0 Abweichungen ist keine Zukunftsgarantie; kein Umbau vorhandener MySQL-Prozesse und keine Lockerung von Archiv-/Receipt-/Delete-Sicherheit.
- Die Session-Abhängigkeit kann Engagement länger als 14 Tage erhalten. Die Frist ist die früheste Altersfreigabe, kein garantiertes Löschdatum.
- Mutable Daten, konkurrierende Writer und Abbrüche benötigen die vorgesehenen echten MariaDB-/SQLite-Tests. Prototyp-Zeiten und PHP-Speicherwerte ersetzen keinen vollständigen WordPress-/Cleanup-Nachweis und keine Hosting-Account-Messung.
- Die einmalige fachliche Fristfreigabe und die spätere Freigabe des automatischen Cleanup-Mechanismus bleiben erforderlich. Die technische Bereitschaft des Issues ersetzt keine Betriebsfreigabe.

### Empfohlene Tests

Die Checkboxen vor PR-Erstellung bleiben ausschließlich im Issue-Text. Hier stehen Testmethoden und erwartete Nachweise:

- WordPress-Kalendertage mit 14 und sieben Tagen, strenger `<`-Grenze, Offset-Zeitzone, PHP UTC/Europe-Berlin und DST-Wechsel; `updated_at` schützt neu geänderte alte Engagements.
- Disabled und vollständiger, begrenzter Dry-run: keine Originalarchivierung, kein Delete; Fortschritt und Kandidaten-/Zurückstellungs-/Freigabezahlen korrekt.
- Historische Ausnahme an beiden Datumskanten, nur für Engagement; fehlende Coverage danach, fehlender TK-Nachweis, Query-/Archivfehler und unlesbare beziehungsweise widersprüchliche Archive blockieren.
- Die sieben Tageskennzahlen für Main und TK separat unterhalb, auf und oberhalb der exakt gerechneten 2-%-Grenze; für CTA zusätzlich die Mindesttoleranz fünf. Kleine Zahlen, beide Werte null, größere Summary-Werte als raw und keine Verrechnung zwischen Metriken/Tagen/Modi abdecken. Konkrete Grenzen: Page-load 100/98 besteht, 100/97 sperrt; CTA 25/24 und 25/20 bestehen, 25/19 sperrt; CTA 1.000/980 besteht, 1.000/979 sperrt. Fehlende Coverage besteht auch bei null/null niemals allein aufgrund der Werte.
- Live-/Archiv-Source-ID-Deduplizierung, kanonische Session-Paar-/Tageszuordnung, TK-PID-Allowlist vor Paarzusammenfassung und vollständige Tageszählung mit zurückgehaltenen beziehungsweise bereits früher archivierten Engagements. Gleiche Zeitstempel mit unterschiedlichen Browser-/TK-zone-Werten dürfen bei passenden Tageszahlen keinen zusätzlichen Gruppenblocker auslösen; keine Sonderlogik oder historischen Summary-Reparaturen dafür. Die Tagesabweichungen sind im Audit nachweisbar.
- ASCII-Groß-/Kleinschreibungsvarianten gegen die tatsächliche MySQL-Kollation und echte SQLite-NOCASE-Indizes prüfen: gleiche Main-/TK-Paarzugehörigkeit und kein falscher Coverage-Blocker im früher gescheiterten Fall. Lookup, Seitenfortsetzung und abgeleitete Paar-Deduplizierung müssen NOCASE konsistent nutzen. Künstliche Unicode-/Leerzeichen-Kontrollen dokumentieren die bekannte NOCASE-Grenze; nicht fälschlich als universellen MySQL-Gleichheitsnachweis ausgeben und keine zusätzliche Sonderfallmechanik einführen. Die freigegebenen Tages-Toleranzen einschließlich höherer Abweichung als Sperre bleiben getestet.
- Session-Schutz vor Archivierung und erneut beim Delete ausdrücklich nach nativen MySQL-Regeln; eine ausschließlich caseabweichende passende live Session schützt ebenfalls. Beim vollständigen Payloadvergleich muss dagegen bereits eine caseabweichende Feldänderung das Delete verhindern; eine zwischen den Phasen hinzugekommene passende live Session verhindert das Delete.
- Vollständige Snapshots inklusive CTA-/Source-/UA-/Soft-flag-Feldern, NULL gegen Leerstring und verschiedene Textinhalte; erneute Archivierung aktualisiert denselben Source-PK; veraltetes Receipt beziehungsweise abweichender Payload löscht nichts.
- Zwei echte Verbindungen auf einer temporären lokalen MariaDB 11.8.9: Schreiben vor/unter/nach dem Cleanup-Lock, sichere Neuerstellung, verlorene Updates und Delete-Rennen; eine minimale schemaidentische Testdatenbank genügt.
- Reale SQLite-Fixtures: exakte Indexdefinitionen einschließlich NOCASE nur an beiden Session-Paarschlüsseln und BINARY an den übrigen Feldern, `status → apply → status`, Idempotenz, externer Ersatz eines gleichnamigen alten BINARY- oder sonst falsch definierten Leseindex ohne Payload-/Receipt-Änderung, Generation-Lock-Konflikt und passende `EXPLAIN QUERY PLAN`-Ergebnisse für die reduzierten Tages-/Paar-Lookups ohne kompletten Dimensionsabgleich. Vorhandene breite Prototype-Messungen nicht als Messung der endgültigen Indizes ausgeben.
- Grenzen von 500 Zeilen, 50.000 Zeilen und 60 Sekunden samt Fortsetzung; keine Archive-/Delete-Phase vor Abschluss sämtlicher Vorbereitungsgates.
- Abbruch nach SQLite-Commit, vor/nach Receipt-Persistierung, nach Delete vor Audit-Fortschritt: korrekte Wiederaufnahme, keine Doppelzählung, richtige Cursor und nachvollziehbare Zurückstellungen.
- Gemeinsame Lock-/Corruption-/Receipt-Regressionen und die vorhandene Testsuite mit tatsächlich verfügbarem SQLite; Stand und nicht gelaufene Prüfungen ausdrücklich dokumentieren.

### Abnahme nach erfolgreichem Merge

Offene Punkte dieses Abschnitts blockieren die PR-Erstellung nicht. Alle Prüfungen beziehen sich auf den tatsächlich gemergten und später konkret freigegebenen Stand.

- codex: Zugehörige PR und erfolgreichen Merge nach `main` mit PR-Link, Merge-SHA und vorgesehenem Release-Stand belegen; ein lokal abgeschlossener Test oder offener PR genügt dafür nicht.
- human: Deployment dieses konkreten Release-Stands und externe Archivvorbereitung für die konkret benannten Production-Archive freigeben.
- codex: Freigegebenen Code mit deaktiviertem Engagement-Cleanup bereitstellen; externe Archivvorbereitung über `status → apply → status` durchführen und vollständige Indexdefinitionen, Generation-Lock und vorhandenen Archiv-Leseschutz belegen. `apply` erst nach der obigen Freigabe.
- codex: Einen ausdrücklich freigegebenen Production-Dry-run mit `dry_run=true` auswerten; abgeschlossene Vorbereitung, Kandidaten, Session-/Änderungszurückstellungen, Main-/TK-zone-Gates und historische Ausnahme dokumentieren. Belegen, dass Originaldaten weder archiviert noch gelöscht wurden.
- human: Die 14-Tage-Frist für Fraud-/Billing-Review fachlich freigeben; offene fachliche Fragen klären. Jede spätere Verkürzung benötigt erneute Freigabe.
- human: Aktiven Engagement-Cleanup als künftig regelmäßig automatisch laufenden Mechanismus für den belegten Release-, Konfigurations- und vorbereiteten Archivstand einmalig ausdrücklich freigeben; keine neue human Freigabe pro Lauf.
- codex: Nach dieser Freigabe den tatsächlichen Lauf mit Archiv-, Receipt-, Delete- und Zurückstellungszahlen, Cutoff und Audit-/Cursor-Evidence prüfen und dokumentieren; Fehler als Sperre behandeln, keine synthetischen Sale-/Billing-Requests verwenden.
- human: Ergebnis der späteren Abnahme bewerten und entscheiden, ob Issue #72 abgeschlossen werden kann.

### Dokumentation

`docs/architecture/retention-architecture.md`: source-spezifischer mutable Snapshot-/Receipt-Vertrag, Session-Abhängigkeit, Live-/Archiv-Kanonisierung und eigene Engagement-Tages-Coverage mit 2 % je Kennzahl beziehungsweise mindestens fünf CTA-Zählungen; Entfall des Pflicht-Gruppenabgleichs und besonderer Gleichstandslogik. Die bestehende Session-Coverage behält ihren eigenen Vertrag. Native MySQL-Paarzuordnung, gezielte NOCASE-Archivnachbildung samt dokumentierter Unicode-/Leerzeichen-Grenze und davon getrennte bytegenaue Snapshot-Verifikation ausdrücklich beschreiben. `docs/operations/retention-runbook.md`: externe Indexvorbereitung, begrenzte Vorbereitung/Dry-run, historische Ausnahme, Tagesabweichungen im Audit, Fehler-/Zurückstellungsbelege und spätere einmalige fachliche Frist-/Mechanismusfreigabe für automatische Folgeläufe. `docs/operations/configuration-reference.md`: vorhandener generischer Schlüssel, 14 Tage, mindestens sieben, `updated_at` und Defaults. Bei einem neuen fokussierten Dokument den passenden Bereichsindex ergänzen. Keine Änderung an `AGENTS.md`.

### Nicht-Ziele

Keine Änderung an Sale-, Billing-, Aggregator-, Premium-SMS-Flow-, Fraud-Signal- oder bestehender Session-Prüflogik, MySQL-Kollationen oder Unique-Keys. Keine Umstellung bestehender Prozesse auf Groß-/Kleinschreibungsunterscheidung, allgemeine Unicode-Normalisierung/Custom-Collation, zusätzliche Normalisierungsspalten oder Sonderfallindizes. Kein Pflicht-Gruppenabgleich, keine Tie-Breaker-Sonderlogik und keine historische Summary-Reparatur. Keine zusätzliche fallbezogene Hold-Verwaltung, kein kompletter WordPress-Teststack, keine allgemeine Summary-Datumsreparatur und keine ungeprüften MySQL-Indizes. Keine automatischen Production-Schreibschritte oder Aktivierung aufgrund dieses Reports. Der jetzige Auftrag endet mit der verifizierten Veröffentlichung des Planner-Reports.

### Issue-Bindung

- Issue: `#72` — https://github.com/mpetrovic-hub/backend/issues/72
- Task-ID: `issue-72`
- Empfohlener späterer Branch: `codex/issue-72-engagement-retention`
- Empfohlene PR-Verknüpfung: `Refs #72`
- Projekt: Risk=`High`, Size=`L`; Status=`Ready for Codex` erhalten.
- Labels nach verifizierter Veröffentlichung: `1b - codex-planned`, `2 - codex-implement-ready`; `0 - codex-candidate` erhalten.
