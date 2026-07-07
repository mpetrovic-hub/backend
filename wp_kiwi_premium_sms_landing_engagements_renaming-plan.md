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
    - Ja, neuer generischer Repository-Name `Kiwi_Landing_Session_Engagement_Repository`; alte Premium-SMS-Repository-Klasse nicht behalten, sofern nicht unbedingt technisch nötig.
18. Soll der Premium-SMS-Fraud-Code nur noch gegen ein generisches Interface arbeiten, z.B. Landing_Engagement_Repository, damit Fraud nicht Besitzer der Tabelle bleibt?
    - Ja. Premium-SMS-Fraud nutzt künftig das generische `Kiwi_Landing_Session_Engagement_Repository`; die Datenquelle gehört fachlich nicht mehr zum Premium-SMS-Namespace.
19. Soll eine zentrale Tabellen-Namensquelle eingeführt werden, damit Summary, Retention, Device-Harvest und Fraud nicht alle den Namen selbst zusammensetzen?
    - Ja. Eine zentrale Tabellen-Namensquelle ist gewünscht, auch als zukünftiges Muster für andere Tabellen, auf die mehrere Prozesse zugreifen.
20. Gibt es eine gewünschte Grenze: "nur Tabellen-/Repository-Benennung", keine Änderung an Event-Schema, Retention, Fraud-Logik oder Summaries?
    - Ja. Keine Event-Schema-Änderung in diesem Issue; nur Namen und Architekturgrenzen ändern. Retention, Fraud-Regeln und Summary-Berechnungen nur soweit anfassen, wie es für die Namensumstellung nötig ist.

# Rollout & Doku
21. Soll der Refactor feature-flagged/konfigurierbar sein, oder reicht ein schema-versionierter WordPress-Migrationspfad?
    - Schema-versionierter WordPress-Migrationspfad; kein Feature Flag.
22. Welche Produktionsprüfung wäre Dir wichtig: bestehende Fraud-Ansicht, Landing Funnel Daily Summary, Tkzone Summary, Device Model Harvest, Retention Gate?
    - Besonders wichtig: Landing Funnel Daily Summary, Tkzone Summary, Device Model Harvest und Retention Gate. Ohne Landing Funnel Daily Summary/Tkzone Summary failt das Retention Gate und damit die Retention-Kette.
23. Soll die Migrationsstrategie in TODO.md, docs/architecture/, docs/operations/ oder als GitHub Issue/PR-Text dokumentiert werden?
    - Zum jetzigen Zeitpunkt nicht in `docs/architecture/` oder `docs/operations/`, weil diese den Live-Status dokumentieren sollen. Die Plan-Datei dient der Nachvollziehbarkeit; am Ende soll daraus mit `kiwi-issues-in-github-creator` ein GitHub-Issue erstellt werden.
24. Gibt es eine harte Deadline oder einen Zusammenhang mit Retention/DB-Größe, wegen der wir eher eine risikoarme Zwischenlösung wählen sollten?
    - Keine harte Deadline im Sinne eines festen Datums. Der Name-Refactor ist aber Blocker/Vorarbeit für GitHub Issue #72 (`Add retention cleanup for wp_kiwi_premium_sms_landing_engagements`) und hat daher Priority=High. Keine risikoarme Zwischenlösung gewünscht, sondern saubere vollständige Umstellung vor #72.
    - Der spätere Name-Refactor-Issue soll explizit festhalten, dass #72 nach dem Rename in Titel/Beschreibung/Akzeptanzkriterien auf `wp_kiwi_landing_session_engagements` angepasst werden muss.
