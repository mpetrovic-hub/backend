# Zielbild
1. Geht es Dir primär um den physischen Tabellennamen in MySQL, um die PHP-Klassen/Repository-Namen, oder um beides?
    - Beides
2. Ist kiwi_landing_engagements Dein bevorzugter neuer Tabellenname, oder gibt es eine bessere fachliche Bezeichnung?
    - "kiwi_landing_session_engagements"
3. Soll "Landing Engagement" wirklich alle künftigen Flows abdecken, also Premium-SMS, Carrier-Billing, Click2SMS, Web2SMS, UA-Kontext, Traffic Source?
    - Ja
4. Soll der Refactor erst als Plan/Migrationsstrategie dokumentiert werden, oder willst Du danach direkt eine erste technische Zwischenstufe bauen?
    - Plan/Migrationsstrategie

# Migration & Produktion
5. Darf es irgendwann einen echten RENAME TABLE geben, oder bevorzugst Du dauerhaft eine Alias-/View-Schicht?
    - Ja
6. Wie kritisch ist Downtime bei dieser Tabelle? Darf eine Migration kurz locken, oder muss sie möglichst online/risikoarm laufen?
    - Ein kurzer kontrollierter Lock von ein paar Sekunden ist ok.
7. Gibt es externe Queries, BI-Reports, Admin-Tools oder manuelle SQL-Auswertungen, die direkt auf wp_kiwi_premium_sms_landing_engagements zugreifen?
    - Nein
8. Ist Multisite oder ein anderer WordPress-Prefix als wp_ relevant, oder reicht prefix-basiertes Verhalten wie aktuell?
    - Kein Multisite-/variabler Prefix-Bedarf; Tabellen bleiben produktiv mit `wp_...`.
9. Soll die alte Tabelle nach Migration als View/Alias weiter existieren, damit alte Queries nicht sofort brechen?
    - Nein. Keine alte View/Alias-Schicht nötig; Code, Tests und Docs werden vollständig auf den neuen Namen umgestellt.

# Kompatibilität
10. Wie lange muss Backward Compatibility für den alten Tabellennamen garantiert bleiben?
    - Bewusst nicht erforderlich.
11. Wäre eine Phase akzeptabel, in der Code intern generisch heißt, aber physisch noch die alte Tabelle nutzt?
    - Keine Übergangsphase; direkte vollständige Umstellung.
12. Sollen Tests künftig den neuen generischen Namen erwarten, oder sollen sie bewusst beide Namen/Kompatibilität prüfen?
    - Alles auf den neuen generischen Namen umstellen; keine Tests für alte Tabellenname-Kompatibilität.
13. Müssen Shortcodes/Admin-Reports weiterhin Premium-SMS-Wording zeigen, wenn sie Fraud-spezifisch sind, obwohl die Datenquelle generisch wird?
    - Ja, Premium-SMS-Wording bleibt dort erhalten, wo Shortcodes/Admin-Reports fachlich Premium-SMS-Fraud behandeln; nur Datenquelle und generische Engagement-Komponenten werden umbenannt.

# CTA-Legacy-Spalten
14. Sollen first_cta_click_at, last_cta_click_at, cta_click_count vorerst als generische Kompatibilitätsschicht bleiben?
    - Ja. Sie bleiben als flow-übergreifende generische CTA-Zusammenfassung pro Landing-Session erhalten.
15. Ist die spätere Entfernung dieser Legacy-CTA-Spalten Teil dieses Issues, oder ausdrücklich ein separates Folge-Issue?
    - Keine Entfernung. Die allgemeinen CTA-Spalten sind keine zu entfernende Legacy-Schicht, sondern dauerhaft nützliche generische Session-Engagement-Metriken.
16. Wenn step-spezifische CTA-Auswertung vollständig ist: soll "generic CTA" weiterhin als Summe/first/last über CTA1-3 angeboten werden?
    - Variante A: Generic CTA bleibt als eigene gespeicherte Metrik erhalten (`first_cta_click_at`, `last_cta_click_at`, `cta_click_count`) und wird nicht nur aus CTA1/CTA2/CTA3 abgeleitet.

# Architektur
17. Wünschst Du einen neuen generischen Repository-Namen wie Kiwi_Landing_Engagement_Repository, mit altem Premium-SMS-Repository als dünnem Alias?
    - Ja, neuer generischer Repository-Name `Kiwi_Landing_Session_Engagement_Repository`; die alte Premium-SMS-Repository-Klasse wird nicht als Alias oder Kompatibilitätsschicht behalten.
18. Soll der Premium-SMS-Fraud-Code nur noch gegen ein generisches Interface arbeiten, z.B. Landing_Engagement_Repository, damit Fraud nicht Besitzer der Tabelle bleibt?
    - Ja. Premium-SMS-Fraud nutzt künftig das generische `Kiwi_Landing_Session_Engagement_Repository`; die Datenquelle gehört fachlich nicht mehr zum Premium-SMS-Namespace.
19. Soll eine zentrale Tabellen-Namensquelle eingeführt werden, damit Summary, Retention, Device-Harvest und Fraud nicht alle den Namen selbst zusammensetzen?
    - Ja. Eine zentrale Tabellen-Namensquelle ist gewünscht, auch als zukünftiges Muster für andere Tabellen, auf die mehrere Prozesse zugreifen.
20. Gibt es eine gewünschte Grenze: "nur Tabellen-/Repository-Benennung", keine Änderung an Event-Schema, Retention, Fraud-Logik oder Summaries?
    - Ja. Keine Event-Schema-Änderung in diesem Issue; nur Namen und Architekturgrenzen ändern. Retention, Fraud-Regeln und Summary-Berechnungen nur soweit anfassen, wie es für die Namensumstellung nötig ist.

# Rollout & Doku
21. Soll der Refactor feature-flagged/konfigurierbar sein, oder reicht ein schema-versionierter Migrationspfad?
    - Kein Feature Flag und keine dauerhafte Kompatibilitätsschicht.
    - Die frühere Entscheidung für einen WordPress-Runtime-Migrationspfad ist durch die externe Datenbank-Deployment-Architektur aus Issue #103 ersetzt.
    - Der Rename erfolgt als externer, Maintenance-gestützter Hard Cutover. Normale Website-, REST-, Admin-, AJAX-, WP-Cron- und Plugin-Worker-Laufzeit führt keine Schema- oder Einmal-Migration aus.
