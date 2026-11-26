<?php

require_once ABSPATH . 'includes/repositories/class-landing-kpi-summary-repository.php';
require_once ABSPATH . 'includes/repositories/class-landing-handoff-event-repository.php';
require_once ABSPATH . 'includes/services/class-landing-kpi-service.php';
require_once ABSPATH . 'includes/services/class-device-context-normalizer.php';
require_once ABSPATH . 'includes/http/class-landing-kpi-rest-routes.php';

class WP_REST_Request {
    public function __construct(private array $params) {}
    public function get_params(): array { return $this->params; }
}
class WP_REST_Response {
    public function __construct(public array $data, public int $status) {}
}
$landingConfig = new class extends Kiwi_Config {
    public function get_landing_page(string $key): ?array {
        return $key === 'lp185' ? ['service_key' => 'nth_fr_one_off', 'provider' => 'nth', 'flow' => 'one-off'] : null;
    }
};
$landing = new Kiwi_Landing_Kpi_Summary_Repository();
$handoff = new Kiwi_Landing_Handoff_Event_Repository();
$landing->create_table();
$handoff->create_table();
$routes = new Kiwi_Landing_Kpi_Rest_Routes($landingConfig, new Kiwi_Landing_Kpi_Service($landingConfig, $landing),
    null, null, $handoff, $variants);
$send = static function (string $event, string $suffix) use ($routes): array {
    $params = ['landing_key' => 'lp185', 'session_token' => 'session185'];
    $params += $event === 'cta1' ? ['step' => 'cta1'] : ['event_type' => 'sms_' . $event, 'handoff_id' => 'handoff-' . $suffix];
    return $routes->handle_event(new WP_REST_Request($params))->data;
};
$eventsToCheck = ['cta1', 'handoff_attempted', 'handoff_hidden', 'handoff_no_hide', 'handoff_returned'];
$new = $variants->insert_if_new($group + ['transaction_id' => 'txn_rest_original_185', 'visible_token' => 'rest185'])['row'];
foreach ($eventsToCheck as $event) {
    $first = $send($event, 'active');
    $retry = $send($event, 'active');
    et_check($first['sms_body_variant_recorded'] && !$retry['sms_body_variant_recorded']
        && (int) $variants->get_summary_rows()[0][$event] === 1, 'Only the first live REST event contributes to ' . $event);
}
$archive->archive_snapshot_batch($source, [$variants->find_assignment_by_id((int) $new['id'])],
    '2026-10-13 00:00:00', '185-rest', $path);
$wpdb->query('DELETE FROM ' . $source['source_table'] . ' WHERE id=' . (int) $new['id']);
foreach ($eventsToCheck as $event) {
    $response = $send($event, 'archived');
    et_check($response['success'] && !$response['sms_body_variant_recorded']
        && (int) $variants->get_summary_rows()[0][$event] === 1, 'The independent REST event remains accepted after archival for ' . $event);
}
et_check((int) $wpdb->get_var('SELECT cta1 FROM t72_kiwi_landing_kpi_summary') === 3,
    'The active clicks and the click after archival persist in the general landing KPI summary');
et_check((int) $wpdb->get_var("SELECT COUNT(*) FROM t72_kiwi_landing_handoff_events WHERE handoff_id='handoff-archived'") === 4,
    'All four handoff events after archival persist in the general event table');
