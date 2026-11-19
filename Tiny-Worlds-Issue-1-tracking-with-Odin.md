# Tiny Worlds Issue #1: Tracking mit Odin

Stand: 21. Oktober 2026. Laufende lokale Arbeitsnotiz, noch kein Implementierungsplan oder freigegebener Schnittstellenvertrag. [Tiny-Worlds-Issue #1](https://github.com/Holmson/tiny-worlds/issues/1) einschließlich seiner Kommentare ist die maßgebliche Quelle für die gemeinsame Abstimmung; diese Datei hält nur den Odin-Arbeitsstand fest und wird bei Abweichungen an das Issue angepasst.

## Ausgangspunkt und Leitplanken des Nutzers

- „Odin“ ist der Spitzname für das bestehende kiwi-backend. Odin gibt den vorhandenen mVAS-Payment-Flow vor; Tiny Worlds soll daran angebunden werden.
- Das Backend soll nicht umgebaut werden. Keine großen Sonderlösungen und keine neue Tabelle nur für Tiny Worlds. Kleine, saubere Ergänzungen wie das Speichern einer notwendigen Referenz oder eines Tokens sind möglich, wenn sie nach Codeprüfung wirklich gebraucht werden.
- Erste Idee: ein einfaches Server-zu-Server-Tracking nach bestätigtem Sale, ähnlich dem bestehenden Affise-Postback, mit den tatsächlich benötigten Angaben für Tiny Worlds.
- Beim Top-up muss Tiny Worlds beim Aufruf der Odin-Landing-Page eine eindeutige Tracking-/Kaufreferenz mitgeben, analog zur Click-ID im bestehenden S2S-Tracking. Die Anforderung an einen solchen Parameter ist kein Hindernis; seine konkrete Form und Prüfung werden im Issue abgestimmt.
- [Tiny-Worlds-Issue #1](https://github.com/Holmson/tiny-worlds/issues/1) ist aus Odin-Sicht ein Vorschlag zur Abstimmung. Seine Endpunkte, Felder und Prozessschritte sind nicht bereits bestätigt. Das Issue beauftragt weder Implementierung noch echte Zahlungen.
- Für den Start geht es um Frankreich und One-off. Weitere Länder, Services und später eventuell Abonnements bleiben als Erweiterungsbedarf im Blick. Abo-Kündigungen sind jetzt kein implementierter Backend-Flow und werden nicht vorab gebaut.

## Beabsichtigte Nutzerwege

### 1. Neukunde über eine Odin-Landing-Page

Eine Landing-Page im Backend bewirbt Tiny Worlds und nutzt den vorhandenen landes- und servicespezifischen mVAS-Flow, zunächst voraussichtlich NTH FR One-off. Nach einem bestätigten Sale informiert Odin Tiny Worlds über den Kauf und das zugehörige Credit-Paket. Der Nutzer erhält den üblichen Zugang zur Content-Seite; für den derzeitigen FR-Flow ist ein Link in der MT-SMS die naheliegende Hypothese. Die genaue Konto- und Kaufzuordnung bei Tiny Worlds ist noch offen.

### 2. Top-up aus Tiny Worlds

Ein bestehender Tiny-Worlds-Nutzer wählt dort „Mobile Payment“ und gelangt zu einer passenden Odin-Landing-Page im Stil „Top up your Credits now“. Die Zahlung folgt weiterhin dem für Land und Service konfigurierten Odin-Flow. Nach bestätigtem Sale erhält Tiny Worlds die zur Gutschrift nötige Nachricht. Wie die ursprüngliche Tiny-Worlds-Kontoreferenz sicher und zuverlässig bis zum Sale erhalten bleibt, ist noch zu klären.

## Geprüfter Ist-Zustand im Repository

- NTH FR One-off ist ein Premium-SMS-Flow: Landing-Page → SMS des Nutzers (MO) → bezahlte Content-SMS (MT) → NTH-Delivery-Report. Der Sale wird erst bei einem erfolgreichen terminalen Report in `wp_kiwi_sales` gespeichert. Siehe `docs/integrations/nth/fr/one-off/fr-one-off-nth-api.md`, Abschnitt „Runtime Flow Summary“, und `includes/services/class-nth-fr-one-off-service.php`, `handle_notification()`.
- Das Backend hat bereits generische Sales-Daten mit eindeutiger `sale_reference` und `context_json` in `wp_kiwi_sales`. Der NTH-Flow speichert zusätzlich `meta_json` in seiner vorhandenen Transaktionstabelle. Das ist vorhandener Speicherraum, aber noch **keine Entscheidung**, dort Tiny-Worlds-Referenzen abzulegen.
- Das bestehende Affise-Postback läuft über Click-Attribution, einen bestätigten Conversion-Status und einen aus einer URL-Vorlage gebauten HTTP-Request. Es benötigt derzeit `click_id`; die Capture-Logik legt ohne `click_id` keinen Attributionseintrag an. Siehe `docs/architecture/click-attribution-and-postbacks.md`, `includes/services/class-tracking-capture-service.php` und `includes/services/class-affiliate-postback-dispatcher.php`.
- Die NTH-SMS-CTA trägt die interne `transaction_id` nur dann mit, wenn eine Attribution vorhanden ist. Ohne solchen Bezug kann NTH beim MO zwar eine neue interne Transaktionsreferenz erzeugen, diese ist aber noch keiner Tiny-Worlds-Kontoreferenz zugeordnet. Siehe `includes/providers/nth/class-nth-primary-cta-adapter.php` und `includes/services/class-nth-fr-one-off-service.php`.
- Die Content-SMS wird bereits vor dem terminalen Delivery-Report zusammengestellt und versendet. Ein Link in dieser SMS darf daher für sich allein keine Credits freischalten. Die aktuelle Textvorlage ist servicebezogen; ihre Platzhalter enthalten derzeit keine individuelle Kauf- oder Kontoreferenz. Siehe `includes/services/class-nth-fr-one-off-service.php`, `build_mt_message_text()`.
- Der NTH-One-off-Flow hat eine konfigurierbare Sperrfrist nach einem bestätigten Sale für denselben Subscriber-/Service-Kontext: Standard im Adapter sieben Tage, mit `0` abschaltbar. Wiederholte Top-ups könnten dadurch blockiert werden. Die tatsächliche Runtime-Konfiguration wurde nicht geprüft. Siehe `docs/integrations/nth/fr/one-off/fr-one-off-nth-api.md`, „FR-Specific Technical Rules“, und `includes/services/class-nth-fr-one-off-service.php`, `handle_inbound_mo()`.
- Für die FR-Landing-Page und die MT-SMS gelten konkrete Pflichtangaben zu Preis, Service und „kein Abo“. Eine neue Tiny-Worlds-Seite muss diese bestehenden Vorgaben einhalten; die heutige Standard-SMS verlinkt noch auf MyJoyplay. Siehe `docs/integrations/nth/fr/one-off/fr-one-off-nth-api.md`, „Landing-Page Compliance Requirements“ und „Mandatory content-message rule“.

## Vorläufige Einordnung von Issue #1

Der Entwurf beschreibt unter anderem eine eigene Checkout-API, direkte Odin-zu-Tiny-Worlds-Übergabecodes, Webhooks, Statusabfragen und Refund-Prozesse. Bei den hier beschriebenen zwei Odin-Landing-Pages wirkt das umfangreicher als der erste konkrete Bedarf. Das ist eine **Arbeitshypothese**, keine Streichung von Sicherheitsanforderungen oder eine Zusage, dass ein einzelner Postback genügt.

Für einen schlanken Start erscheinen vier Fähigkeiten notwendig:

1. Den richtigen Tiny-Worlds-Bezug beziehungsweise einen später sicher einlösbaren Kaufbezug durch den vorhandenen SMS-Flow tragen.
2. Erst nach einem von Odin bestätigten Sale eine eindeutig referenzierte Nachricht an Tiny Worlds senden.
3. Doppelte oder verspätete Nachrichten ohne doppelte Credits verarbeiten und fehlgeschlagene Zustellung erkennen und nachholen können.
4. Bei Neukunden den bezahlten Kauf nach der Registrierung dem richtigen Konto zuordnen, ohne dass ein bloßer SMS-Link schon Zahlungsnachweis oder Kontozugang ist.

Der bestehende Affise-Mechanismus liefert dafür ein Muster (bestätigter Sale, S2S-Versand, Wiederholbarkeit). Die nötige Tracking-Referenz wird beim Top-up von Tiny Worlds mitgegeben; die frühere Einordnung der `click_id`-Anforderung als Hindernis war falsch. Ob die heutige Affise-spezifische Konfiguration direkt erweitert werden kann oder ein kleiner allgemeiner Versandweg sinnvoller ist, bleibt eine technische Prüfung und keine Vorentscheidung.

### Im Issue veröffentlichte Odin-Fragen

Am 21. Oktober 2026 wurden [die Tracking-Leitplanke und zwei Fragen als Kommentar in Issue #1](https://github.com/Holmson/tiny-worlds/issues/1#issuecomment-6316928903) festgehalten. Die Antworten werden dort diskutiert; die folgenden Einträge bleiben bis dahin offen.

### Idee: Tiny Worlds bei neuen Issue-Kommentaren gezielt ansprechen

Am 21. Oktober 2026 fragte der Nutzer nach einem GitHub-Tag ähnlich `@codex`, damit der Tiny-Worlds-Agent erkennt, dass ein neuer Kommentar in Issue #1 beantwortet werden soll. Das ist eine **offene Koordinationsidee**, noch kein eingerichteter Trigger und keine Beauftragung zur Umsetzung.

- Eine normale GitHub-Erwähnung (`@GitHub-Name`) benachrichtigt den erwähnten Account; sie startet nicht automatisch einen bestehenden Codex-Chat. Issue #1 ist dem Account `mpetrovic-hub` zugewiesen, der die Issue-Benachrichtigungen abonniert hat. Wer den Tiny-Worlds-Agenten betreibt und wie er gestartet wird, ist damit nicht geklärt.
- Im Tiny-Worlds-Repository ist auf `main` derzeit nur [`.github/workflows/verify.yml`](https://github.com/Holmson/tiny-worlds/blob/main/.github/workflows/verify.yml) sichtbar. Dieser Workflow läuft bei Push, Pull Request oder manuellem Start; ein `issue_comment`-Trigger ist dort nicht konfiguriert.
- Technisch könnte Tiny Worlds einen kleinen GitHub-Workflow oder GitHub-App-Webhook für neue `issue_comment`-Ereignisse einrichten. Beispiel für eine bewusst erkennbare Anforderung im Kommentar: `/tiny-worlds bitte beantworten: …`. Der Empfänger müsste auf Issue #1 und berechtigte Absender begrenzt sein, Bot-Eigenkommentare ignorieren und die Antwort im selben Issue veröffentlichen. Der Kommentarinhalt ist dabei untrusted input; die spätere Umsetzung muss Berechtigungen und Secrets passend schützen.
- Zu entscheiden: Soll nur ein Mensch eine GitHub-Benachrichtigung erhalten, oder soll ein Tiny-Worlds-Agent automatisch lesen und antworten? Falls Agent: Welcher konkrete Agent/Account verarbeitet das Ereignis, und sollen Antworten direkt erscheinen oder erst als Entwurf geprüft werden? Erst danach lässt sich ein Tag oder Kommando verbindlich festlegen.

Quellen für die technische Möglichkeit: [GitHub-Benachrichtigungen](https://docs.github.com/en/subscriptions-and-notifications/concepts/about-notifications), [`issue_comment` als Actions-Trigger](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#issue_comment) und [GitHubs Hinweise zu ungeprüftem Kommentartext in Workflows](https://docs.github.com/en/actions/concepts/security/script-injections).

#### Wie die Action angelegt würde (Erläuterung, noch keine Umsetzung)

1. Im Repository `Holmson/tiny-worlds` unter **Code → `.github/workflows` → Add file → Create new file** eine eigene Datei, etwa `issue-1-reply.yml`, anlegen. Der vorhandene Prüf-Workflow bleibt eigenständig. Die neue Datei zunächst per Pull Request prüfen und erst nach Freigabe auf `main` bringen; GitHub verarbeitet `issue_comment` nur, wenn der Workflow auf dem Default-Branch liegt. [GitHub-Quickstart](https://docs.github.com/en/actions/get-started/quickstart), [Ereignisregel](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#issue_comment).
2. Als Auslöser **neuer Issue-Kommentar** (`issue_comment`, `created`) festlegen. Der Workflow prüft Issue `#1`, eine vereinbarte Markierung wie `/tiny-worlds`, erlaubte GitHub-Absender und dass der Kommentar nicht vom Bot selbst stammt. Ein bloßes `@` ohne vorhandenes Konto oder Bot ist noch keine solche Automation.
3. Für eine automatische Antwort liest der Workflow das Issue samt Kommentaren, gibt diese als Daten an einen gesonderten Agentenlauf und schreibt dessen geprüfte Antwort in **dasselbe Issue**. Ein möglicher Baustein ist [OpenAIs `codex-action`](https://github.com/openai/codex-action); diese startet einen **neuen** Codex-Lauf pro Ereignis und weckt keinen vorhandenen Desktop-Chat. Sie benötigt einen API-Schlüssel als GitHub-Secret; [API-Nutzung wird separat vom ChatGPT-Abonnement abgerechnet](https://help.openai.com/en/articles/9039756-managing-billing-settings-on-chatgpt-web-and-platform).
4. Agent nur lesend ausführen; GitHub-Schreibrecht für den Antwort-Kommentar möglichst in einem getrennten Schritt/Job vergeben. Issue-Kommentare als ungeprüfte Eingaben behandeln, nicht direkt in Shell-Befehle einsetzen. Zuerst mit einem ausdrücklich markierten Testkommentar prüfen, ob der Workflow genau einmal startet und keine eigene Antwortschleife auslöst. [GitHub-Berechtigungen](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#permissions), [Sicherheitshinweise zur Codex-Action](https://github.com/openai/codex-action/blob/main/docs/security.md).

Vor einer konkreten Workflow-Datei ist die Produktentscheidung offen, ob Tiny Worlds selbsttätig ins Issue antworten soll oder ob nur ein Mensch benachrichtigt wird. Für eine reine Benachrichtigung reicht eine GitHub-Erwähnung des zuständigen Accounts; dafür braucht es keine Action.

## Offene Entscheidungen für die nächste Abstimmung

1. **Zuordnung beim Top-up:** Tiny Worlds muss eine eindeutige Tracking-/Kaufreferenz an die Odin-Landing-Page übergeben. Format, Prüfung und Zuordnung nach dem SMS-App-Wechsel sind noch abzustimmen.
2. **Zuordnung beim Neukauf – im Issue gefragt:** Gibt es vor dem SMS-Kauf schon ein Tiny-Worlds-Konto, oder wird es erst danach angelegt? Falls danach: Wie wird der bestätigte Kauf später sicher und genau einmal übernommen?
3. **SMS-Link:** Reicht ein allgemeiner Link zu Tiny Worlds für Top-up, und braucht der Neukundenweg einen individuellen Link? Wie passt er in den NTH-MT-Text und die FR-Pflichtangaben?
4. **Top-up-Häufigkeit – im Issue gefragt:** Sollen mehrere One-off-Käufe desselben Nutzers innerhalb von sieben Tagen möglich sein? Die bestehende Sperrfrist müsste dazu bewusst pro Service geprüft werden.
5. **Angebot:** Welcher reale FR-Service, Bruttopreis und welche Credit-Anzahl gelten? Der Preis und das Fünf-Credits-Beispiel in Issue #1 sind nicht als Freigabe zu verstehen.
6. **Minimaler S2S-Vertrag:** Welche Felder akzeptiert Tiny Worlds tatsächlich (z. B. eindeutige Sale-Referenz, Angebotskennung, bestätigter Status, Zuordnungsreferenz)? Wie authentifiziert Tiny Worlds Odin, bestätigt den Empfang und verhindert doppelte Gutschriften?
7. **Fehler und spätere Korrekturen:** Wie werden fehlgeschlagene Meldungen nachgeholt? Welche Rückbuchungs-/Erstattungsinformationen kann der konkrete FR-NTH-Flow heute überhaupt liefern? Ein späteres Abo-Ende ist als künftiger Ereignistyp zu bedenken, nicht als Teil des FR-Starts.
8. **Reihenfolge:** Welche der beiden Landing-Pages wird zuerst benötigt? Beide sind fachlich gewünscht; eine Umsetzungsreihenfolge wurde noch nicht festgelegt.

## Nächster Schritt

Die beiden veröffentlichten Fragen und weitere Schnittstellenentscheidungen in Issue #1 diskutieren. Danach den lokalen Arbeitsstand daran anpassen und erst auf Basis der abgestimmten Antworten einen kleinen Schnittstellenvertrag und einen Implementierungsmodus festlegen. Aus dieser Notiz allein keine Backend-Implementierung, Aktivierung oder Production-Änderung ableiten.
