# Issue #73 – Retention-Cleanup für `wp_kiwi_landing_handoff_events`

> **Temporäre Planungsnotiz.** Diese Datei hält die bisherigen Entscheidungen
> für Issue #73 fest. Sie ist keine dauerhafte Betriebs- oder
> Architekturdokumentation und wird nach dem finalen Planner-Report entfernt
> oder in die dafür vorgesehenen Dokumente überführt.
>
> **Entschieden:** Für Handoff-Events gibt es bewusst **kein Coverage-Gate**
> gegen die Main Summary. Die Retention bestätigt damit die sichere
> Archivierung, nicht erneut die Vollständigkeit der Analytics-Summary.

## Ziel

Die Rohereignisse aus `wp_kiwi_landing_handoff_events` sollen nach **21
vollständigen Tagen** sicher archiviert und anschließend aus MySQL entfernt
werden können. Die bestehende Retention-Infrastruktur – Audit-Run, Sperren,
SQLite-Archiv mit Receipts, Korruptionsprüfung und Wiederaufnahme – wird dafür
wiederverwendet.

Die Handoff-Retention prüft vor der Entfernung **nicht** erneut, ob die Werte
in `wp_kiwi_landing_funnel_daily_summary` enthalten sind. Die stündliche
Summary-Aktualisierung und ihre siebentägige Wiederholungsfrist bleiben davon
unberührt.

## Ausgangslage

- Die Retention-Quellenregistrierung kennt bislang nur
  `landing_page_sessions`; für Handoff-Events fehlt die Quellenanbindung.
- Die Konfiguration enthält bereits einen deaktivierten Handoff-Eintrag. Seine
  Standard-Aufbewahrungsdauer wird auf 21 Tage gesetzt; die Retention bleibt
  standardmäßig deaktiviert und ein Dry-Run.
- Der bestehende Retention-Service arbeitet bereits quellenbezogen. Scheduler
  und Worker sind aber bislang auf Sessions fest verdrahtet.
- Die Main Summary wird stündlich erneuert. Sie verarbeitet jeweils einen Tag
  aus einem Fenster von heute und den vorherigen sieben Kalendertagen.
- Die Main Summary ist sessiongeführt. Deshalb wäre ein einfacher Vergleich
  der Handoff-Tabelle mit der Summary nicht belastbar: deren Datum und
  Betriebssystem stammen heute aus der Landing-Session.

## Festgehaltene Entscheidungen

1. **Stabiler Quellenname:** Die Handoff-Retention verwendet überall den
   Schlüssel `landing_handoff_events`.
2. **Sicherer Standard:** Der Standard ist `enabled=false`, `dry_run=true` und
   `retention_days=21`. Die bestehende Mindestfrist von sieben Tagen bleibt
   erhalten; es gibt keine Ersatzfrist bei ungültiger Konfiguration.
3. **Zeitgrenze:** Ein Handoff-Ereignis wird erst berücksichtigt, wenn
   `created_at` vor dem Tagesbeginn von „heute minus 21 Tage“ liegt. Dadurch
   bleiben 21 vollständige Kalendertage erhalten.
4. **Ein gemeinsamer Ablauf:** Die Retention erhält keinen separaten
   Handoff-Scheduler mit doppelter Logik. Der tägliche Scheduler zählt die
   registrierten Quellen in stabiler Reihenfolge auf. Der Worker erhält den
   Quellen-Schlüssel als Argument.
5. **Bestehende Kompatibilität:** Ein alter Worker-Aufruf ohne Argument
   verarbeitet weiterhin `landing_page_sessions`.
6. **Kein Handoff-Coverage-Gate:** Der Handoff-Lauf fragt weder die
   Landing-Sessions noch die Main Summary ab. Er führt keinen
   Analytics-Vollständigkeitsnachweis und blockiert deshalb nicht wegen
   `coverage_gate_failed`.
7. **Keine schwache Löschfreigabe:** Ein Vergleich nur nach Handoff-Datum,
   `landing_key` und `ua_ch_platform` wird nicht als Gate verwendet. Er wäre
   wegen der abweichenden Summary-Semantik kein belastbarer Nachweis.
8. **Geschlossener Sicherheitsablauf:** Der vorhandene Ablauf friert die
   Ziel-IDs ein, archiviert sie mit Receipts in SQLite, prüft das Archiv erneut,
   lässt die bestehende Korruptionsprüfung laufen und löscht erst dann die
   Rohdaten. Cursor und Audit-Spur bleiben quellenbezogen.