22. Welche Produktionsprüfung wäre Dir wichtig: bestehende Fraud-Ansicht, Landing Funnel Daily Summary, Tkzone Summary, Device Model Harvest, Retention Gate?
    - Besonders wichtig: Landing Funnel Daily Summary, Tkzone Summary, Device Model Harvest und Retention Gate. Ohne Landing Funnel Daily Summary/Tkzone Summary failt das Retention Gate und damit die Retention-Kette.
23. Soll die Migrationsstrategie in TODO.md, docs/architecture/, docs/operations/ oder als GitHub Issue/PR-Text dokumentiert werden?
    - Während der Planungsrunde nicht als bereits umgesetzter Live-Status in `docs/architecture/` oder `docs/operations/` schreiben. Die lokale Plan-Datei dient bis zum fertigen Handoff der Nachvollziehbarkeit.
    - Nach abgeschlossener Planungsrunde und erfolgreichem Planner-Prototyp wird der vollständige Plan mit `kiwi-github-implementation-plan-record` als einziger aktueller Codex Planner Report im bereits bestehenden GitHub Issue #96 festgehalten.
    - Die fokussierten dauerhaften Dokumentationsänderungen aus Entscheidung 37 sind anschließend verpflichtender Bestandteil der Implementierung und beschreiben erst dann den tatsächlich umgesetzten Stand.
24. Gibt es eine harte Deadline oder einen Zusammenhang mit Retention/DB-Größe, wegen der wir eher eine risikoarme Zwischenlösung wählen sollten?
    - Keine harte Deadline im Sinne eines festen Datums. Der Name-Refactor ist aber Blocker/Vorarbeit für GitHub Issue #72 (`Add retention cleanup for wp_kiwi_premium_sms_landing_engagements`) und hat daher Priority=High. Keine risikoarme Zwischenlösung gewünscht, sondern saubere vollständige Umstellung vor #72.
    - Der spätere Name-Refactor-Issue soll explizit festhalten, dass #72 nach dem Rename in Titel/Beschreibung/Akzeptanzkriterien auf `wp_kiwi_landing_session_engagements` angepasst werden muss.

# Neue Design-Entscheidungen nach Issue #103

Diese Entscheidungen ersetzen die frühere Annahme, dass der Rename aus dem WordPress-Runtime-Migrationspfad ausgeführt wird. Maßgeblich sind die Database-Deployment-Änderungen aus `CHANGELOG.md` vom 2026-07-22 und 2026-07-23, Issue #103 sowie der aktuelle externe Runner unter `tools/database/`.

25. Wie wird der direkte Rename mit der neuen externen Schema-Deployment-Grenze verbunden?
    - Als externer, Maintenance-gestützter Hard Cutover.
    - Der Implementer liefert den generischen Anwendungscode, den aktualisierten Schema-Contract und das migrationsspezifische Artefakt.
    - Der Deployment-Codex/Operator hält die Website beziehungsweise das abhängige Verhalten geschlossen, führt die externen Prüf- und Migrationsschritte aus und gibt die Website erst nach grünem Schema-Status und erfolgreichen Smoke-Checks wieder frei.
    - Es gibt weiterhin keine View, keinen Alias, kein Dual-Read, keine Dual-Table-Phase und keine dauerhafte Kompatibilität für den alten Namen.
    - Das generische `kiwi database apply` muss den vorhandenen alten Tabellennamen als Legacy-Zustand erkennen und fail-closed blockieren, statt zusätzlich eine leere neue Tabelle anzulegen.

26. Soll der Rename durch das generische `apply` oder durch ein eigenes Migrationsartefakt ausgeführt werden?
    - Durch ein eigenständiges, versioniertes Issue-#96-Migrationsartefakt unter `tools/database/`.
    - Das Artefakt bietet explizites read-only `check`, mutierendes `apply` und einen kontrollierten `rollback`.
    - `apply` führt den geprüften atomaren `RENAME TABLE` nur nach erfolgreichem Preflight und mit exklusivem Lock aus.
    - `rollback` ist nur im Maintenance-Fenster und vor der endgültigen Freigabe neuer Schreibzugriffe zulässig.
    - Das generische `kiwi database apply` führt den Rename nicht automatisch aus.

27. Wie weit soll die zentrale Tabellen-Namensquelle in diesem Issue reichen?
    - Es wird eine generische, später erweiterbare Tabellen-Namensquelle wie `Kiwi_Database_Table_Names` eingeführt.
    - In diesem Issue wird zunächst nur `kiwi_landing_session_engagements` zentral definiert.
    - Repository, Summary-Jobs, Retention Gate, Device Model Harvest, verwaltete Views und `tools/database` verwenden diese eine Definition.
    - Andere Kiwi-Tabellennamen werden nicht im selben Issue zentralisiert; das würde den Refactor unnötig verbreitern.

28. Wie wird verhindert, dass das neue generische Repository weiterhin fest von einer Premium-SMS-Fraud-Komponente abhängt?
    - Es wird ein neutraler interner Vertrag wie `Kiwi_Landing_Session_Engagement_Evaluator_Interface` eingeführt.
    - `Kiwi_Landing_Session_Engagement_Repository` hängt nur von diesem Vertrag ab und konstruiert nicht selbst den konkreten Premium-SMS-Service.
    - Der bestehende `Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service` implementiert den neutralen Vertrag.
    - Der Plugin-Aufbau injiziert den bestehenden Premium-SMS-Service weiterhin ausdrücklich in das Repository, sodass Regeln, Schwellwerte und gespeicherte Soft-Flag-Ergebnisse unverändert bleiben.
    - Schema-only-Aufrufe aus `tools/database` benötigen keine Fraud-Auswertung; produktive Schreibpfade dürfen den erforderlichen Evaluator nicht stillschweigend durch eine andere Policy ersetzen.
    - Das separate `Kiwi_Premium_Sms_Fraud_Signal_Repository` bleibt unverändert und ist keine Abhängigkeit des Landing-Session-Engagement-Repositories.

29. Was bedeutet Maintenance für den Hard Cutover?
    - `apply` und `rollback` dürfen nur in einem ausdrücklich bestätigten Maintenance-Fenster laufen.
    - Öffentlicher Website-, REST-, AJAX- und Admin-Traffic wird gestoppt; WP-Cron, externe Scheduler, Worker und andere schreibende WP-CLI-Aufrufe werden pausiert.
    - Der exklusive Migrations-Lock verhindert nur einen zweiten Migrationslauf und ersetzt diesen externen Schreibstopp nicht.
    - Kurzfristig fehlschlagende Requests oder Prozesse während des kontrollierten Fensters sind akzeptiert. Entscheidend ist, dass nach erfolgreicher Migration und Freigabe wieder alle vorgesehenen Pfade korrekt funktionieren.
    - Traffic und Jobs bleiben gesperrt, solange der korrekte Schema- und Anwendungszustand nicht vollständig bestätigt ist.

