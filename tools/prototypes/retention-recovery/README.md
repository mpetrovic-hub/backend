# PROTOTYPE — Retention-Recovery-Zustandsmodell

Diese Wegwerf-Prototyp beantwortet eine einzige Frage:

> Kann der haengende Retention-Lauf kontrolliert fertiggestellt werden, wenn 50.000 Zeilen schon im Archiv belegt sind, aber noch nicht als Fortschritt oder Loeschung im Audit stehen?

Er verwendet ausschliesslich die anonymen Produktions-Fakten des Vorfalls vom 2026-07-24:

- 66.418 alte Quellzeilen;
- 50.000 belegte Archiv-Primary-Keys (808247 bis 858246);
- keine davon bisher geloescht;
- 16.418 danach noch zu archivierende Zeilen.

Es gibt **keine** Datenbank-, SSH- oder WordPress-Verbindung und keine Persistenz. Das Programm lebt nur im Speicher. Der Happy Path nimmt an, dass die Vorabpruefung die 50.000 Archivbelege gegen die Quelle erfolgreich abgeglichen hat; `x` zeigt den verweigerten Fall bei einer Abweichung.

## Start

```powershell
php tools/prototypes/retention-recovery/retention-recovery-tui.php
```

Der sichere Happy Path ist: `p` → `r` → `d` → `a` → `f`.

Mit `x` kann man absichtlich einen fehlenden Archivbeleg simulieren. Dann verweigert das Modell die Recovery.

## Ergebnis festhalten

Noch offen: Nach gemeinsamer Bewertung den Prototyp loeschen und nur die bestaetigte Zustandsregel in Issue #110 bzw. die Recovery-Planung uebernehmen.
