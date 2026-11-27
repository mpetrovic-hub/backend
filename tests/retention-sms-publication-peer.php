<?php

require_once ABSPATH . 'tools/database/class-retention-sms-archive-publication-service.php';
$lock = new class extends Kiwi_Retention_Archive_Lock {
    public $pause = false;
    public function acquire_for_archive(string $path): array
    {
        if ($this->pause) {
            $this->pause = false;
            echo "source_locked\n";
            flush();
            if (trim(fgets(STDIN)) !== 'continue') { throw new RuntimeException('test_peer_cancelled'); }
        }
        return parent::acquire_for_archive($path);
    }
};
$lock->pause = !empty($request['pause']);
$result = (new Kiwi_Retention_Sms_Archive_Publication_Service(locks: $lock))->publish($request['prepared'], $request['destination']);
echo json_encode($result + ['pid'=>getmypid(), 'connection'=>(int)$wpdb->pdo->query('SELECT CONNECTION_ID()')->fetchColumn()]), "\n";