30. Was passiert, wenn die Engagement-Tabelle nach der Migration unerwartet leer ist?
    - Eine unerwartet leere Tabelle wird nicht stillschweigend als erfolgreicher Daten-Erhalt gewertet, sondern als sichtbare Abweichung gemeldet.
    - Der Verlust betrifft historische Landing-Engagement- und Soft-Flag-Daten, nicht die getrennten Sales-, Billing- oder Transaktionsdaten.
    - Wenn Tabellenstruktur, neuer Schreib-/Lesepfad und die übrigen sicherheitsrelevanten Prüfungen funktionieren, darf der User den Verlust der historischen Engagement-Daten ausdrücklich akzeptieren und auf Restore beziehungsweise Rollback verzichten.
    - Nach dieser dokumentierten Freigabe darf die Website mit der leeren, funktionsfähigen Tabelle wieder geöffnet werden; neue Engagement-Ereignisse befüllen sie erneut.
    - Analytics-, Device- und Fraud-Auswertungen dürfen vorübergehend weniger historische Grundlage haben. Das Retention Gate bleibt bei unzureichender Vergleichsgrundlage fail-closed.
    - Ohne ausdrückliche User-Freigabe bleibt die Abweichung ein Stop-Gate.

31. Wer implementiert, testet und führt die Vorher-/Nachher-Prüfungen aus?
    - Der Implementer baut die exakten Vergleiche für Zeilenzahl, kleinste/größte ID, `AUTO_INCREMENT`, Tabellenstruktur und Indizes in das Migrationsartefakt ein.
    - Der Implementer testet passende und abweichende Snapshots, fehlgeschlagene Prüfqueries, Lock-Verlust, das Stop-Gate und den ausschließlich manuell ausgelösten Rollback mit den repository-eigenen automatisierten Testmitteln.
    - Die zusätzliche echte Scratch-MySQL-/MariaDB-Generalprobe ist ausschließlich die vorgelagerte Planner-Prüfung aus Entscheidung 42 und kein Implementer-Arbeitspaket.
    - Die Implementer-Abschlussbesprechung trennt ausdrücklich zwischen implementierten und lokal validierten Mechanismen einerseits sowie noch offenen Production-Aktionen andererseits.
    - Sie enthält die vorgesehenen Production-Befehle und erwarteten bereinigten JSON-Ausgaben, meldet aber ausdrücklich, dass weder migrationsspezifischer Production-`check`/`apply` noch allgemeiner Production-`kiwi database status`, echte Vorher-/Nachher-Werte oder Production-Smokes ausgeführt wurden.
    - Der Deployment-Codex/Operator führt diese Mechanismen später nach separater Autorisierung gegen Production aus und dokumentiert die realen Werte.
    - Der User entscheidet bei einer Abweichung über Rollback, Restore oder das akzeptierte Weiterarbeiten mit einer leeren Engagement-Tabelle.

32. Wer veröffentlicht die neue globale Schema-Version?
    - Issue #96 erhält die dedizierte neue Zielversion `2026-07-23-1`, die höher als die aktuelle Version `2026-07-20-1` ist.
    - Das migrationsspezifische Artefakt behandelt Tabellen-Rename und Schema-Version als eine koordinierte Operation.
    - Es verwendet die zentrale Schema-Prüflogik des externen Database-Deployment-Systems und erfindet keinen vereinfachten zweiten Schema-Contract.
    - `apply` speichert `2026-07-23-1` erst, nachdem der atomare Rename, der Vorher-/Nachher-Vergleich und die vollständigen Schema-Postconditions erfolgreich waren.
    - Der anschließende normale read-only `kiwi database status` muss die veröffentlichte Version und den gesamten Schema-Contract mit `ready=true` bestätigen.
    - `rollback` benennt die Tabelle zuerst erfolgreich zurück, prüft den alten Zustand und stellt erst danach die zuvor erfasste Schema-Version wieder her.
    - Das allgemeine `kiwi database apply` wird nicht als zusätzlicher mutierender Versionsschritt ausgeführt.

33. Ist vor der tatsächlichen Ausführung des Renames zwingend eine zusätzliche Sicherung erforderlich?
    - Für den konkret geplanten Production-Lauf lädt der User vor der tatsächlichen Ausführung ein aktuelles Hostinger-Datenbank-Backup herunter.
    - Zeitpunkt beziehungsweise Bezeichnung der Hostinger-Sicherung und die erfolgreiche lokale Verfügbarkeit der heruntergeladenen Datei werden dokumentiert; Zugangsdaten und Dump-Inhalte werden nicht in Issue oder Logs kopiert.
    - Das Migrationsartefakt erstellt keinen zusätzlichen automatischen Datenbank-Dump.
    - Der Operator zeigt dem User zusätzlich den rein lesend erfassten aktuellen Tabellenzustand und die Vorher-Vergleichswerte.
    - Der Rename darf erst ausgeführt werden, wenn sowohl der Ausgangscheck als auch die heruntergeladene Hostinger-Sicherung bestätigt sind.
    - Trotz vorhandener Sicherung bleibt der bereits beschlossene Pfad gültig, einen Verlust ausschließlich historischer Engagement-Daten später ausdrücklich zu akzeptieren und auf einen Restore zu verzichten.

34. Welche internen Namen werden generisch und welche bleiben Premium-SMS-spezifisch?
    - Alle aktiven Bezeichnungen der gemeinsamen Datenquelle werden vollständig auf `landing_session_engagement...` umgestellt.
    - Dazu gehören Repository-Klasse und Datei, Plugin-/Runtime-Schlüssel, Variablen, Testklassen und Testbeschreibungen, Schema-Contract und Schema-Step, der Retention-Einstellungsschlüssel sowie Summary-, Retention-, Device- und View-Referenzen.
    - Für diese Datenquellen-Bezeichnungen gibt es keine parallelen alten und neuen Namen und keine dauerhaften Code-Aliase.
    - Der alte Tabellen-/Repository-Name darf nur im migrationsspezifischen `check`/`apply`/`rollback`, in den dazugehörigen Tests und in eindeutig historischen Hinweisen vorkommen.
    - Wirklich Premium-SMS-Fraud-spezifische Komponenten behalten ihre Fachsprache: `Kiwi_Premium_Sms_Landing_Engagement_Soft_Flag_Service`, `Kiwi_Premium_Sms_Fraud_Signal_Repository`, Premium-SMS-Fraud-Monitor/Shortcode, Premium-SMS-MO-Evaluator sowie Fraud-Konfigurationen und Schwellwerte.
    - Der Implementer weist über eine vollständige Suche nach, dass keine nicht erlaubte aktive Referenz auf den alten Datenquellen-Namen übrig bleibt.

