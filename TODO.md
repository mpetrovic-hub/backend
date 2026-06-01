# TODO

## 1. Config-Page für Runtime-/Tracking-Switches

### Ziel

Eine Admin-/Tool-Seite planen und später bauen, auf der wichtige Runtime-Optionen sichtbar und steuerbar sind.

### Hintergrund

Mehrere produktionsrelevante Optionen liegen aktuell nur als `KIWI_*` Konstanten bzw. Service-Arrays im Code oder `wp-config.php`. Der UA-Tracking-Switch ist inzwischen gebaut und deployed; das offene Thema ist jetzt die spätere visuelle Steuerung und Übersicht dieser Optionen.

Eine Config-Page soll helfen, operative Switches kontrolliert zu setzen, den aktuellen Zustand zu sehen und Fehlkonfigurationen schneller zu erkennen.

### Erwartetes Verhalten

- Die Config-Page zeigt wichtige Optionen gruppiert nach Bereich.
- Werte können je nach Sicherheitsklasse entweder editiert, nur angezeigt oder nur als "configured/not configured" dargestellt werden.
- Secrets werden niemals im Klartext angezeigt.
- Bestehende Konstanten bleiben weiterhin gültig und haben Vorrang, solange keine saubere Persistenz-/Override-Strategie definiert ist.
- Die UI bereitet spätere Steuerung vor, ohne sensible Provider-Konfiguration versehentlich zu exponieren.

Mögliche Config-Kandidaten aus der aktuellen Codebase:

#### Landing / Tracking / Analytics

- `KIWI_LANDING_UA_TRACKING_MODE` (`disabled`, `onclick`, `onload`)
- `KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED` (Legacy-Kompatibilität)
- `KIWI_CLIENT_IP_RESOLUTION_DEBUG` (temporaer default-on fuer Trusted-Proxy-Rollout; spaeter wieder default-off/entfernen)
- `KIWI_DEVICE_MODEL_BRAND_HARVEST_MIN_DAILY_SESSIONS` (Daily-Harvester-Schwelle fuer observed `(unknown)` Device-Model-Keys)
- `KIWI_SMS_BODY_VARIANT_EXPERIMENT_ENABLED`
- `KIWI_SMS_BODY_VARIANT_EXPERIMENT_COUNTRIES`
- `KIWI_LANDING_FUNNEL_TKZONE_SUMMARY_PIDS` (editable PID-Allowlist fuer Tkzone-Summary; Default `106`)
- `KIWI_LANDING_PAGES_FILESYSTEM_ENABLED`
- `KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED`
- `KIWI_LANDING_PAGES_ROOT`
- `KIWI_LANDING_PAGES` (Legacy, nur anzeigen/retirement status)

#### Click Attribution / Affiliate Postbacks

