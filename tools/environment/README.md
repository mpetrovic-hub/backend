# GitHub-Abgleich der Cloud-Arbeitskopie

`sync-main.sh` ruft den aktuellen Stand von `origin/main` ab. Mit `--update` übernimmt es ihn automatisch, wenn die Arbeitskopie sauber ist und ausschließlich ein Fast-forward nötig ist. Es installiert keinen Hintergrundprozess und verändert keine Cloud-Plattformkonfiguration.

```bash
# GitHub-Stand abrufen und Unterschiede melden; ausgecheckte Dateien erhalten.
bash tools/environment/sync-main.sh --check

# Für den Beginn einer neuen Arbeitsrunde: einen sicheren Fast-forward übernehmen.
bash tools/environment/sync-main.sh --update
```

Voraussetzungen: Bash, Git, `flock`, `timeout` und funktionierender lesender Zugriff auf das konfigurierte Git-Remote. Das Skript verwendet die vorhandene Git-Authentifizierung und startet keine interaktive Anmeldung. Für lokale Funktionsprüfungen unterstützt es zusätzlich `--repo PATH`.

## Verhalten

- Ein Fetch aktualisiert zunächst nur den gespeicherten GitHub-Stand; die Arbeitsdateien bleiben erhalten.
- Sind lokaler Commit und GitHub-Commit gleich, wird `status=current` gemeldet. `working_tree_dirty=true` zeigt zusätzlich an, dass lokale Dateien trotzdem abweichen.
- Bei `--check` werden Unterschiede nur gemeldet.
- Bei `--update` werden neue GitHub-Commits ausschließlich per `git merge --ff-only` übernommen. Der vorhandene Arbeitsbranch bleibt bestehen.
- Lokale Änderungen, untracked Dateien, eigene Commits, ein detached HEAD, laufende Git-Operationen oder zwischenzeitliche Änderungen am Checkout blockieren eine notwendige Aktualisierung.
- Es gibt kein automatisches Stash, Commit, Reset, Rebase, Konfliktlösen oder Verwerfen lokaler Arbeiten.
- Gleichzeitige Aufrufe werden über einen Lock im Git-Verzeichnis verhindert. Der Fetch hat ein Zeitlimit von 45 Sekunden.

Exit-Codes: `0` = geprüft/aktualisiert, `1` = technischer Fehler, `2` = Aktualisierung zum Schutz der Arbeitskopie blockiert. Bei `--check` bedeutet `status=differs` ausdrücklich, dass nur geprüft wurde.

## Automatische Auslösung

Empfohlen ist der Aufruf **vor jeder neuen Arbeitsrunde**. Während einer laufenden Reparatur, Implementierung oder Prüfung bleibt der Code-Stand stabil. Ein erneuter `--check` vor der Übergabe dokumentiert, ob GitHub inzwischen weitergelaufen ist; er verändert den getesteten Code nicht.

Die folgende Regel wurde am 2026-10-01 ausdrücklich vom Nutzer freigegeben und in die Repository-`AGENTS.md` eingetragen:

```markdown
### Cloud checkout freshness

At the start of each new coding task, before detailed code reads or planning,
run `bash tools/environment/sync-main.sh --update`.
Read the reported base commit, GitHub main commit, and status.
If the update is blocked or fails, preserve local work and report the reason;
do not discard, stash, commit, rebase, or merge local work automatically.
Keep the checkout stable while the task is in progress.
Before final validation or handoff, run the script with `--check` and record the
tested commit; report if GitHub main has advanced without changing the tested code.
```

Diese Regel ist ein agentenseitig ausgeführter Ablauf, kein nativer Lifecycle-Hook. Für neue Checkouts müssen Skript und Regel außerdem im ausgewählten Repository-Stand vorhanden sein. Die lokale Aktivierung ist erfolgt; die Übernahme nach GitHub `main` ist ein separater Schritt.

Alternativ kann ein vom jeweiligen Cloud-Produkt unterstützter Start-/Fortsetzen-Hook denselben `--update`-Aufruf ausführen. Ob ein solcher Hook konfigurierbar ist, ist mit den hier verfügbaren Werkzeugen nicht festgestellt: Das Cloud-Werkzeug bietet ausschließlich eine Statusabfrage. Die gefundene `.codex/environments/environment.toml` ist automatisch erzeugt, enthält ein leeres Setup-Skript und ist ausdrücklich nicht manuell zu bearbeiten. Sie wurde nicht geändert oder als Cloud-Lifecycle-Hook interpretiert.

Codex ist in dieser Arbeitskopie nun angewiesen, den Abgleich zu Beginn einer neuen Coding-Aufgabe auszuführen. Ein periodischer Hintergrund-Updater wurde nicht eingerichtet.

## Ausgeführte Prüfung

Am 2026-10-01 bestanden neun Funktionsprüfungen gegen ausschließlich lokale, temporäre Git-Repositories:

- sauberer Fast-forward bei Erhaltung des Arbeitsbranches;
- Fetch im Prüfmodus ohne Änderung der Arbeitsdateien;
- Erhaltung bearbeiteter Dateien;
- Erhaltung untracked Prototyp-Dateien;
- Erhaltung eigener Commits bei abweichendem GitHub-Stand;
- Schutz eines detached HEAD;
- Schutz bei einem fehlgeschlagenen Fetch;
- aktuelle Basis mit unverändert erhaltenen lokalen Änderungen;
- Schutz einer laufenden Git-Operation.

```bash
python3 tests/environment-sync-tests.py
bash -n tools/environment/sync-main.sh
```

Der direkte GitHub-Zugriff und ein echter `--check` in `/workspace/backend` wurden ebenfalls ausgeführt. GitHub `main` und die lokale Basis zeigten beide auf `d8d2382cfe1010b53ac203adcfeb31327da0ce6a`; vorhandene Prototyp-Arbeiten wurden erhalten. Es gab keine GitHub-Schreiboperation.
