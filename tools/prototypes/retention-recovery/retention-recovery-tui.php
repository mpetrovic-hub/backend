<?php

declare(strict_types=1);

require __DIR__ . '/recovery-model.php';

/**
 * PROTOTYPE ONLY — no production connection, no persistence.
 * Run: php tools/prototypes/retention-recovery/retention-recovery-tui.php
 */

$state = Kiwi_Retention_Recovery_Prototype_Model::initial_state();

while (true) {
    render($state);
    $input = fgets(STDIN);

    if ($input === false) {
        break;
    }

    $key = strtolower(trim($input));

    if ($key === 'q') {
        echo "\nPrototype beendet. Es wurde nichts gespeichert oder verbunden.\n";
        break;
    }

    if ($key === 't') {
        $state = Kiwi_Retention_Recovery_Prototype_Model::initial_state();
        continue;
    }

    $actions = [
        'p' => 'preflight',
        'r' => 'reconcile',
        'd' => 'delete_evidence',
        'a' => 'archive_remaining',
        'f' => 'finish',
        'h' => 'health_passed',
        'e' => 'health_failed',
        'x' => 'simulate_mismatch',
    ];

    $state = Kiwi_Retention_Recovery_Prototype_Model::dispatch($state, $actions[$key] ?? 'unknown');
}

function render(array $state): void
{
    echo "\033[2J\033[H";
    echo "\033[1mPROTOTYPE — Retention-Recovery-Zustandsmodell\033[0m\n";
    echo "\033[2mNur lokaler Speicher. Keine Production-Verbindung.\033[0m\n\n";

    line('Run', $state['run_id']);
    line('Status', $state['run_status'] . ' / ' . $state['worker_phase']);
    line('Vorabpruefung', $state['preflight']);
    line('Archivgesundheit', $state['archive_health']);
    line('Operational Event', $state['operational_event']);
    echo "\n\033[1mZaehlwerte\033[0m\n";
    line('Quellzeilen verbleibend', number_format((int) $state['source_rows_remaining'], 0, ',', '.'));
    line('Archiviert im Audit', number_format((int) $state['archived_rows_audited'], 0, ',', '.'));
    line('Geloescht im Audit', number_format((int) $state['deleted_rows_audited'], 0, ',', '.'));
    line('Archiv-Pfad im Audit', $state['archive_db_path']);
    line('Letzter Archiv-PK', (string) $state['archive_last_primary_key']);
    line('Letzter Delete-PK', (string) $state['delete_last_primary_key']);
    echo "\n\033[1mBestehender Archivbeleg\033[0m\n";
    line('Belegte Zeilen', number_format((int) $state['evidence']['expected_rows'], 0, ',', '.'));
    line('Noch passende Quellzeilen', number_format((int) $state['evidence']['source_rows_matching_evidence'], 0, ',', '.'));
    line('PK-Bereich', $state['evidence']['first_primary_key'] . ' bis ' . $state['evidence']['last_primary_key']);
    line('SQLite-Batch-Status', $state['evidence']['archive_batch_status']);
    echo "\n\033[1mLetzte Entscheidungen\033[0m\n";

    foreach ($state['history'] as $entry) {
        echo "- {$entry}\n";
    }

    echo "\n\033[1mTasten\033[0m\n";
    echo "[p] Vorab pruefen  [r] Audit abgleichen  [d] belegte 50.000 loeschen\n";
    echo "[a] Rest archivieren  [f] fertigstellen  [h] externer Check OK  [e] externer Check Fehler\n";
    echo "[x] fehlenden Beleg simulieren  [t] zuruecksetzen  [q] beenden\n> ";
}

function line(string $label, string $value): void
{
    echo "\033[1m{$label}:\033[0m {$value}\n";
}