35. Wie verhält sich das Migrationsartefakt bei Wiederholung oder widersprüchlichen Zuständen?
    - `check` ermittelt read-only den Zustand aus beiden Tabellennamen, beiden erwarteten Schema-Versionen und den erforderlichen Postconditions.
    - Nur alte Tabelle vorhanden und alte Version bestätigt: Zustand `pending`; `apply` ist zulässig.
    - Nur neue Tabelle vorhanden, neue Version `2026-07-23-1` bestätigt und Schema vollständig: Zustand `applied`; wiederholtes `apply` meldet Erfolg ohne Mutation.
    - Alte und neue Tabelle gleichzeitig vorhanden: Konflikt; keine automatische Auswahl, kein Rename und keine Versionsänderung.
    - Beide Tabellen fehlen: kein Rename möglich. Eine neue Installation verwendet ausschließlich das normale externe Datenbank-Setup, das die neue Tabelle direkt erstellt.
    - Tabellenname und Schema-Version widersprechen einander: unvollständiger Zustand; keine automatische Reparatur oder Versionsänderung.
    - Wiederholtes `rollback` meldet ohne Mutation Erfolg, wenn alte Tabelle, alte Version und alte Postconditions bereits vollständig wiederhergestellt sind.
    - Alle anderen Rollback-Zustände bleiben fail-closed und benötigen Diagnose.

36. Welche wiederhergestellten beziehungsweise älteren Datenbankstände darf das Issue-#96-Artefakt migrieren?
    - Das Artefakt arbeitet nur aus dem exakt bestätigten Vorgängerstand: alte Tabelle vorhanden, Schema-Version `2026-07-20-1` und erwartete Spalten/Indizes vollständig.
    - Eine neue Installation verwendet das normale externe Datenbank-Setup und erstellt direkt `wp_kiwi_landing_session_engagements`; das Rename-Artefakt ist dort nicht zuständig.
    - Eine wiederhergestellte Datenbank mit dem exakt erwarteten Vorgängerstand darf denselben migrationsspezifischen Rename durchlaufen.
    - Eine ältere, unbekannte oder in weiteren Punkten abweichende Datenbank bleibt in Maintenance. Das Artefakt führt keinen Rename aus und veröffentlicht nicht `2026-07-23-1`.
    - Der abweichende Restore muss zuerst über die dafür passenden geprüften Releases beziehungsweise migrationsspezifischen Schritte auf den bestätigten Vorgängerstand gebracht werden.
    - Das Artefakt überspringt keine älteren Migrationen und erklärt keinen unbekannten Gesamtzustand allein aufgrund eines erfolgreichen Tabellen-Renames für aktuell.

37. Welche dauerhafte Dokumentation muss der Implementer im Rahmen von Issue #96 aktualisieren?
    - Die Dokumentationsaktualisierung ist verpflichtender Bestandteil der Implementierung und darf nicht nur im Planner Report oder in dieser temporären Plan-Datei verbleiben.
    - `docs/operations/database-migrations.md` dokumentiert Maintenance-Voraussetzung, Hostinger-Backup, migrationsspezifische `check`-/`apply`-/`rollback`-Bedienung, exakten Vorgängerstand, Stop-Bedingungen und kontrollierte Wiederfreigabe.
    - `docs/operations/landing-funnel-analytics.md` wird auf `wp_kiwi_landing_session_engagements`, die gemeinsame generische Datenquelle und die zentrale Tabellen-Namensquelle umgestellt.
    - `docs/operations/premium-sms-fraud-monitoring.md` erklärt, dass der bestehende Premium-SMS-Soft-Flag-Service den neutralen Evaluator-Vertrag implementiert und vom weiterhin getrennten Fraud-Signal-Repository zu unterscheiden ist.
    - `docs/architecture/capability-matrix.md` wird dort angepasst, wo Tabellenname, gemeinsame Engagement-Datenquelle oder Evaluator-Grenze als Capability-Zuordnung beschrieben sind.
    - `CHANGELOG.md` hält nach der tatsächlichen Implementierung die umgesetzte Architektur-, Schema- und Betriebsänderung fest; betroffene Einträge in `TODO.md` werden aktualisiert oder entfernt, aber nicht als bereits umgesetzt beschrieben, bevor die Implementierung vorliegt.
    - Es entsteht kein neues Sammel- oder Architekturdokument, solange die Informationen fokussiert in die vorhandenen Dokumente passen. Bestehende `INDEX.md`-Dateien müssen nur geändert werden, falls sich navigierbare Dokumentziele tatsächlich ändern.

38. Wie wird das einmalige Issue-#96-Migrationsartefakt vom dauerhaften allgemeinen Datenbank-Werkzeug getrennt?
    - Die bestehenden Befehle `kiwi database status` und `kiwi database apply` bleiben der wiederholt sicher ausführbare, allgemeine Weg zum kanonischen Datenbank-Sollzustand für aktuelle und zukünftige normale Schemaänderungen.
    - Der allgemeine `apply` führt niemals den historischen Tabellen-Rename, eine Datenübernahme oder den speziellen Rückweg aus. Erkennt er die alte Tabelle, stoppt er weiterhin mit einem migrationspflichtigen Legacy-Zustand.
    - Das versionierte Issue-#96-Artefakt liegt getrennt unter `tools/database/migrations/` und stellt ausdrücklich die migrationsspezifischen Befehle `kiwi database migration landing-session-engagements check`, `apply` und `rollback` bereit.
    - Nur der ausdrücklich aufgerufene migrationsspezifische `apply` darf den kontrollierten Rename ausführen; nur dessen `rollback` darf innerhalb der festgelegten Rollback-Grenze den kontrollierten Rückweg ausführen.
    - Der migrationsspezifische Einstieg verwendet dieselbe sichere WP-CLI-Lebenszyklusgrenze wie der allgemeine Runner: Kiwi Backend ist geladen, Ausführung nach `plugins_loaded`, vor `init`, fail-closed bei fehlender Voraussetzung.
    - Nach abgeschlossenem Rollout bleibt der allgemeine Datenbank-Runner der dauerhafte Betriebsweg. Das historische Issue-#96-Artefakt wird nicht für neue Installationen oder andere Migrationen zweckentfremdet; spätere Sondermigrationen erhalten eigene versionierte Artefakte unter demselben migrationsspezifischen Namensraum.

