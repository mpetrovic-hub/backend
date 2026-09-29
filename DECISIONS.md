# Entscheidungen und ihre Gründe

Dieses Protokoll bewahrt wichtige, vom Nutzer freigegebene Abwägungen für kiwi-backend. Es erklärt, warum eine Lösung gewählt wurde und unter welchen Voraussetzungen die Begründung übertragbar ist. Es ersetzt weder den aktuellen Planner-Report noch Betriebsanweisungen oder eine Produktionsfreigabe.

Erstfassung: 21.09.2026, aus der gemeinsamen Planung von #122/#126 und der anschließenden Prozessdiskussion. „Gültig“ bedeutet hier **als Planungsentscheidung freigegeben**, nicht bereits implementiert oder produktiv geprüft. Die Quellenlinks verweisen auf die zugehörigen Reports; deren späteren Umsetzungsstand bei Bedarf aktuell prüfen.

## Verwendung und Pflege

- Bei Planung passende Einträge lesen, nicht pauschal die ganze Historie.
- Bereits abgedeckte Entscheidungen nicht erneut abfragen. Bei einem nur ähnlichen Fall die Begründung für eine Empfehlung nutzen, nicht als automatische Zustimmung.
- Aktuelle ausdrückliche Entscheidungen haben Vorrang. Widersprüche und veränderte Voraussetzungen benennen.
- Eine ersetzte Entscheidung als ersetzt kennzeichnen und auf ihren Nachfolger verweisen; nicht zwei widersprechende Regeln als gültig stehen lassen.
- Nur folgenreiche Abwägungen aufnehmen. Umsetzungsschritte, vollständige Tests und Deployment-Checklisten bleiben im Planner-Report. Kritische Vorgaben müssen dort selbst enthalten sein.

## D-001 — User-Flow und Sales vor perfekter abgeleiteter Statistik

**Bezug:** projektweite Priorität, präzisiert am 21.09.2026. **Status:** gültig.

**Entscheidung:** Zuverlässigkeit und Performance des mVAS-User-Flows sowie die Möglichkeit, einen Sale abzuschließen, haben Vorrang vor einer unverhältnismäßigen Perfektionierung abgeleiteter Statistik.

**Warum:** Statistik dient Auswertung und Marketing. Zusätzliche Technik soll den Kundenablauf nicht behindern oder das Projekt für geringe Zusatzgewinne dauerhaft verkomplizieren. Ziel bleiben saubere, langfristig tragfähige Lösungen; bewusst fragile Schnelllösungen sind damit nicht gewünscht.

**Akzeptierte Folge und Grenze:** Begrenzte historische Statistikabweichungen können im konkreten Fall zurückgestellt werden. Die beispielhaft genannten 0,1 % sind keine allgemeine Fehlertoleranz. Keine Erlaubnis zum Verlust von Sales, Abrechnungsdaten oder anderen geschäftlichen Originaldaten; auch neue Anforderungen dürfen nicht wissentlich falsch umgesetzt werden.

**Neu bewerten, wenn:** eine Abweichung nachweislich wichtige Geschäftsentscheidungen oder den Kundenablauf beeinträchtigt. Nutzen und Aufwand dann konkret abwägen, statt allein mit hypothetischen Risiken zu argumentieren.

**Quelle:** ausdrückliche Nutzerpriorität in der Planungsdiskussion vom 21.09.2026.

## D-002 — Assignment/Event und Summary hier nicht gemeinsam neu absichern

**Bezug:** #122/#126. **Status:** gültig.

**Entscheidung:** Die geerbte Lücke zwischen gespeichertem Assignment/Event und einer anschließend fehlgeschlagenen Summary-Aktualisierung wird in diesem Vorhaben nicht behoben.

**Warum:** Assignment und Ereignis bilden die geschäftliche Grundlage; die Summary ist eine daraus abgeleitete Statistik. Die Generationserweiterung soll nicht zu einem Umbau mit Transaktionen, Nachholmechanismen oder zusätzlichen Protokolltabellen werden. Insbesondere soll ein Statistikproblem nicht ohne eigene fachliche Entscheidung zur Rücknahme eines geschäftlichen Vorgangs führen.

