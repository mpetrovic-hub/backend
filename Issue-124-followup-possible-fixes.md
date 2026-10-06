# Issue #124 – mögliche Folgeschritte

## Fokus: Sessions-`raw_context` inhaltlich kürzen

Diese Notiz hält den aktuellen Stand und einen ersten Entwurf fest. Es wurde kein Produktionsinhalt geändert. Die Zahlen unten stammen aus der untersuchten Archivkopie und sind keine neue Messung des Live-Systems.

### Was aktuell in `raw_context` steht

Die untersuchte Kopie enthält **1.029.071 Sessions** mit zusammen rund **1,003 GiB** `raw_context`. Davon waren **832.784 Zeilen (ca. 81 %)** bereits im kompakten Format; **196.287 Zeilen (ca. 19 %)** enthielten noch das ältere Format. Das Archiv ist also nicht vollständig auf das kompakte Format umgestellt.

Die bestehende Kompaktierung ersetzt den früheren Inhalt durch eine ausgewählte JSON-Struktur. Das aktuell kompakte Format sieht – mit absichtlich ausgelassenen echten Werten – so aus:

```json
{
  "schema": "landing_session_raw_context_compact_v1",
  "landing_page": {
    "key": "…",
    "country": "…",
    "flow": "…",
    "provider": "…",
    "locale": "…",
    "service_type": "…",
    "business_number": "…",
    "keyword": "…",
    "service_key": "…",
    "shortcode": "…",
    "price_label": "…",
    "kpi_cta_steps": { "…": "…" },
    "render_mode": "…",
    "folder_name": "…",
    "cta_href": "…"
  },
  "client_ip_resolution": {
    "source": "…",
    "peer_trusted": true,
    "trusted_proxy_configured": true,
    "forwarded_headers_present": ["…"],
    "other_client_ip_headers_present": ["…"],
    "forwarded_candidate_count": 0,
    "resolution_reason": "…"
  }
}
```

Die ältere Form hatte zusätzlich unter anderem `host`, `request_uri`, `session_dimensions` und `device_context`. Diese wurden bei der bestehenden Kompaktierung entfernt. Die echten Werte werden hier nicht wiedergegeben, weil darin Anfrage- und Geräteinformationen stehen können.

### Erster Vorschlag für weitere Kürzung

In `landing_page` stehen fünf Angaben, die auch als eigene Sessions-Spalten existieren: `key`, `country`, `flow`, `provider` und `service_key`. Die bestehende Kompaktierung füllt diese JSON-Felder sogar aus den Spalten, wenn der jeweilige JSON-Wert fehlt.

**Vorschlag angenommen:** Diese fünf Werte aus dem kompakten Sessions-`raw_context` weglassen und die eigenen Tabellenspalten als maßgebliche Werte verwenden.

**Abgleich erfolgt:** In allen **832.784** kompakten Sessions der untersuchten Archivkopie stimmen die fünf JSON-Werte exakt mit den zugehörigen Spalten überein. Keiner der JSON-Werte fehlt; es gibt **0 Abweichungen**. Der Nutzer hat außerdem bestätigt, dass es keine externe Auswertung gibt, die diese Archiv-DB liest.

In den bereits kompakten Zeilen machen die fünf Werte zusammen mindestens rund **37,6 MB** aus (Wertbytes; JSON-Schlüssel und Syntax kommen noch dazu). Das wären mindestens etwa **5,5 % des kompakten `raw_context`** beziehungsweise **3,5 % des gesamten Sessions-`raw_context`** in dieser Kopie.

**Messung mit Archivkopie (durchgeführt):** Die unveränderte Baseline wurde gesichert. Für den Test wurde daraus eine separate Kopie erzeugt, in der die fünf Werte aus dem Sessions-`raw_context` entfernt wurden. Anschließend wurden logische `raw_context`-Größe, Dateigröße/SQLite-Seitenzahl, Laufzeit und Prozess-I/O von `quick_check` verglichen. Weil das SQL-Update allein die Datei nicht physisch verkleinerte, wurde die Testkopie danach mit SQLite `VACUUM` neu aufgebaut. Die Live-Archivdatei blieb unangetastet; Ergebnisse stehen im folgenden Abschnitt.