39. Wie werden Störungen während des Production-Umbaus als Operational Event behandelt?
    - Eine planmäßig erfolgreiche Migration erzeugt keinen Operational Event; der erfolgreiche Ablauf wird als Deployment-Nachweis in Issue #96 dokumentiert.
    - Scheitern migrationsspezifischer `check`, `apply`, eine Nachprüfung oder ein notwendiger `rollback`, bleibt Maintenance aktiv und der Operator bewahrt zuerst das ursprüngliche bereinigte JSON-Ergebnis und den Exit-Code.
    - Das Migrationsartefakt schreibt keinen Operational Event automatisch. Der Operator erfasst die Störung anschließend in einem gesonderten, ausdrücklich autorisierten WP-CLI-Schritt über `Kiwi_Operational_Event_Service::record_failure()`.
    - Der Event verwendet `area=database`, `severity=critical`, `event_type=schema_migration_failed`, eine stabile Correlation/Referenz wie `schema_migration:landing_session_engagements_rename:v1`, eine versuchsspezifische Idempotency-ID und einen bereinigten Kontext mit Phase und Fehlercode.
    - Zugangsdaten, vollständige SQL-Anweisungen, rohe MSISDNs, Subscriber-Identifier oder ungekürzte sensible Daten dürfen weder im Operational Event noch im GitHub-Nachweis erscheinen.
    - Ein Fehler beim Schreiben des Operational Events darf den ursprünglichen Datenbankfehler nicht ersetzen oder als erfolgreicher Migrationszustand behandeln; beide Ergebnisse werden getrennt bewahrt.
    - Existiert ein passender offener Fehler-Event, darf `record_recovery()` genau einmal erst nach erfolgreicher Datenbankoperation beziehungsweise verifiziertem Rollback, grünem allgemeinen `status` und den relevanten Production-Smokes ausgeführt werden.

40. Darf die Website wieder geöffnet werden, wenn nach vollständig grüner Technik ausschließlich die Operational-Event-Aufzeichnung fehlschlägt?
    - Ja, aber nur über einen kontrollierten, ausdrücklich vom User freigegebenen Ausnahmeweg.
    - Dieser Weg ist nur zulässig, wenn Datenbankoperation beziehungsweise verifizierter Rollback, allgemeiner `kiwi database status`, Tabellen-/Daten-Nachprüfungen und alle relevanten Production-Smokes vollständig grün sind.
    - Ein ungeklärter Datenbank-, Daten-, Schema-, Schreib-/Lesepfad- oder Anwendungszustand kann niemals als reiner Protokollierungsfehler behandelt werden und hält die Website weiter in Maintenance.
    - Der Operator bewahrt den bereinigten Fehler des fehlgeschlagenen `record_failure()`- oder `record_recovery()`-Aufrufs, dokumentiert im Issue, welcher Event fehlt beziehungsweise offen blieb, und verwendet später dieselbe Correlation sowie Idempotency-Logik für einen sicheren erneuten Versuch.
    - Der fehlende Eintrag darf weder als erfolgreich geschrieben dargestellt noch durch einen erfundenen Event ersetzt werden.
    - Nach dieser Dokumentation darf der User die Wiederfreigabe trotz des isolierten Protokollierungsfehlers ausdrücklich genehmigen; die Nachholung bleibt sichtbare offene Folgearbeit.
    - Diese Ausnahme erzeugt keinen Routine-Erfolgs-Event: Wenn die Migration vom ersten Versuch an vollständig grün war, bleibt es weiterhin bei der normalen Deployment-Dokumentation ohne Operational Event.

41. Wie wird ein nach der Wiederfreigabe noch fehlender Operational-Event- oder Recovery-Eintrag nachgeholt?
    - Dafür wird kein zusätzliches GitHub Issue angelegt. Issue #96 bleibt die Nachweis- und Arbeitsstelle und darf bis zur erfolgreichen Nachholung nicht abgeschlossen werden.
    - Der Operator beziehungsweise Deployment-Codex führt den fehlenden Eintrag nachträglich in einem ausdrücklich autorisierten WP-CLI-Schritt über den bestehenden `Kiwi_Operational_Event_Service` aus; ein direktes SQL-`INSERT` ist ausgeschlossen.
    - Der Nachtrag verwendet die bereits dokumentierte Correlation, den ursprünglichen Ereigniszeitpunkt, die bereinigte Fehlerphase und dieselbe Idempotency-Logik, damit der historische Ablauf korrekt bleibt und kein Duplikat entsteht.
    - Der Operator verifiziert das tatsächliche Ergebnis des Service-Aufrufs und dokumentiert Event-/Recovery-Nachweis oder erneuten bereinigten Fehler in Issue #96.
    - Ein weiterer Fehlschlag wird im selben Issue weiterverfolgt; er ändert den bereits als grün bewiesenen Datenbankzustand nicht, bleibt aber ein offener Abschlussblocker für Issue #96.
    - Erst wenn kein erforderlicher Operational-Event- oder Recovery-Eintrag mehr fehlt, entscheidet der User über den Abschluss von Issue #96.

42. Wie wird der festgelegte Migrationsplan vor dem Planner Report praktisch geprüft?
    - Nach Abschluss aller Planungsfragen und vor dem GitHub Planner Report baut der Planner mit der `prototype`-Skill einen kleinen, klar als wegwerfbar markierten Datenbank-Prototyp.
    - Der Prototyp ist eine vorgelagerte Planner-Prüfung und ausdrücklich kein Arbeitspaket für den späteren Implementer. Der Implementer soll ihn weder erneut bauen noch als Production-Code übernehmen.
    - Er verwendet ausschließlich eine von Production getrennte Scratch-MySQL-/MariaDB-Datenbank, den erwarteten alten Tabellenaufbau und künstliche Beispieldaten; Production-Zugänge, Backups und echte Kunden- oder Subscriber-Daten sind ausgeschlossen.
    - Geprüft werden mindestens der reale Rename, Erhalt von Zeilen, IDs, Indizes und `AUTO_INCREMENT`, Versionsübergang, wiederholtes `check`/`apply`, kontrolliertes `rollback` sowie die festgelegten pending/applied/conflict/missing/Versions-Widerspruch-Zustände.
    - Widerspricht das Ergebnis einer bisherigen Design-Entscheidung, wird der Planner Report noch nicht geschrieben. Stattdessen wird die Abweichung in dieser Datei dokumentiert und mit dem User geklärt.
    - Nur die belastbaren Erkenntnisse und relevanten Ausführungsformen werden in dieser Plan-Datei und später im Abschnitt `Prototype / Evidence Details` des selbstständigen Planner Reports festgehalten. Der wegwerfbare Prototyp selbst ist kein dauerhaftes Repository-Artefakt.