9. **Kein globaler Laufzeit-Health-Check:** Die aktuelle schlanke
   Archive-Health-Prüfung bleibt erhalten. Ein kompletter SQLite-Check gehört
   nicht in WP-Cron; die Änderungen aus #110/#113 bleiben maßgeblich.

## Verhalten ohne Coverage-Gate

1. Der Retention-Service berechnet den angeforderten 21-Tage-Cutoff.
2. Für `landing_handoff_events` überspringt er ausschließlich die
   Coverage-Prüfung; Sperren, Konfiguration, Audit und alle Archiv-Sicherheits-
   schritte bleiben aktiv.
3. Er arbeitet den vollständigen angeforderten Cutoff ab, sofern die übrigen
   vorhandenen Sicherheitsprüfungen dies zulassen.
4. Ein fehlender oder fehlerhafter Summary-Refresh verhindert die Handoff-
   Archivierung nicht und erzeugt für diese Quelle keinen Coverage-Gate-Skip-
   Incident.

## Vorläufiger Implementierungsplan

1. Die Handoff-Quelle mit Tabellenname, Primärschlüssel, `created_at`-Cutoff,
   Archiv-Mapping und Standardkonfiguration in der zentralen Quellenregistrierung
   ergänzen.
2. Scheduler und Worker quellenbezogen machen: registrierte Quellen in stabiler
   Reihenfolge einplanen, Quellen-Schlüssel als Worker-Argument übergeben und
   den bisherigen argumentlosen Session-Worker kompatibel halten.
3. In der Quellenregistrierung festlegen, dass `landing_handoff_events` keine
   Coverage-Prüfung benötigt; das bestehende Session-Gate bleibt unverändert.
4. Den vorhandenen Archive-/Receipt-/Reverify-/Corruption-/Delete-Ablauf mit
   der neuen Quelle ausführen; keine parallele Archivierungslogik schaffen.
5. Tests in `tests/run-tests.php` für Quellenregistrierung, Konfiguration,
   Scheduler/Worker-Kompatibilität, den ausgelassenen Coverage-Gate-Aufruf,
   den vollständigen 21-Tage-Cutoff und den vollständigen Archivierungsablauf
   ergänzen.
6. Nach der Implementierung die bestehenden Retention-Dokumente sowie den
   `CHANGELOG.md` gezielt aktualisieren;
   keine neue dauerhafte Dokumentstruktur schaffen.

## Nicht Teil von #73

- Löschen oder Archivieren von Landing-Sessions, Engagements, CTA-, Verkaufs-
  oder sonstigen Rohdaten.
- Änderung der Handoff-Erfassung, Session-Verknüpfung oder Aggregator-Logik.
- Nachträgliche Datenreparatur, Rückgewinnung alter Daten oder Aktivierung der
  Retention in Produktion.
- Globale SQLite-Integritätsprüfungen in WP-Cron.
- Ein Handoff-Coverage-Gate, ein Summary-Abgleich als Löschfreigabe oder ein
  nachträglicher Nachweis der Analytics-Vollständigkeit.
- Duplizierung der gemeinsamen Retention-, Archiv-, Lock-, Audit- oder
  Operational-Event-Logik.

## Referenzen

- Issue: [#73](https://github.com/mpetrovic-hub/backend/issues/73)
- Retention-Änderungen: [#106](https://github.com/mpetrovic-hub/backend/issues/106),
  [#110](https://github.com/mpetrovic-hub/backend/issues/110),
  [#113](https://github.com/mpetrovic-hub/backend/issues/113)
- Quellenregistrierung: `includes/services/class-retention-source-registry.php`
- Retention-Ablauf und Scheduler: `includes/core/class-plugin.php`,
  `includes/services/class-retention-cleanup-service.php`
- Bestehendes Session-Gate (unverändert): `includes/services/class-retention-coverage-gate.php`
- Handoff-Rohdaten: `includes/repositories/class-landing-handoff-event-repository.php`
- Summary-Aggregation: `includes/services/class-landing-funnel-daily-summary-aggregation-service.php`
- Tests: `tests/run-tests.php`
- Bestehende Runbooks: `docs/operations/retention-runbook.md`,
  `docs/architecture/retention-architecture.md`