- `KIWI_CLICK_ATTRIBUTION_COOKIE_NAME`
- `KIWI_CLICK_ATTRIBUTION_CLICK_ID_KEYS`
- `KIWI_CLICK_ATTRIBUTION_TTL_SECONDS`
- `KIWI_CLICK_ATTRIBUTION_CLEANUP_LIMIT`
- `KIWI_AFFILIATE_POSTBACK_URL_TEMPLATE`
- `KIWI_AFFILIATE_POSTBACK_SECRET` (secret, nicht im Klartext anzeigen)
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_PARAMETER`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_ALGORITHM`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_BASE`
- `KIWI_AFFILIATE_POSTBACK_TIMEOUT_SECONDS`
- `KIWI_AFFILIATE_POSTBACK_RESPONSE_BODY_LIMIT`

#### Premium SMS Fraud / Engagement Guardrails

- `KIWI_PREMIUM_SMS_FRAUD_THRESHOLD_1H`
- `KIWI_PREMIUM_SMS_FRAUD_THRESHOLD_24H`
- `KIWI_PREMIUM_SMS_FRAUD_MO_ENGAGEMENT_MODE` (`observe`, `block`)
- `KIWI_PREMIUM_SMS_FRAUD_MO_REQUIRE_PAGE_LOADED`
- `KIWI_PREMIUM_SMS_FRAUD_MO_REQUIRE_CTA_CLICK`
- `KIWI_PREMIUM_SMS_FRAUD_MO_MIN_SECONDS_AFTER_LOAD`

#### Provider / Integration Operations

- `KIWI_NTH_SERVICES` (komplexes Service-Mapping, eher anzeigen/validieren als direkt editieren)
- `KIWI_NTH_SUBMIT_TIMEOUT`
- `KIWI_NTH_CALLBACK_LOGGING_ENABLED`
- `KIWI_NTH_CALLBACK_PAYLOAD_LOGGING_ENABLED`
- `KIWI_DIMOCO_SERVICES` (komplexes Service-Mapping)
- `KIWI_DIMOCO_BASE_URL`
- `KIWI_DIMOCO_CALLBACK_URL`
- `KIWI_DIMOCO_DEBUG`
- `KIWI_LILY_BASE_URL`
- `KIWI_LILY_USERNAME` (secret-adjacent, nicht unnötig offen anzeigen)
- `KIWI_LILY_PASSWORD` (secret, nicht im Klartext anzeigen)
- `KIWI_OPERATOR_LOOKUP_ROUTES`

#### Global / Tooling

- `KIWI_DEBUG`
- `KIWI_DEFAULT_COUNTRY`
- `KIWI_HTTP_TIMEOUT`
- `KIWI_HLR_BATCH_LIMIT`
- `KIWI_HLR_REQUEST_DELAY_MS`
- `KIWI_HLR_RETRY_DELAY_SECONDS`
- `KIWI_FRONTEND_AUTH_USERNAME`
- `KIWI_FRONTEND_AUTH_PASSWORD_HASH` (secret/hash, nicht im Klartext anzeigen)

### Akzeptanzkriterien

- [ ] Config-Kandidaten sind in sinnvolle UI-Gruppen aufgeteilt.
- [ ] Jede Option hat eine Sicherheitsklasse: editable, read-only, configured-only, secret.
- [ ] Konstanten aus `wp-config.php` werden als "locked by constant" erkennbar.
- [ ] Secrets werden maskiert oder nur als "configured/not configured" angezeigt.
- [ ] `KIWI_LANDING_UA_TRACKING_MODE` ist als erstes editierbares Beispiel vorgesehen.
- [ ] Komplexe Service-Arrays wie `KIWI_NTH_SERVICES`, `KIWI_DIMOCO_SERVICES`, `KIWI_OPERATOR_LOOKUP_ROUTES` werden in v1 nicht frei editiert, sondern höchstens validiert/angezeigt.
- [ ] Es gibt eine klare Persistenzstrategie, z.B. WordPress Options mit Konstanten-Override-Priorität.

### Nicht-Ziele

- Keine sofortige Implementierung der Config-Page.
- Kein Speichern oder Anzeigen von Secrets im Klartext.
- Kein freies Editieren komplexer Provider-Service-Mappings in v1.
- Keine Änderung bestehender Runtime-Defaults ohne separates Issue.

### Hinweise für Codex

Startpunkt ist `includes/core/class-config.php`. Viele Werte sind dort bereits gekapselt und eignen sich als Quelle für Labels, Defaults und Validierung.

Für die spätere Umsetzung sollte zuerst ein kleines Config-Metadatenmodell geplant werden:

- key / label / description
- type (`bool`, `enum`, `int`, `string`, `secret`, `array-readonly`)
- default
- allowed values
- source (`constant`, `option`, `default`)
- editable

### Manuelle Tests

- [ ] Config-Page zeigt `KIWI_LANDING_UA_TRACKING_MODE` mit aktuellem Wert.
- [ ] Per Konstante gesetzte Werte erscheinen als locked/read-only.
- [ ] Secrets erscheinen nicht im Klartext.
- [ ] Geänderte editierbare Optionen wirken nach Save in `Kiwi_Config`.


## 2. Refactor Tabellenbenennung `wp_kiwi_premium_sms_landing_engagements`

### Ziel

Die unglückliche Benennung `wp_kiwi_premium_sms_landing_engagements` perspektivisch generischer machen.

### Hintergrund

Die Tabelle enthält inzwischen generische Landing-Engagement-Daten wie Page Load, CTA Clicks, Traffic Source, Session-Kontext und künftig UA Device Context. Das ist nicht mehr Premium-SMS-spezifisch.

### Erwartetes Verhalten

Seit der CTA-Step-Erweiterung werden neben den generischen Legacy-Spalten `first_cta_click_at`, `last_cta_click_at` und `cta_click_count` auch step-spezifische CTA1/CTA2/CTA3-Spalten gepflegt. Nach Rollout der step-spezifischen Summary-/Statistics-Auswertung muss separat entschieden werden, ob die Legacy-CTA-Spalten dauerhaft als KompatibilitÃ¤tsschicht bleiben oder kontrolliert entfernt werden.

Langfristig soll die Tabelle bzw. Repository-Benennung generischer werden, z.B. Richtung `landing_engagements`.

### Akzeptanzkriterien

- [ ] Migrationsstrategie ohne Datenverlust skizziert
- [ ] Backward Compatibility für bestehende Queries/Views bedacht
- [ ] Repositories/Shortcodes/Views können schrittweise umgestellt werden
- [ ] Keine harte Kopplung an Premium-SMS-Flows bleibt übrig

### Nicht-Ziele

Kein sofortiger Rename ohne saubere Migration.

### Hinweise für Codex

Erst Analyse/Plan erstellen. Mögliche Strategie: neue generische View/Alias-Schicht vor echtem Table-Rename.

### Manuelle Tests

- [ ] Bestehende Fraud-/Statistics-/Landing-Reports nach Refactor prüfen

## 3. Temporaere Debug-/Logging-Experimente wieder entfernen

### Ziel

Kurzfristige Diagnose- und Logging-Experimente sammeln, damit sie nach der jeweiligen Prod-Diagnose wieder entfernt oder zurueckgedreht werden.

### Aktuelle Experimente

- [x] Landing-Funnel-Daily-Summary SQL-Dump-Logging aus PR #42 wieder entschaerft.
  - Erledigt mit Issue #43: der Refresh laeuft intern pro `metric_date` und normales Erfolgs-/Fehlerlogging bleibt kurz.
  - Keine dauerhaften grossen Summary-SQL-Dumps sind Teil des normalen Refresh-Pfads.
- [ ] `KIWI_CLIENT_IP_RESOLUTION_DEBUG` nach Trusted-Proxy-Rollout wieder default-off setzen oder entfernen.
  - Eingefuehrt/erweitert in PR #61 fuer Issue #55.
  - Zweck: kurz pruefen, ob `X-Forwarded-For`, `Forwarded`, `X-Real-IP` oder andere Client-IP-Header bei PHP ankommen und ob der direkte Peer trusted ist.
  - Debug-Kontext bleibt datensparsam: Header-Namen, Candidate-Count, Trust-/Resolution-Reason; keine rohen Headerwerte oder Kandidaten-IPs.
  - Nach erfolgreicher Prod-Pruefung wieder raus aus dem Default-Pfad.

### Regel

Neue SQL-/Header-/Payload-Diagnosen nur temporaer, hinter explizitem Schalter und ohne rohe personenbezogene oder geheime Werte dauerhaft zu speichern.