### Ausgeführter Test mit der Archivkopie

Die unveränderte Baseline wurde unter `kiwi_retention_archive_2026_copy_for_codex_rawcontext-compressed.before-session-json-trim.sqlite` gesichert. In der benannten Testkopie wurden die fünf Werte aus `raw_context` in allen **1.029.071 Sessions-Zeilen** entfernt. Die Handoff-Tabelle wurde nicht geändert.

- Vorher stimmten die Werte in allen Sessions exakt mit den Spalten überein: **0 Abweichungen**. Es gab **0 ungültige JSON-Zeilen**.
- Sessions-`raw_context`: **1.076.757.689 → 924.616.249 Byte**, Ersparnis **152.141.440 Byte (14,1 %)**.
- Das SQL-Update allein ließ die Datei zunächst bei **4.137.742.336 Byte** und **1.010.191 Seiten**. Danach wurde nur die Testkopie mit SQLite `VACUUM` neu aufgebaut.
- Nach `VACUUM`: **3.545.477.120 Byte** und **865.595 Seiten**; Verringerung um **592.265.216 Byte (14,3 %)**. `PRAGMA quick_check` meldete `ok`.
- Handoff blieb unverändert: **1.252.734 Zeilen**, `raw_context` weiterhin **427.758.602 Byte**.
- Im direkten `quick_check`-Vergleich meldeten beide Kopien `ok`: Baseline **4,589 s**, `rchar=8.081.694.920`, `syscr=1.973.074`; gekürzte und neu aufgebaute Kopie **4,767 s**, `rchar=6.919.758.037`, `syscr=1.689.398`. Das sind rund **14,4 % weniger Prozess-Leseaufrufe und gelesene Bytes**, aber keine kürzere Laufzeit in diesem einzelnen Vergleich.

`read_bytes` war bei beiden Läufen **0**: Die Testdateien kamen aus dem Betriebssystem-Cache. Prozess-I/O-Werte sind keine direkte Messung von Hostingers abgerechneten IOPS. Das Ergebnis zeigt weniger vom `quick_check` angeforderte Prozess-Leseaktivität und eine kleinere neu aufgebaute Testdatei, aber keinen Nachweis über Hostingers Abrechnung oder kalte Datenträgerzugriffe. Die Live-Archivdatei blieb unangetastet.

### Größerer möglicher Hebel – mit Datenverlust

`client_ip_resolution` belegt in den kompakten Zeilen ungefähr **216,5 MB** JSON. Eine stärkere Kürzung dort könnte mehr Platz sparen, würde aber Diagnoseinformationen entfernen. Vor einem Vorschlag dazu muss feststehen, welche dieser Informationen später tatsächlich für Fehleranalyse oder Nachweis gebraucht werden. Daher ist das noch keine beschlossene Kürzung.

### Abgrenzung

Der Test passt ausdrücklich eine separate Archivkopie an, um die Wirkung der Kürzung zu messen. Die Aussage, dass eine bestehende Archivdatei nicht automatisch kleiner wird, bezog sich auf eine Änderung an der laufenden Quelle: Sie schreibt eine bereits vorhandene Archivkopie nicht rückwirkend um. Das war keine Aussage gegen den geplanten Kopien-Test. Eine Änderung der Live-Quelle oder ein Umschreiben des Live-Archivs ist nicht Bestandteil dieses Tests.

Eine verlustfreie technische Komprimierung bleibt ein möglicher zweiter Ansatz. Sie ist von dieser inhaltlichen Kürzung getrennt zu messen und zu planen.

## Handoff-Tabelle – erster Vorschlag und Test

### Aktueller Inhalt

