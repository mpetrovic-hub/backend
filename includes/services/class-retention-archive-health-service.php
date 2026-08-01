<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Retention_Archive_Health_Service
{
    private $controller;

    public function __construct(
        ?Kiwi_Config $config = null,
        ?Kiwi_Retention_Sqlite_Archive_Service $archive_service = null,
        ?Kiwi_Retention_Archive_Lock $lock_service = null,
        ?Kiwi_Operational_Event_Service $operational_event_service = null,
        ?callable $clock = null,
        ?callable $check_runner = null,
        string $child_script_path = '',
        ?Kiwi_Retention_Cleanup_Run_Repository $run_repository = null,
        ?Kiwi_Retention_Archive_Health_Controller $controller = null
    ) {
        if ($controller instanceof Kiwi_Retention_Archive_Health_Controller) {
            $this->controller = $controller;

            return;
        }

        $config = $config instanceof Kiwi_Config ? $config : new Kiwi_Config();
        $archive_service = $archive_service instanceof Kiwi_Retention_Sqlite_Archive_Service
            ? $archive_service
            : new Kiwi_Retention_Sqlite_Archive_Service($config);
        $lock_service = $lock_service instanceof Kiwi_Retention_Archive_Lock
            ? $lock_service
            : new Kiwi_Retention_Archive_Lock();
        $operational_event_service = $operational_event_service instanceof Kiwi_Operational_Event_Service
            ? $operational_event_service
            : new Kiwi_Operational_Event_Service();
        $run_repository = $run_repository instanceof Kiwi_Retention_Cleanup_Run_Repository
            ? $run_repository
            : new Kiwi_Retention_Cleanup_Run_Repository();
        $supervisor = new Kiwi_Retention_Archive_Check_Supervisor(
            $config,
            $check_runner,
            $child_script_path
        );
        $safety_gate = new Kiwi_Retention_Corruption_Safety_Gate_Coordinator(
            $lock_service,
            $operational_event_service,
            $run_repository
        );
        $this->controller = new Kiwi_Retention_Archive_Health_Controller(
            $archive_service,
            $supervisor,
            $safety_gate,
            $operational_event_service,
            $run_repository,
            $clock
        );
    }

    public function check(string $check): array
    {
        return $this->controller->check($check);
    }

    public function diagnose(string $archive_name, string $check): array
    {
        return $this->controller->diagnose($archive_name, $check);
    }

    public function unblock(
        string $archive_name,
        string $replacement_archive_name = '',
        bool $confirmed = false
    ): array {
        return $this->controller->unblock(
            $archive_name,
            $replacement_archive_name,
            $confirmed
        );
    }

    public function record_scheduled_bootstrap_failure(string $reason_code): array
    {
        return $this->controller->bootstrap_failure($reason_code);
    }
}