**Akzeptierte Folge und Grenze:** Einzelne Summary-Zählungen können weiterhin fehlen, und ein wiederholtes Ereignis repariert das nicht zwingend. Das ist kein generelles Verbot von Transaktionen. Ebenso wenig beweist es, dass historische Produktionsabweichungen genau diese Ursache hatten. Die verlangte korrekte Zuordnung zur Generation bleibt Pflicht.

**Neu bewerten, wenn:** ein eigener Auftrag die Konsistenz zwischen Originaldaten und Statistik behandelt oder belastbare neue Auswirkungen vorliegen. Historische Ursachenanalyse und Reparatur sind Folgearbeit, kein stiller Zusatz zu #122/#126.

**Quellen:** [Plan #122](https://github.com/mpetrovic-hub/backend/issues/122#issuecomment-5541816114), [Plan #126](https://github.com/mpetrovic-hub/backend/issues/126#issuecomment-5603119910).

## D-003 — Französische Verteilung an der NTH-Integration konfigurieren

**Bezug:** #126. **Status:** gültig.

**Entscheidung:** Die acht französischen Varianten, ihre Kategorien und Gewichte sowie `fr_sms_v2` stehen in `includes/providers/nth/config/fr-one-off-sms-body-variants.php`. Der allgemeine Varianten-Service verarbeitet die übergebene Verteilung.

**Warum:** Diese konkrete Auswahl gehört zu NTH–Frankreich–One-off. Gemeinsame Funktionen bleiben wiederverwendbar, ohne französische Fachregeln anderen Ländern oder Abläufen aufzuzwingen. Eine separate Datei neben dem Adapter trennt Konfiguration von dessen eigentlicher Verarbeitung.

**Akzeptierte Folge und Grenze:** Nicht jeder SMS-Ablauf benötigt Varianten. Der besprochene griechische Ablauf mit fester `OK`-Antwort erhält deshalb keine künstliche Verteilung. Die geringe praktische Wahrscheinlichkeit eines zweiten Aggregators für denselben Service im selben Land rechtfertigt hier keine zusätzliche Abstraktion.

**Neu bewerten, wenn:** tatsächlich weitere Integrationen dieselbe fachliche Verteilung gemeinsam benötigen; nicht allein wegen einer theoretischen Erweiterungsmöglichkeit.

**Quelle:** [Plan #126](https://github.com/mpetrovic-hub/backend/issues/126#issuecomment-5603119910).

## D-004 — Einmalige Migration und dauerhafte Zielprüfung trennen

**Bezug:** #122. **Status:** gültig.

**Entscheidung:** Die einmalige SMS-Umstellung erhält einen eigenen Migrationsbaustein. Die Prüfung des fertigen Datenbankaufbaus bleibt im allgemeinen Deployment-Prozess.

**Warum:** Das Wissen, wie alte Datenbankstrukturen in neue überführt werden, wird nur für diesen Übergang benötigt. Die Kontrolle, ob der aktive Code einen passenden Aufbau vorfindet, wird dagegen dauerhaft gebraucht. Das Herauslösen der Migration darf diese Sicherheitsprüfung nicht entfernen.

**Konkret vereinbart:** `tools/database/migrations/sms-body-variant-allocation-version.php` und `tools/database/migrations/class-sms-body-variant-allocation-version-migration-service.php`. Der bestehende Deployment-Prozess und die bestehende Migrationsdokumentation werden genutzt. Keine einmaligen Tabellenänderungen aus dem Produktions-User-Flow heraus.

**Akzeptierte Folge und Grenze:** Zwei Verantwortlichkeiten müssen sauber zusammenspielen; daraus folgt kein Auftrag zum allgemeinen Umbau des Deployment-Systems.

**Neu bewerten, wenn:** ein späterer Übergang andere Anforderungen hat. Die Trennung von Übergang und dauerhaftem Zielzustand bleibt dabei der Ausgangspunkt.

**Quelle:** [Plan #122](https://github.com/mpetrovic-hub/backend/issues/122#issuecomment-5541816114).

## D-005 — Detailprüfungen gezielt aktivieren

**Bezug:** #122, Ergänzung vom 21.09.2026. **Status:** gültig.

**Entscheidung:** Den allgemeinen Datenbankprüfer wiederverwendbar erweitern, aber im Schema-Vertrag ausdrücklich festlegen, welche Detailprüfungen beim allgemeinen Deployment aktiv sind.

**Warum:** Bereits vorhandene Detailvorgaben für eine spezielle Engagement-Migration dürfen durch die SMS-Änderung nicht unbeabsichtigt zu neuen allgemeinen Deployment-Hürden werden. Gleichzeitig reicht bei SMS das bloße Vorhandensein eines Spalten- oder Indexnamens nicht aus, um korrekte Generationstrennung sicherzustellen.

**Grenze für #122:** Zusätzlich dauerhaft prüfen: `allocation_version` als `VARCHAR(50) NOT NULL DEFAULT 'legacy'` in Assignment und Summary sowie den vollständigen eindeutigen Summary-Schlüssel `(landing_key, service_key, variant_key, seed, allocation_version)` in dieser Reihenfolge. Bestehende allgemeine und migrationsspezifische Engagement-Prüfungen unverändert lassen; keine zusätzlichen Detailprüfungen anderer Tabellen aktivieren. Tatsächlichen WordPress-Präfix verwenden.

**Akzeptierte Folge:** Nicht alle vorhandenen Detailvorgaben werden sofort allgemein geprüft. Weitere Aktivierungen sind spätere bewusste Entscheidungen über denselben Mechanismus.

**Neu bewerten, wenn:** ein eigener Auftrag die Prüfungen anderer Tabellen erweitern soll.

**Quelle:** [ergänzter Plan #122](https://github.com/mpetrovic-hub/backend/issues/122#issuecomment-5541816114).

## D-006 — Vorbereitung und neue Verteilung in zwei Stufen

**Bezug:** #122/#126. **Status:** gültig.

**Entscheidung:** Die vorhandenen Issues werden neu gefasst: #122 bereitet generationenfähige Speicherung und Zählung vor und behält die bisherige Verteilung unter `legacy`; #126 aktiviert anschließend `fr_sms_v2`. #126 setzt den geprüften und vom Nutzer abgenommenen Produktionsrollout von #122 voraus.

**Warum:** Datenbankumstellung und Änderung der Ausspielung können getrennt beurteilt werden. Der vorbereitete Stand aus #122 bietet außerdem einen sinnvollen Rückkehrpunkt, der bereits beide Generationen korrekt behandeln kann.

**Akzeptierte Folge und Grenze:** Zwei PRs und eine Produktionsabnahme bedeuten hier zusätzlichen, begründeten Aufwand. Das wird nicht zum Standard für jede kleine Änderung. Eine Rückkehr von #126 zu #122 betrifft den Anwendungscode; gespeicherte Generationen und bestehende Assignments bleiben erhalten. Der alte, noch nicht generationenfähige Stand ist dafür kein gleichwertiger Ersatz.

**Neu bewerten, wenn:** eine künftige Änderung ohne solchen vorbereiteten Zwischenstand sicher unabhängig ausgeliefert werden kann.

**Quellen:** [Plan #122](https://github.com/mpetrovic-hub/backend/issues/122#issuecomment-5541816114), [Plan #126](https://github.com/mpetrovic-hub/backend/issues/126#issuecomment-5603119910).

## D-007 — Online vorbereiten, bei belegter Sperre abbrechen

**Bezug:** #122. **Status:** gültig nach gezieltem lokalem Versuch.

**Entscheidung:** Während der Datenbankvorbereitung arbeitet der bisherige Code weiter und schreibt `legacy`. Die Migration verwendet die geprüften Online-Verfahren. Bekommt sie die nötige Sperre nicht sofort, bricht sie ab; kein automatisches Wiederholen und kein stärker blockierender Ersatzweg.

**Warum:** Der Versuch zeigte, dass wartende Tabellenänderungen Schreibvorgänge beeinträchtigen können. Sofortiger Abbruch begrenzt dieses Risiko. Erfolgreiche additive Vorbereitungsschritte zurückzunehmen wäre für diesen erwarteten Fall unnötig und würde den Ablauf verkomplizieren.

**Akzeptierte Folge und Grenze:** Ein Versuch kann unvollständig enden. Erfolgreiche Schritte bleiben bestehen; nach Zustandsprüfung und erneuter menschlicher Freigabe werden nur fehlende Schritte nachgeholt. Fremde Datenbankvorgänge nicht automatisch beenden. Versionsfähigen Code erst nach vollständiger Zielprüfung aktivieren. Vor `fr_sms_v2` dürfen keine versionsblinden Schreiber mehr laufen.

**Neu bewerten, wenn:** Zielsystem oder konkreter Migrationsschritt die geprüften Verfahren nicht unterstützt. Lokale Laufzeiten sind keine Zusage für Produktion; nicht still auf blockierende Verfahren wechseln.

**Quelle:** [Plan #122 einschließlich Prototypnachweisen](https://github.com/mpetrovic-hub/backend/issues/122#issuecomment-5541816114).

## D-008 — Backup bereithalten, nicht routinemäßig zurückspielen

**Bezug:** #122/#126. **Status:** gültig.

**Entscheidung:** Vor dem Rollout eine aktuelle Sicherung und deren geprüfte Wiederherstellbarkeit bereitstellen. Erwartete Sperrabbrüche nach D-007 behandeln. Unerwartete Datenprobleme benötigen einen gezielten Wiederherstellungsvorschlag und gesonderte Freigabe.

**Warum:** Seit einer Sicherung können neue Assignments und Ereignisse eingegangen sein. Ein pauschales Zurückspielen könnte gerade die geschäftlichen Fakten überschreiben, die erhalten werden sollen.

**Akzeptierte Folge und Grenze:** Nicht jeder denkbare Schaden bekommt vorab ein fertiges Reparaturverfahren. Im Fehlerfall zuerst Zustand und betroffene Daten feststellen. Eine erfolgreiche Durchführung erfordert weder Daten-Restore noch Code-Rückkehr; solche Maßnahmen sind bedingt, keine immer abzuhakenden Erfolgskriterien.

**Neu bewerten, wenn:** ein konkreter Fehler vorliegt; dessen Auswirkungen und seit dem Backup entstandene Daten bestimmen die geeignete Maßnahme.

**Quelle:** [Plan #122](https://github.com/mpetrovic-hub/backend/issues/122#issuecomment-5541816114).

## D-009 — Tatsächlich verfügbare Tests nutzen, keine Testplattform nebenbei bauen

**Bezug:** #122/#126. **Status:** gültig; Umgebungsbefund aus September 2026.

**Entscheidung:** Vorhandene automatisierte Projekttests mit künstlichen Daten und simulierten externen Antworten sowie eine temporäre lokale MariaDB für die Datenbankprüfung verwenden. Keine vollständige isolierte WordPress-Testinstallation als Zusatzauftrag einführen.

**Warum:** Eine solche WordPress-Umgebung existierte bei der Planung nicht. Sie vorauszusetzen verdeckt eine Prüflücke; sie für diese Änderung aufzubauen würde den Umfang erheblich erweitern. Gezielte Tests liefern einen passenden Nachweis, sofern ihre Grenzen ausdrücklich benannt werden.

**Akzeptierte Folge und Grenze:** Kein behaupteter vollständiger WordPress-/NTH-End-to-End-Nachweis. Ein geprüfter Migrationsbaustein ist nicht automatisch ein geprüfter vollständiger WP-CLI-Aufruf. Tests erfolgen während der Implementierung vor abschließendem Review; nach Änderungen werden betroffene Tests wiederholt. Vor Deployment wird geprüft, ob die Nachweise den freigegebenen Stand abdecken.

**Neu bewerten, wenn:** künftig eine geeignete Umgebung existiert oder ein Auftrag weitergehende Abdeckung tatsächlich benötigt. Den damaligen Umgebungsbefund nicht ungeprüft für immer fortschreiben.

**Quellen:** [Plan #122](https://github.com/mpetrovic-hub/backend/issues/122#issuecomment-5541816114), [Plan #126](https://github.com/mpetrovic-hub/backend/issues/126#issuecomment-5603119910).

## D-010 — Reale Vorgänge beobachten, keine Sales in Live-Prod simulieren

**Bezug:** #122/#126; zugleich grundsätzliche Nutzerpräferenz. **Status:** gültig.

**Entscheidung:** Für diese Issues nach dem Deployment echte eingehende Vorgänge ausschließlich lesend beobachten. Keine künstlichen Verkäufe, Callback-Replays oder dadurch ausgelösten Affiliate-Postbacks.

**Warum:** Ein Produktions-Testverkauf kann reale geschäftliche Folgen auslösen. Für diese Änderung rechtfertigt der zusätzliche Nachweis solche Eingriffe nicht; synthetische Fälle werden außerhalb des realen Verkaufsablaufs geprüft.

**Akzeptierte Folge und Grenze:** In einem kurzen Beobachtungsfenster werden möglicherweise nicht alle Ereignisse sichtbar. Diese bleiben „noch nicht beobachtet“ und gelten nicht als bestanden. Der Nutzer entscheidet über weitere Beobachtung. Der vereinbarte Nachkontrollbedarf ist kein automatisch eingerichtetes Monitoring.

**Neu bewerten, wenn:** ein späterer Auftrag eine Produktionssimulation zwingend erfordert. Dann zuerst begründen, warum andere Prüfungen nicht ausreichen, Auswirkungen erklären und ausdrücklich freigeben lassen. Keine pauschale Ausnahmefreigabe.

**Quellen:** [Plan #122](https://github.com/mpetrovic-hub/backend/issues/122#issuecomment-5541816114), [Plan #126](https://github.com/mpetrovic-hub/backend/issues/126#issuecomment-5603119910).

## D-011 — Marketingauswertung vom Implementierungsauftrag trennen

**Bezug:** #126. **Status:** gültig.

**Entscheidung:** Die neue Ausspielung betrifft alle passenden französischen NTH-One-off-Landingpages. Die geschäftliche Auswertung „FR Download now“ für lp5-fr und lp6-fr wird nicht als zusätzliche Codefunktion oder technische Gruppierung umgesetzt.

**Warum:** Der Kontext erklärt, wofür die Daten gebraucht werden. Er begründet aber keine zusätzliche Funktion, die der Implementer aufgrund eines im Issue erwähnten Namens erfinden soll.

**Akzeptierte Folge und Grenze:** Generationen getrennt und korrekt verfügbar zu machen gehört zum Auftrag; die spätere Marketinganalyse und Bewertung der Kennzahlen gehören nicht zur technischen Umsetzung dieses Issues.

**Neu bewerten, wenn:** eine eigene Anforderung eine konkrete Auswertungsfunktion definiert.

**Quelle:** [Plan #126](https://github.com/mpetrovic-hub/backend/issues/126#issuecomment-5603119910).

## D-012 — Wichtige Entscheidungen recherchieren und begründen, nicht Fragen maximieren

**Bezug:** Planungsprozess, Zustimmung und Skill-Erstellung am 21.09.2026. **Status:** gültig als vereinbarte Planungsrichtung; keine Änderung von AGENTS.md durch diesen Eintrag.

**Entscheidung:** Vor Rückfragen vorhandene Antworten und konkrete Codeauswirkungen recherchieren. Wesentliche offene Entscheidungen gebündelt vorlegen, zusätzliche Absicherungen am Auftrag messen und den konsolidierten Plan einmal gezielt aus Implementersicht prüfen.

**Warum:** Die lange SMS-Planung litt sowohl unter übersehenen Auswirkungen als auch unter unbelegten Annahmen, wiederholten Fragen und zusätzlichem Umfang. Mehr Fragen allein beheben das nicht. Der Nutzer braucht verständliche Entscheidungen mit Beispielen aus kiwi-backend, keine abstrakte technische Diskussion.

**Akzeptierte Folge und Grenze:** Kein Versprechen, jeden späteren Befund vorherzusehen. Neue Befunde werden auf ihre Bedeutung für den Auftrag geprüft; nicht automatisch alle Planungsfragen erneut öffnen. Der Wunsch nach vorheriger Abstimmung neuer Pfade und Namen bleibt bestehen. Daraus folgt keine pauschale Ermächtigung, offene Geschäfts- oder Architekturentscheidungen selbst zu treffen.

**Neu bewerten, wenn:** reale nächste Planungsrunden zeigen, dass wesentliche Fragen weiter übersehen oder bereits geklärte Punkte erneut abgefragt werden. Gezielt nachbessern, nicht für jeden Einzelfall eine neue allgemeine Pflicht hinzufügen.

**Quelle:** Nutzerfreigabe zur Überführung der Auswertung in `kiwi-github-planner-round_v2` im Gespräch vom 21.09.2026. Die ausführbare Skill-Anleitung liegt in der jeweiligen Codex-Skill-Installation; dieses Protokoll benötigt sie zum Verständnis der Begründung nicht.