Der Handoff-`raw_context` enthält `event_value` und ein Objekt `ua_client_hints`. Dieses Objekt wiederholt sieben Werte, die auch als eigene Spalten der Handoff-Tabelle gespeichert sind: `ua_ch_supported`, `ua_ch_mobile`, `ua_ch_platform`, `ua_ch_platform_version`, `ua_ch_model`, `ua_ch_brands` und `ua_ch_full_version_list`.

In der unveränderten Archiv-Baseline gibt es **1.252.734 Handoff-Zeilen** mit **427.758.602 Byte** `raw_context`. Ein read-only Abgleich ergab für alle sieben Werte **0 Abweichungen**; auch wenn ein JSON-Feld fehlt, entspricht der Tabellenwert dem gespeicherten Standardwert.

### Vorschlag und Test in Archivkopie

Als ersten Schritt `ua_client_hints` vollständig aus dem Handoff-`raw_context` entfernen und die sieben eigenen Spalten behalten. `event_value` bleibt dabei vorerst erhalten.

In der benannten Testkopie wurde `ua_client_hints` aus allen **1.252.734 Handoff-Zeilen** entfernt. Eine Vorher-Kopie (`kiwi_retention_archive_2026_copy_for_codex_rawcontext-compressed.before-handoff-ua-client-hints-trim.sqlite`) wurde als Baseline gesichert; sie enthält bereits die Sessions-Kürzung. `event_value` blieb erhalten. Live-Daten wurden nicht geändert.

- Handoff-`raw_context`: **427.758.602 → 33.823.818 Byte**, Ersparnis **393.934.784 Byte (92,1 %)**.
- Die Datenbankdatei wurde nach dem JSON-Update mit SQLite `VACUUM` neu aufgebaut: **3.545.477.120 → 3.116.691.456 Byte** (**12,1 %** weniger); Seitenzahl **865.595 → 760.911**.
- `quick_check` meldete für beide Dateien `ok`. Die Sessions blieben unverändert bei **1.029.071 Zeilen** und **924.616.249 Byte** `raw_context`.
- Im direkten `quick_check`-Vergleich sank die Laufzeit von **3,994 s auf 3,448 s**. `rchar` sank von **6.919.758.024 auf 6.062.186.713** und `syscr` von **1.689.398 auf 1.480.030** (jeweils rund **12,4 %** weniger Prozess-Leseaktivität).

Bei beiden Läufen war `read_bytes=0`, also wurden die Seiten aus dem Betriebssystem-Cache gelesen. Das ist ein Vergleich der Prozess-Leseaktivität, keine direkte Messung von Hostingers abgerechneten IOPS oder kalten Datenträgerzugriffen.

