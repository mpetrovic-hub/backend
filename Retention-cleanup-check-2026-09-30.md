# Retention cleanup check — 2026-09-30

## Auftrag und Reihenfolge

Zuerst die bestehenden `landing_page_sessions`-Retention-Läufe sicher geradebiegen. Erst danach separat untersuchen und planen, wie späte Zusammenfassungsänderungen und Abhängigkeiten zwischen Sessions- und Handoff-Retention künftig vermieden werden.

## Produktionsbefund

Die Prüfung erfolgte über die Backend-WordPress-Installation auf Hostinger. Die erfolgreichen Datenbankabfragen waren lesend.

- Der erste zusammenhängende Skip war Lauf `113` am 2026-09-14. `enabled=1`, `dry_run=0`, Cutoff `2026-08-31`. Für den 2026-08-30 stimmten Handoff-Versuche mit der Zusammenfassung überein (`2888`); CTA1-Klicks wichen aber um 8 ab (`5484` Rohdaten, `5476` Zusammenfassung, Toleranz 6). Das Gate meldete zusätzlich `hard_dimension_mismatch`.
- Die Hauptzusammenfassung für 2026-08-30 war zuletzt am 2026-09-06 aktualisiert worden. Zwei zugehörige Engagement-Datensätze wurden danach geändert; der jüngste Änderungszeitpunkt war 2026-09-13. Das aktive Zusammenfassungsfenster beträgt standardmäßig sieben Tage. Die genaue Ursache der verspäteten Änderungen ist aus den Datenbankständen nicht ersichtlich.
- Handoff-Läufe liefen trotz der Session-Skips weiter. Handoff-Lauf `128` vom 2026-09-21 hatte Cutoff `2026-08-31`; 5.773 Zeilen wurden archiviert und gelöscht, ohne Duplikate. Die Receipt ist als verifiziert protokolliert; `external_health_deferred` bedeutet, dass der separate Archiv-Health-Check für diesen Lauf nicht bestätigt ist.
- Seit dem nächsten Tag meldet das Session-Gate für ältere Tage Handoff-Rohwerte `0`, während die Zusammenfassung noch Handoff-Werte enthält. Der aktuelle Auditbericht zeigt die Tage 2026-08-30 bis 2026-09-06 blockiert. Dies ist eine zusätzliche Folge der laufenden Handoff-Retention.
- Neuester Session-Lauf `143` vom 2026-09-29, Cutoff `2026-09-15`: 72.440 berechtigte Zeilen, 0 archiviert, 0 gelöscht. Vom 2026-09-14 bis 2026-09-29 gab es 16 tägliche Session-Skips.

## Abhängigkeit

Der Coverage-Gate-Pfad für `landing_page_sessions` liest `wp_kiwi_landing_handoff_events`, um Handoff-Zahlen mit der Hauptzusammenfassung abzugleichen. Die Retention-Registry führt Handoffs zugleich als separate Quelle ohne eigenes Coverage-Gate. Die aktuelle Session-Prüfung hängt damit von Handoff-Rohdaten in MySQL ab, auch nachdem Handoff-Läufe diese Daten receipt-gesichert ins Archiv verschoben haben.

## Angeforderte Sofortmaßnahme

1. Den vorgesehenen Read-only-Archivcheck für das in Lauf `128` verwendete Archiv ausführen.
2. Einen unterstützten datumsbezogenen Abgleich für die blockierten Tage 2026-08-30 bis 2026-09-06 ermitteln bzw. verwenden: Sessions/Engagement aus den noch vorhandenen Rohdaten und Handoffs aus dem Archiv berücksichtigen.
3. Danach Zusammenfassung und Coverage-Gate erneut prüfen. Keine Toleranzänderung, kein Überspringen des Gates und keine reine Neuberechnung aus den Live-Tabellen, die archivierte Handoff-Werte zu null machen könnte.

Ein vorhandener, geprüfter Produktionsbefehl für diesen Abgleich ist noch nicht festgestellt. Vor jeder Änderung wird geprüft, ob ein vorhandener unterstützter Ablauf existiert. Der separate Entwurf zur künftigen Vermeidung ist ausdrücklich zurückgestellt.

## Zwischenstand der Reparaturprüfung

- Im Repository ist kein öffentlicher Befehl zum Auslesen/Wiederherstellen archivierter Quellzeilen oder zum datumsbezogenen Gate-Abgleich dokumentiert. Die Summary-Aggregation kann zwar einen Datumsbereich neu berechnen, liest Handoff-Daten dafür jedoch aus der Live-MySQL-Tabelle.
- Der Read-only-Archivcheck für `kiwi_retention_archive_2026.sqlite` ist mit `quick=ok` und `integrity=ok` abgeschlossen (`sqlite_check_ok`, kein Write-Block).
- Eine reine Neuberechnung der Main-Zusammenfassung aus Live-Tabellen wäre jetzt nicht sicher: Die Handoff-Zeilen für die betroffenen Tage sind dort bereits entfernt. Ein geprüfter datumsbezogener Abgleich, der die receipt-verifizierten Archivzeilen verwendet und danach das Coverage-Gate erneut ausführt, ist im Repository nicht vorhanden.
- Der eng begrenzte Wiederherstellungs-Task ist in [GitHub Issue #135](https://github.com/mpetrovic-hub/backend/issues/135) erfasst. Er umfasst nur den Abgleich der blockierten Tage und das erneute Gate; die spätere Präventionsanalyse ist ausgeschlossen.
- Es wurden noch keine Produktionsdaten oder Retention-Einstellungen geändert. Vor jeder Datenänderung muss ein unterstützter Reparaturweg festgelegt und reviewt werden.