43. Wie heißen Prüfung und Ergebniszustand des migrationsspezifischen Artefakts und wie verhalten sich dessen Exit-Codes?
    - Der neue read-only Prüf-Befehl heißt `kiwi database migration landing-session-engagements check`; das JSON-Ergebnis verwendet das Feld `state`.
    - Die vorhandenen allgemeinen Befehle `kiwi database status` und `kiwi database apply` aus Issue #103 bleiben unverändert.
    - Ein vollständig bestätigter Vorgängerzustand liefert `success=true`, `state=pending` und Exit-Code `0`: Der Zustand ist sicher erkannt und der migrationsspezifische `apply` ist aus Datenbanksicht zulässig.
    - Ein vollständig bestätigter Zielzustand liefert `success=true`, `state=applied` und Exit-Code `0`: Die Migration ist bereits abgeschlossen und ein wiederholtes `apply` bleibt ein No-op.
    - `conflict`, `missing`, Versions-/Schema-Widerspruch oder eine fehlgeschlagene Prüfquery liefern `success=false`, einen eindeutigen `state` beziehungsweise Fehlercode und einen Exit-Code ungleich `0`; `apply` und `rollback` bleiben fail-closed.
    - Die Ausgabe darf Datenbank-Eignung beziehungsweise nächste sichere technische Aktion beschreiben, erteilt aber niemals die externe User-Freigabe für `apply`, `rollback` oder das Öffnen der Website.

44. Welche GitHub-Project-Risikoeinstufung erhält Issue #96?
    - `Risk = High`.
    - Ausschlaggebend sind die Production-Datenbankmigration, der Hard Cutover einer vorhandenen Datentabelle, Schema-Versionswechsel, Maintenance-/Schreibstopp, kontrollierter Rollback beziehungsweise Restore sowie die abhängigen Analytics-, Retention-, Device- und Fraud-Pfade.
    - `High` bewertet die mögliche Auswirkung eines Fehlers und begründet die festgelegten Schutzmaßnahmen; es bedeutet nicht, dass der freigegebene Plan als unsicher gilt.
    - Beim späteren Plan-Record darf das Project-Feld `Risk` auf `High` gesetzt werden. Der GitHub Project Status bleibt unverändert.

45. Welche GitHub-Project-Größeneinstufung erhält Issue #96?
    - `Size = L`.
    - Maßgeblich ist der vollständige Umfang aus generischem Repository-Refactor, zentraler Tabellen-Namensquelle, neutralem Evaluator-Vertrag, abhängigen Anwendungspfaden, Schema-Contract und -Version, eigenständigem Migrationsartefakt, Zustands-/Regressionstests, dauerhafter Dokumentation und mehrstufigem Production-Handoff.
    - Die Einstufung bewertet den gesamten sicheren Änderungs- und Validierungsumfang und nicht nur den kurzen physischen `RENAME TABLE`-Befehl.
    - Beim späteren Plan-Record darf das Project-Feld `Size` auf `L` gesetzt werden. Der GitHub Project Status bleibt unverändert.

46. Wie wird der veraltete Issue-#96-Text beim finalen GitHub-Handoff behandelt?
    - Nach abgeschlossener Planungsrunde und erfolgreichem Planner-Prototyp wird der Issue-Text vor dem Planner Report minimal auf den bestätigten aktuellen Plan gebracht.
    - Das veraltete Akzeptanzkriterium eines schema-versionierten WordPress-Runtime-Migrationspfads wird durch den externen, Maintenance-gestützten Hard Cutover mit dem migrationsspezifischen `check`/`apply`/`rollback`-Artefakt ersetzt; Issue #103 wird als Architekturgrundlage genannt.
    - Die Issue-Beschreibung hält die zentralen Ziel- und Akzeptanzkriterien aktuell, dupliziert aber nicht den vollständigen Implementierungs-, Test-, Rollback- und Operator-Plan. Der einzelne Codex Planner Report bleibt die verbindliche Detailquelle für den Implementer.
    - Die statische Zeile mit eingebetteten Projektfeldwerten wird aus dem Issue-Text entfernt, damit sie nicht erneut von den echten GitHub-Project-Feldern abweichen kann.
    - Die echten Project-Felder werden nach Live-Abgleich bei Bedarf auf `Risk=High` und `Size=L` gesetzt. `Type=Refactor`, `Codex Mode=Critical` und `Priority=High` bleiben unverändert, sofern der Live-Abgleich diese bestehenden Werte bestätigt.
    - Der GitHub Project Status bleibt unverändert. Issue-Text, Planner Report, Labels sowie `Risk`/`Size` werden erst nach erfolgreichem Prototyp und finalem Reifecheck geändert.

# Planner-Prototyp / Evidence (2026-07-24)

- Ziel: Die festgelegte migrationsspezifische Zustandsmaschine und die Daten-Erhaltung des echten `RENAME TABLE` vor dem Planner Report gegen eine reale MySQL-kompatible Datenbank prüfen.
- Umgebung: offizielles portables MariaDB Community Server `12.3.2` Windows-ZIP, SHA-256 `67347c129eb9c5923d002ea34fbfa27c60eb95d36dd73b85af2651cdeceecac5`; keine systemweite Installation, kein Windows-Dienst und keine Production-Verbindung.
- Datenbasis: aktuelles vollständiges Engagement-Tabellenschema aus `includes/repositories/class-premium-sms-landing-engagement-repository.php` und `tools/database/schema-contract.php`; ausschließlich drei künstliche Datensätze.
- Ausgangsmessung: `state=pending`, `row_count=3`, `min_id=7`, `max_id=41`, `AUTO_INCREMENT=73`, vollständige erwartete Spalten- und Indexmenge, alte Version `2026-07-20-1`.
- Reales `apply`: datenbankweiter Lock mit demselben Lock-Namensraum wie der allgemeine Runner wurde erworben und freigegeben; `RENAME TABLE wp_kiwi_premium_sms_landing_engagements TO wp_kiwi_landing_session_engagements` und Versionswechsel auf `2026-07-23-1` führten zu `state=applied`.
- Nach `apply` blieben `row_count=3`, `min_id=7`, `max_id=41`, `AUTO_INCREMENT=73`, Spalten und Indizes exakt erhalten; die alte Tabelle fehlte und die neue Tabelle war vollständig vorhanden.
- Wiederholtes `apply` war ein erfolgreicher No-op ohne Mutation.
- Reales `rollback` benannte die Tabelle zurück, stellte `2026-07-20-1` wieder her und erhielt denselben Daten-/Schema-Snapshot; wiederholtes `rollback` war ein erfolgreicher No-op.
- Die Zustände beide Tabellen vorhanden, beide Tabellen fehlend, Versions-Widerspruch und absichtlich entfernter Index wurden jeweils korrekt als `conflict`, `missing`, `version_mismatch` beziehungsweise `schema_mismatch` erkannt und stoppten fail-closed.
- Ergebnis: alle neun Szenarien bestanden. Die Scratch-Datenbank wurde nach dem Lauf gelöscht.
- Grenze der Evidence: Der Prototyp bestätigt MariaDB-Verhalten und die geplante Zustandslogik, nicht die noch zu implementierende PHP-/WP-CLI-Integration, Production-Berechtigungen, Maintenance-Wirksamkeit, Hostinger-Backup, Production-Version oder Production-Smokes. Diese Nachweise bleiben in der Migrations-Todo-Liste bei den vorgesehenen Rollen.
- Der erste Harness-Lauf stoppte vor dem Rename an einem falsch escapeden PowerShell-Tabellennamen. Nach Korrektur der ausschließlich wegwerfbaren Hülle bestand der vollständige Lauf; daraus ergibt sich keine Änderung am Implementierungsplan.