Der Vorschlag und diese Messwerte wurden außerdem als vorläufiger Kommentar in [Issue #134](https://github.com/mpetrovic-hub/backend/issues/134#issuecomment-5913855651) festgehalten. Der formelle Codex Planner Report steht weiterhin aus.

### Archive-Health-Messung der bereinigten Kopie (cache-warm)

Am **2026-09-30** wurde auf der bereinigten Kopie `kiwi_retention_archive_2026_copy_for_codex_rawcontext-compressed.sqlite` der gleiche SQLite-`quick_check` im auf Hostinger eingesetzten Health-Check-Unterprozess ausgeführt. Es war kein Cronlauf. Die Kopie wurde dafür über einen privaten temporären Hardlink unter dem vom Tool akzeptierten Archivnamen geprüft; das Archiv selbst wurde nicht umgeschrieben. Das temporäre Verzeichnis und der Messdatensatz wurden nach dem Auslesen entfernt. Der Messdatensatz von heute Nacht blieb unverändert.

- Geprüfte Datei: **3.116.691.456 Byte**.
- Ergebnis: **`ok`**, `sqlite_check_ok`, Exit-Code **0**.
- Laufzeit: **3,847 s**.
- Prozessmessung: `read_bytes=0`, `write_bytes=0`, `syscw=0`, `ru_inblock=0`, `ru_oublock=0`.

Der nächtliche Live-Lauf dauerte **986,395 s** und meldete `read_bytes=4.136.366.080` sowie `ru_inblock=8.078.840`. Die beiden Laufzeiten waren zunächst kein fairer Vorher-nachher-Vergleich: Beim ersten Kopientest kamen **keine gelesenen Bytes vom Datenträger** beim Prozess an; die Daten waren bereits im Betriebssystem-Cache. Der Lauf bestätigte nur, dass die bereinigte Kopie den Check besteht. Auch `read_bytes` und `ru_inblock` messen nicht Hostingers abgerechnete IOPS direkt.

### Cache-kalter Vergleich mit der Nachtmessung

Am **2026-09-30, 15:17:34–15:30:01 UTC** lief ein neuer Test mit derselben auf Hostinger eingesetzten Health-Check-Unterprozess-Datei auf der bereinigten Testkopie. Vorher wurde nur der Dateicache dieser Kopie verworfen; `mincore` bestätigte **760.910 von 760.911 Seiten vor** und **0 von 760.911 Seiten nach** der Verdrängung. Die Live-Archivdatei und der systemweite Cache wurden nicht geleert.

- Ergebnis: **`ok`**, `sqlite_check_ok`, Exit-Code **0**.
- Laufzeit: **747,597 s** (12 min 28 s).
- Prozessmessung: `read_bytes=3.116.670.976`, `write_bytes=0`, `syscw=0`, `ru_inblock=6.087.248`, `ru_oublock=0`.
- Die Datei ist **3.116.691.456 Byte** groß; der Check las damit praktisch die gesamte Datei vom Datenträger.

Verglichen mit dem kalten Nachtlauf am Live-Archiv (**986,395 s**, `read_bytes=4.136.366.080`, `ru_inblock=8.078.840`) brauchte der Testlauf **24,2 % weniger Zeit** und verursachte **24,7 % weniger gelesene Bytes und Block-Eingaben**. Das passt nahezu genau zur um **24,7 %** kleineren Testdatei gegenüber der ursprünglichen Baseline. Das ist ein deutlicher Hinweis, dass die inhaltliche Kürzung den kalten SQLite-Check-Aufwand in ähnlicher Größenordnung senkt. Es ist weiterhin keine direkte Messung der von Hostinger abgerechneten IOPS; außerdem vergleichen wir eine Testkopie mit dem Live-Archiv, nicht zwei Läufe auf exakt derselben Datei.

### Technische Verdichtung und Spitzenrate

Nach dem inhaltlichen Kürzen wurde auf den Testkopien SQLite-`VACUUM` ausgeführt. Das hat die Datenbankdatei neu aufgebaut und nicht mehr benötigte/leere SQLite-Seiten entfernt. Es war **keine zusätzliche verlustfreie Kompression** wie ZIP oder Zstandard.

Die kalten Läufe lasen im Durchschnitt fast gleich schnell: **4,19 MB/s** beim Nachtlauf und **4,17 MB/s** beim Testlauf. Die kleinere Kopie senkte damit vor allem die insgesamt zu lesende Datenmenge und verkürzte den Lauf; eine niedrigere Spitzenrate ist nicht belegt. Es ist daher möglich, dass Hostingers IOPS-Anzeige während beider Läufe dieselbe Obergrenze erreicht, beim kleineren Archiv aber kürzer dort bleibt. Die vorliegenden Prozessmessungen erfassen Hostingers maximale IOPS-Spitze nicht.

### Möglicher schonenderer Start des Health-Checks

Der aktive externe Wrapper `/home/u367252972/bin/kiwi-retention-archive-health-cron.sh` startet derzeit direkt `/usr/bin/php /usr/local/bin/wp ... archive-health check --check=quick`. Der WP-CLI-Aufruf startet anschließend per `proc_open()` den PHP-Unterprozess, der `PRAGMA quick_check` ausführt.

Auf dem Live-Host ist `/usr/bin/ionice` vorhanden. Ein kleiner, nebenwirkungsfreier Prozess-Probeaufruf zeigte, dass `ionice -c 3` vom Shell-Prozess an einen gestarteten PHP-Unterprozess weitergegeben wird. Als gezielter Versuch könnte der Wrapper den WP-CLI-Aufruf mit dieser niedrigsten I/O-Priorität starten. Das betrifft diesen Cron-Prozess und seine Unterprozesse, nicht pauschal alle Prozesse des Hostinger-Accounts.

`ionice -c 3` ist jedoch **keine feste IOPS- oder MB/s-Grenze**. Es bittet den Linux-I/O-Scheduler, andere I/O-Arbeit vorzuziehen; ob das die Hostinger-Spitze senkt, muss ein regulärer Lauf mit hPanel-Beobachtung zeigen. Bei wenig konkurrierender I/O kann der Check weiterhin zügig lesen. Ein zugänglicher harter cgroup-I/O-Regler (`io.max`) wurde für diesen Account nicht gefunden. Der Produktions-Wrapper und das Plugin wurden bei dieser Prüfung nicht geändert.

### Testlauf mit `ionice` und Prüfung auf `io.max` (2026-10-01)

Der `ionice`-Lauf wurde mit dem Health-Check-Unterprozess auf der bereinigten Archivkopie ausgeführt. Dafür erhielt die Kopie vorübergehend einen vom Werkzeug akzeptierten Archivnamen als Hardlink. Der Hardlink, seine Sperrdatei und das private temporäre Messverzeichnis wurden nach Auswertung entfernt; die eigentliche Kopie blieb bei **3.116.691.456 Byte** und unverändertem Datei-Inode.

- `ionice -c 3` wurde vom WP-CLI-Prozess an den PHP-Unterprozess weitergegeben; beide meldeten die I/O-Klasse `idle`.
- Der Messdatensatz erfasste **737,654 Sekunden** Laufzeit, **3.116.765.184 Byte** `read_bytes`, **6.087.432** `ru_inblock` sowie keine Schreibzugriffe.
- Vergleich zum vorherigen kalten Lauf derselben Kopie ohne `ionice`: **747,597 Sekunden**, **3.116.670.976 Byte** `read_bytes` und **6.087.248** `ru_inblock`. Laufzeit und I/O-Mengen sind praktisch gleich; dieser Test zeigt keine erkennbare Verringerung. Die hPanel-Spitze selbst wurde nicht erfasst, daher lässt sich damit nicht ausschließen, dass die Hostinger-Anzeige anders reagiert.
- Der WP-CLI-Aufrufer meldete nach **600 Sekunden** `health_child_timeout`; gemäß Schutzverhalten blieb sein reiner Lese-Unterprozess aktiv und schrieb den Messdatensatz nach Abschluss bei **737,654 Sekunden**. Der Aufrufer gab deshalb für diesen Lauf kein abschließendes `ok` aus.

Für `io.max` fand sich weder `/sys/fs/cgroup/io.max` noch ein sichtbarer älterer `blkio`-Drosselungsregler; das cgroup-Steuerdateisystem war in dieser SSH-Umgebung nicht zugänglich. Deshalb gab es keinen zweiten Lauf mit `io.max`. Es wurde kein möglicher accountweiter Grenzwert gesetzt. Das ist ein Verfügbarkeitsbefund für diesen Zugang, kein Nachweis, dass Hostinger die Begrenzung intern generell nicht anbieten kann.

### hPanel-I/O-Statistik (vom Nutzer geteilt, 2026-10-01)

Der Screenshot zeigt einen verfügbaren IOPS-Grenzwert von **1.024** und drei sichtbare Spitzen bis an diese Grenze. Beim I/O-Durchsatz werden **1.280 KB/s** Durchschnitt und **20.480 KB/s** verfügbar angezeigt; eine sichtbare Spitze reicht ebenfalls ungefähr an den Durchsatz-Grenzwert. Das belegt, dass die dargestellte Hosting-Auslastung die IOPS-Grenze erreicht. Die Grafik allein ordnet die einzelnen Spitzen keinem Prozess zu.
