# Issue #108 – Retention-Cleanup-Skips als Operational Incidents

> **Temporäre Planungsnotiz.** Diese Datei ist die laufende Entscheidungsgrundlage
> für Issue #108. Sie ist keine dauerhafte Betriebs- oder Architekturdokumentation
> und wird nach dem finalen Planner-Report entfernt oder in die passenden
> bestehenden Dokumente überführt.

## Ziel

Ein echter Retention-Lauf, der wegen `coverage_gate_failed` sicher nicht löscht,
soll als klarer Operational Incident sichtbar sein. Die Retention-Sicherheitslogik
und die bereits vorhandene Audit-Spur bleiben unverändert.

## Ausgangslage

- Der Retention-Audit-Run liegt in `wp_kiwi_retention_cleanup_runs`.
- Die vorhandene Gate-Verzweigung speichert dort `status=skipped` und
  `error_code=coverage_gate_failed`, erzeugt aber noch keinen Operational Event.
- `Kiwi_Operational_Event_Service` stellt bereits append-only Lifecycle,
  Idempotenz, Korrelation und zentrale Sanitizing-Grenzen bereit.
- Der bestehende Timeout-Incident bleibt ein getrenntes Verhalten. Die fehlenden
  Daily-Summary-Daten, Backfills und jede Produktionsreparatur sind nicht Teil
  dieses Issues.

## Festgehaltene Entscheidungen

1. **Getrennte Incidents:** Coverage-Gate-Skips erhalten je Retention-Quelle
   den stabilen Korrelationsschlüssel `retention_cleanup_skip_<source_key>`.
   Der bestehende Timeout-Incident bleibt getrennt und kompatibel.
2. **Generische Zukunft:** Die Lösung soll für künftige Retention-Quellen
   nutzbar sein, nicht nur für `landing_page_sessions`.
3. **Kleine Erweiterungsgrenze:** Es entsteht nur ein kleiner, normalisierter
   Event-Vertrag für Gate-Ergebnisse. Die vorhandene Gate-Logik wird nicht zu
   Strategien oder Interfaces umgebaut.
4. **Enger Auslöser:** `retention_cleanup_skipped` steht ausschließlich für
   `coverage_gate_failed`; Lock, deaktivierte Retention und Dry-Run bleiben
   ohne diesen Error-Incident.
5. **Audit zuerst:** Ein Skip-Event entsteht nur, wenn der finale Audit-Run
   erfolgreich als `skipped / coverage_gate_failed` gespeichert wurde.
6. **Kompakter Kontext:** Der Event enthält Quelle, Reason-Code, Gate-Status,
   angeforderten Cutoff, ersten blockierten Tag bzw. Grund,
   `verified_until_date` und höchstens drei `blocking_errors`.
   `effective_cutoff_value` erscheint nur, wenn der vorhandene Gate-Output ihn
   tatsächlich sinnvoll belegt. Die vollständige Gate-Diagnostik bleibt im
   referenzierten Audit-Run.
7. **Qualifizierte Recovery:** Ein realer, final audit-persistierter
   `completed` oder `completed_noop`-Lauf schließt den offenen Skip-Incident.
   `completed_noop` bedeutet: Der sichere Normalpfad lief erfolgreich, es gab
   lediglich keine archivierungsfähigen Zeilen.
8. **Best Effort:** Scheitert nur das Schreiben des Operational Events, bleibt
   der Retention-Lauf ein erfolgreicher Sicherheits-Skip. Keine zusätzlichen
   Fallbacks, keine nachträgliche Umdeutung in einen technischen Lauf-Fehler.
9. **Ein Event pro Lauf:** Derselbe Audit-Run erzeugt höchstens einen Event.
   Jeder spätere neue Gate-Skip derselben Quelle hängt als `repeated` an den
   offenen Incident an.
10. **Sichere Validierung:** Ein Gate-Fehler wird nur in einer sicheren
    Testumgebung simuliert. Nach dem Deployment wird Produktion beim nächsten
    natürlichen Lauf beobachtet, ohne absichtlich eine Sicherheitsblockade
    auszulösen.
11. **Einheitlicher Event-Typ:** Der schließende Eintrag behält
    `event_type=retention_cleanup_skipped`; ausschließlich
    `lifecycle_action=resolved` zeigt die Behebung an.
12. **Nachweis der Behebung:** Der `resolved`-Eintrag referenziert den späteren
    erfolgreichen Retention-Run. Der ursprüngliche Skip-Event referenziert
    weiterhin den Run, den das Gate blockiert hat.
13. **Normale Aufbewahrung:** Skip-Incidents folgen ohne Sonderfall der
    bestehenden Aufbewahrungsfrist für Operational Events.
14. **Bestehende Dokumentation:** Nur
    `docs/architecture/operational-events.md`,
    `docs/operations/operational-events-runbook.md` und
    `docs/operations/retention-runbook.md` werden ergänzt; es entsteht keine
    neue dauerhafte Dokumentdatei.
15. **Ein aktueller Report:** Nach Abschluss aller Entscheidungen wird der
    vorhandene einzelne Planner-Report im Issue inhaltlich mit der besten,
    widerspruchsfreien Fassung aus bisherigem Report und dieser Notiz
    aktualisiert. Es entsteht kein zweiter Planner-Report.

## Entwurf des Implementierungsplans

1. Im Retention-Cleanup-Service den finalisierten Gate-Skip nach erfolgreicher
   Audit-Persistenz in einen normalisierten Incident-Input übersetzen.
2. `retention_cleanup_skipped` mit `area=retention`, `severity=error`, der
   konkreten Retention-Run-Referenz, pro-Run-Idempotenz und stabiler
   Quellen-Korrelation über den vorhandenen Operational-Event-Service schreiben.
3. Aus dem bereits vorhandenen Gate-Ergebnis ausschließlich die vereinbarte
   kompakte Diagnostik — einschließlich `verified_until_date`, höchstens drei
   `blocking_errors` und gegebenenfalls `effective_cutoff_value` — sowie die
   lesbare Meldung ableiten; keine Zusatzabfrage.
4. Beim qualifizierten erfolgreichen Abschluss den zugehörigen offenen
   Skip-Incident genau einmal auf `resolved` setzen, ohne die Timeout-Recovery
   zu verändern.
5. Retention- und Operational-Event-Tests für `raised`, `repeated`, `resolved`,
   ausgeschlossene Skip-Pfade und Best-Effort-Verhalten ergänzen.
6. Den Gate-Fehler in einer sicheren Testumgebung simulieren; Produktion nach
   Deployment nur beim nächsten natürlichen Lauf beobachten.
7. `docs/architecture/operational-events.md`,
   `docs/operations/operational-events-runbook.md` und
   `docs/operations/retention-runbook.md` gezielt um den neuen
   Producer-Vertrag ergänzen; keine neue dauerhafte Dokumentstruktur.

## Noch zu entscheiden

- Der vorhandene GitHub-Planner-Report bleibt bis dahin unverändert.

## Referenzen

- Issue: `#108` – Log failed retention-cleanup skips as operational incidents
- Runtime: `includes/services/class-retention-cleanup-service.php`
- Event-Lifecycle: `includes/services/class-operational-event-service.php`
- Gate: `includes/services/class-retention-coverage-gate.php`
- Tests: `tests/run-tests.php`