# GitHub-Handoff / Evidence (2026-07-24)

- Der Text von Issue #96 wurde auf die externe Issue-#103-Architektur und den bestätigten Hard-Cutover-Plan aktualisiert; die statische Projektfeld-Zeile wurde entfernt.
- Der vollständige Planner Report wurde unter `https://github.com/mpetrovic-hub/backend/issues/96#issuecomment-5073397024` gespeichert.
- Die gespeicherten Issue- und Report-Inhalte wurden bytegenau gegen die geprüften lokalen Vorlagen verifiziert.
- Die anschließende Gesamtprüfung ergab genau einen Issue-Kommentar und genau einen gültigen aktuellen Codex Planner Report; es gab keine älteren Planner Reports zu entfernen.
- Die Labels `0 - codex-candidate`, `1b - codex-planned` und `2 - codex-implement-ready` sind gesetzt.
- Die bestätigten Project-Felder lauten `Type=Refactor`, `Codex Mode=Critical`, `Priority=High`, `Risk=High` und `Size=L`.
- Der GitHub Project Status blieb unverändert auf `Ready for Codex`.

# Migrations-Todo-Liste

Diese Liste wird während der restlichen Planungsrunde laufend präzisiert. Ihre Reihenfolge ist verbindlich: Ein Schritt beginnt erst, wenn der vorherige erfolgreich abgeschlossen und dokumentiert wurde. `[User]` kennzeichnet eine notwendige Entscheidung oder Freigabe durch den User.

## A. Planung und Implementierung

- [x] 1. `[Planner]` Alle verbleibenden Design-, Prüf-, Rollback- und Freigabeentscheidungen in dieser Datei abschließen.
- [x] 2. `[Planner]` Mit der `prototype`-Skill den wegwerfbaren, vollständig von Production getrennten Scratch-MySQL-/MariaDB-Prototyp bauen und die festgelegten Rename-, Zustands-, Wiederholungs- und Rollback-Fälle mit künstlichen Daten ausführen.
- [x] 3. `[Planner]` Prototyp-Ergebnisse in dieser Datei festhalten; bei Widerspruch zum Plan stoppen und den User befragen, andernfalls nur die belastbare Evidence für den späteren Planner Report übernehmen und den Prototyp nicht zum Implementer-Arbeitspaket machen.
- [x] 4. `[Planner]` Nach erfolgreichem Prototyp den Issue-#96-Text minimal auf die externe Issue-#103-Architektur aktualisieren und die eingebettete Projektfeld-Zeile entfernen; anschließend den vollständigen, in sich geschlossenen Codex Planner Report als einzigen aktuellen Planner Report schreiben und verifizieren, die Implementation-ready-Labels gemäß Plan-Record-Workflow anwenden sowie die echten Project-Felder bei Bedarf auf `Risk=High` und `Size=L` setzen; `Type`, `Codex Mode`, `Priority` und den GitHub Project Status unverändert lassen.
- [ ] 5. `[User]` Planner Report und vorgesehenen Hard-Cutover-Ablauf prüfen beziehungsweise zur Implementierung freigeben.
- [ ] 6. `[Implementer]` Anwendungscode, alle aktiven Datenquellen-Bezeichnungen, generische Tabellen-Namensquelle, Evaluator-Vertrag, Schema-Contract, Tests und das getrennte versionierte Issue-#96-Artefakt unter `tools/database/migrations/` mit `kiwi database migration landing-session-engagements check|apply|rollback` umsetzen; außerdem `docs/operations/database-migrations.md`, `docs/operations/landing-funnel-analytics.md`, `docs/operations/premium-sms-fraud-monitoring.md`, die betroffenen Stellen in `docs/architecture/capability-matrix.md`, `CHANGELOG.md` und gegebenenfalls `TODO.md` fokussiert aktualisieren; echte Premium-SMS-Fraud-Komponenten fachlich unverändert lassen.
- [ ] 7. `[Implementer]` Automatisierte Tests einschließlich der vollständigen pending/applied/conflict/missing/Versions-Widerspruch/erneutes-Apply/erneutes-Rollback-Zustandsmatrix ausführen; zusätzlich beweisen, dass der allgemeine `kiwi database apply` den Rename und den Rollback niemals ausführt und beim alten Tabellennamen fail-closed stoppt; vollständige Suche nach nicht erlaubten alten Datenquellen-Namen, Prüfung der aktualisierten Dokumentation auf konsistente Terminologie und Links, PHP-Lint und `git diff --check` ausführen; ausdrücklich keine Production-Datenbank verändern.
- [ ] 8. `[Implementer]` In der Abschlussbesprechung implementierte Prüfungen, lokale Testergebnisse, aktualisierte dauerhafte Dokumentation, getrennte allgemeine und migrationsspezifische Production-Befehle, erwartete JSON-Ausgaben sowie die manuellen bereinigten Operational-Event-/Recovery-Schritte einschließlich des isolierten Protokollierungsfehler-Ausnahmewegs aufführen; Production-Status, Production-Apply, echte Messwerte, Production-Smokes, Operational-Event-Ausführungen und User-Entscheidungen ausdrücklich als offen kennzeichnen.
- [ ] 9. `[User/Reviewer]` Implementierung und Deployment-/Rollback-Anleitung prüfen und den exakten Release beziehungsweise Commit freigeben.

## B. Vorbereitung des Production-Fensters

