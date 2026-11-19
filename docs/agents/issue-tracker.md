# Issue tracker: GitHub

Wayfinder-Maps, Specs und Tickets liegen als Issues in `mpetrovic-hub/backend`.
GitHub-Operationen laufen mit `gh` aus diesem Repo. Jedes Issue dieses
Matt-Pocock-Flows wird dem Projekt
[@mpetrovic-hub's Odin (Matt Pocock Flow)](https://github.com/users/mpetrovic-hub/projects/3)
zugeordnet: `gh project item-add 3 --owner mpetrovic-hub --url <issue-url>`.
Die Labels `wayfinder:*` und `ready-for-agent` werden nur für Issues dieses
Flows in Projekt #3 verwendet. GitHub-Labels selbst gelten im ganzen Repo.

## Grundoperationen

- Erstellen: `gh issue create --title "..." --body-file <datei>`
- Lesen: `gh issue view <nummer> --comments`
- Suchen: `gh issue list --state open` mit passenden Filtern
- Kommentieren: `gh issue comment <nummer> --body-file <datei>`
- Labels: `gh issue edit <nummer> --add-label "..."` bzw. `--remove-label`
- Schließen: `gh issue close <nummer>`; dabei gilt die Abnahmeregel in `AGENTS.md`.

**PRs as a request surface: no.**

"Publish to the issue tracker" heißt: GitHub Issue anlegen und Projekt #3
zuordnen. "Fetch the relevant ticket" heißt: das betreffende GitHub Issue lesen.

## Wayfinder

- Die Map ist ein Issue mit `wayfinder:map`; Entscheidungen sind Sub-Issues
  mit `wayfinder:research`, `wayfinder:prototype`, `wayfinder:grilling` oder
  `wayfinder:task`.
- Gemäß der bereits vereinbarten Repo-Regel tragen Map und Entscheidungs-Issues
  zusätzlich `codex-automation-skip`. Sie erhalten kein `ready-for-agent`.
- Sub-Issues werden über die GitHub-API verknüpft; falls das nicht verfügbar
  ist, über eine Aufgabenliste in der Map und `Part of #<map>` im Kind-Issue.
- Blockaden verwenden native GitHub-Issue-Abhängigkeiten; falls das nicht
  verfügbar ist, `Blocked by: #<nummer>` im Issue.
- Als nächstes wird das erste offene, nicht zugewiesene Kind ohne offene
  Blockade bearbeitet. Der Bearbeiter weist es sich zu.
- Das Ergebnis kommt als Kommentar ins Kind-Issue und als Verweis in die Map.
  Schließen erfolgt nach der Abnahmeregel in `AGENTS.md`.

`to-spec` und `to-tickets` verwenden `ready-for-agent`. Das bestehende
Backend-Label `0 - codex-ready` wird dadurch nicht gesetzt.