- [ ] 10. `[User]` Production-Deployment und das konkrete Maintenance-Fenster ausdrücklich autorisieren.
- [ ] 11. `[Deployment-Codex/Operator]` Zielumgebung, freigegebenen Commit, vorherigen Release für Code-Rollback und benötigte Zugänge bestätigen.
- [ ] 12. `[User]` Vor der tatsächlichen Ausführung ein aktuelles Hostinger-Datenbank-Backup herunterladen und Zeitpunkt/Bezeichnung sowie die lokale Verfügbarkeit der Datei bestätigen.
- [ ] 13. `[Deployment-Codex/Operator]` Vorhandenen Zustand read-only erfassen, soweit dies mit dem freigegebenen Artefakt ohne Code-Aktivierung möglich ist.
- [ ] 14. `[User/Operator]` Maintenance aktivieren und öffentlichen Website-, REST-, AJAX- und Admin-Traffic sperren.
- [ ] 15. `[Operator]` WP-Cron, externe Scheduler, Worker und andere schreibende WP-CLI-Prozesse pausieren.
- [ ] 16. `[Operator]` Bestätigen, dass keine bewusst gestarteten Schreibprozesse mehr gegen die alte Tabelle laufen; kurzfristige bereits laufende Requests dürfen kontrolliert fehlschlagen.
- [ ] 17. `[Operator]` Den exakt freigegebenen Release mit neuem Code und migrationsspezifischem Artefakt bereitstellen, ohne die Website oder Jobs freizugeben.

## C. Externer Hard Cutover

- [ ] 18. `[Operator]` Migrationsartefakt im read-only `check` ausführen und `success=true`, `state=pending` sowie Exit-Code `0` verlangen: alte Tabelle vorhanden, neue Tabelle nicht vorhanden, Schema-Version `2026-07-20-1`, erwartetes Schema und ausführbarer Ausgangszustand bestätigt.
- [ ] 19. `[Operator]` Vorher-Snapshot, aktuelle Schema-Version und alle für den späteren Vergleich erforderlichen Werte dokumentieren.
- [ ] 20. `[User/Operator]` Vor der tatsächlichen Ausführung bestätigen, dass Ausgangscheck und heruntergeladene Hostinger-Sicherung dokumentiert und verfügbar sind.
- [ ] 21. `[User/Operator]` Die tatsächliche Ausführung des Renames (`apply`) ausdrücklich freigeben und ausführen.
- [ ] 22. `[Operator]` Bestätigen, dass der atomare `RENAME TABLE` und die migrationsspezifischen Nachprüfungen erfolgreich waren und erst danach `2026-07-23-1` gespeichert wurde.
- [ ] 23. `[Operator]` Allgemeinen read-only `kiwi database status` ausführen und `ready=true`, `installed_version=2026-07-23-1` sowie driftfreien Zustand verlangen.
- [ ] 24. `[Operator]` Landing Funnel Daily Summary, TK-zone Summary, Device Model Harvest, Retention Gate und die relevanten Premium-SMS-Fraud-/Landing-Engagement-Pfade kontrolliert prüfen.

## D. Stop, Rollback oder Freigabe

- [ ] 25. `[Stop-Gate/Operator]` Bei einem nicht vollständig beweisbaren Erfolg: Maintenance beibehalten, Traffic und Jobs gesperrt lassen, ursprüngliches bereinigtes JSON und Exit-Code bewahren, den tatsächlichen Datenbankzustand diagnostizieren und den Fehler anschließend separat als bereinigten `schema_migration_failed` Operational Event erfassen.
- [ ] 26. `[User]` Falls ausschließlich die historische Engagement-Tabelle unerwartet leer ist, ausdrücklich zwischen Restore/Rollback und dem dokumentierten Akzeptieren des historischen Datenverlusts entscheiden.
- [ ] 27. `[Operator]` Bei akzeptiertem Datenverlust vor der Freigabe bestätigen, dass Tabellenstruktur, neuer Schreib-/Lesepfad und sicherheitsrelevante Smokes funktionieren und das Retention Gate bei unzureichender Grundlage fail-closed bleibt.
- [ ] 28. `[User/Operator]` `rollback` nur nach ausdrücklicher Entscheidung, bestätigten Rollback-Postconditions und solange noch keine neuen Schreibzugriffe freigegeben wurden ausführen.
- [ ] 29. `[Operator]` Nach einem Rollback den vorherigen kompatiblen Code-Release und die vorherige Schema-Version wiederherstellen, alten Tabellennamen und Datenzustand verifizieren und erst danach über eine Freigabe entscheiden.
- [ ] 30. `[User/Operator]` Bei vollständig grüner Migration oder ausdrücklich akzeptiertem Engagement-Datenverlust die Wiederfreigabe bestätigen; falls ein zugehöriger Fehler-Event offen ist, zuvor nach grünem allgemeinen `status` und erfolgreichen Production-Smokes genau einmal den passenden Recovery-Eintrag erfassen. Scheitert nachweislich nur diese Operational-Event-/Recovery-Aufzeichnung, den bereinigten Fehler und die offene Nachholung dokumentieren und die Website ausschließlich nach ausdrücklicher User-Freigabe trotzdem wieder öffnen.
- [ ] 31. `[Operator]` Zuerst kontrollierte Jobs/Smokes, anschließend öffentlichen Traffic wieder freigeben und kurzfristig auf Fehler beobachten.

## E. Abschluss und Folgearbeit

- [ ] 32. `[Operator/Deployment-Codex]` Falls nach der Wiederfreigabe noch ein erforderlicher Operational-Event- oder Recovery-Eintrag fehlt, ihn ohne direktes SQL über `Kiwi_Operational_Event_Service` mit ursprünglichem Ereigniszeitpunkt, bestehender Correlation und derselben Idempotency-Logik nachtragen, Ergebnis verifizieren und in Issue #96 dokumentieren; bis zum Erfolg bleibt Issue #96 offen.
- [ ] 33. `[Operator]` Release, Hostinger-Sicherung, Vorher-/Nachher-Status, Apply-Ergebnis, Smokes, akzeptierten Datenverlust oder eventuellen Rollback, Operational-Event-/Recovery-Ergebnisse und verbleibende Risiken in Issue #96 dokumentieren.
- [ ] 34. `[Planner/User]` Issue #72 nach dem erfolgreichen Rename in Titel, Beschreibung und Akzeptanzkriterien auf `wp_kiwi_landing_session_engagements` umstellen.
- [ ] 35. `[User]` Erst nach Prüfung aller Nachweise und nachdem kein erforderlicher Operational-Event- oder Recovery-Eintrag mehr fehlt entscheiden, ob Issue #96 abgeschlossen werden kann.
