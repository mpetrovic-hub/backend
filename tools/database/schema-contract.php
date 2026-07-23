<?php

if (!defined('ABSPATH')) {
    exit;
}

return [
    'kiwi_click_attributions' => [
        'columns' => ['id', 'created_at', 'updated_at', 'expires_at', 'tracking_token', 'transaction_id', 'click_id', 'provider_key', 'landing_page_key', 'flow_key', 'service_key', 'pid', 'tksource', 'tkzone', 'session_ref', 'transaction_ref', 'message_ref', 'external_ref', 'sale_reference', 'conversion_status', 'conversion_confirmed_at', 'postback_sent_at', 'postback_response_code', 'postback_response_body', 'postback_last_error', 'postback_attempts', 'last_postback_attempt_at', 'raw_context'],
        'indexes' => ['PRIMARY', 'tracking_token', 'transaction_id', 'click_id', 'provider_key', 'service_key', 'pid', 'tksource', 'tkzone', 'session_ref', 'transaction_ref', 'message_ref', 'external_ref', 'sale_reference', 'expires_at'],
    ],
    'kiwi_device_model_brand_map' => [
        'columns' => ['id', 'model_key', 'brand', 'source', 'notes', 'created_at', 'updated_at'],
        'indexes' => ['PRIMARY', 'model_key', 'brand'],
    ],
    'kiwi_dimoco_blacklist_callbacks' => [
        'columns' => ['id', 'created_at', 'action', 'action_status', 'action_status_text', 'action_code', 'detail', 'detail_psp', 'request_id', 'reference', 'transaction_id', 'order_id', 'msisdn', 'operator', 'blocklist_scope', 'service_key', 'service_label', 'raw_payload'],
        'indexes' => ['PRIMARY', 'request_id', 'reference', 'transaction_id', 'order_id', 'msisdn', 'operator', 'blocklist_scope', 'service_key', 'created_at'],
    ],
    'kiwi_dimoco_operator_lookup_callbacks' => [
        'columns' => ['id', 'created_at', 'action', 'action_status', 'action_status_text', 'action_code', 'detail', 'detail_psp', 'request_id', 'reference', 'transaction_id', 'order_id', 'msisdn', 'operator', 'service_key', 'service_label', 'raw_payload'],
        'indexes' => ['PRIMARY', 'request_id', 'reference', 'transaction_id', 'order_id', 'msisdn', 'operator', 'service_key', 'created_at'],
    ],
    'kiwi_dimoco_refund_callbacks' => [
        'columns' => ['id', 'created_at', 'action', 'action_status', 'action_status_text', 'action_code', 'detail', 'detail_psp', 'request_id', 'reference', 'transaction_id', 'order_id', 'service_key', 'service_label', 'raw_payload'],
        'indexes' => ['PRIMARY', 'request_id', 'reference', 'transaction_id', 'order_id', 'service_key', 'created_at'],
    ],
    'kiwi_landing_funnel_daily_summary' => [
        'columns' => ['id', 'metric_date', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'country', 'pid', 'tksource', 'device_brand', 'os', 'os_version', 'browser', 'client_ip_version', 'client_ip_prefix', 'dimension_hash', 'sessions', 'page_loaded_sessions', 'cta1_sessions', 'cta1_click_events', 'cta2_sessions', 'cta2_click_events', 'cta3_sessions', 'cta3_click_events', 'handoff_attempts', 'handoff_successes', 'handoff_fails', 'handoff_rate_pct', 'min_hidden_seconds', 'max_hidden_seconds', 'sales', 'sales_amount_minor', 'created_at', 'updated_at'],
        'indexes' => ['PRIMARY', 'metric_date_dimension_hash', 'metric_date', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'country', 'pid', 'tksource', 'device_brand', 'os', 'os_version', 'browser', 'client_ip_version', 'client_ip_prefix', 'dimension_hash'],
        'legacy_columns' => ['tkzone', 'median_hidden_seconds', 'android_version'],
    ],
    'kiwi_landing_funnel_daily_tkzone_summary' => [
        'columns' => ['id', 'metric_date', 'provider_key', 'flow_key', 'country', 'service_key', 'landing_key', 'tksource', 'tkzone', 'dimension_hash', 'pid_set_hash', 'sessions', 'page_loaded_sessions', 'cta1_sessions', 'cta1_click_events', 'cta2_sessions', 'cta2_click_events', 'cta3_sessions', 'cta3_click_events', 'handoff_attempts', 'handoff_successes', 'handoff_fails', 'handoff_rate_pct', 'sales', 'sales_amount_minor', 'created_at', 'updated_at'],
        'indexes' => ['PRIMARY', 'metric_date_dimension_hash', 'metric_date', 'provider_key', 'flow_key', 'country', 'service_key', 'landing_key', 'tksource', 'tkzone', 'pid_set_hash', 'metric_date_pid_set_hash', 'dimension_hash'],
    ],
    'kiwi_landing_handoff_events' => [
        'columns' => ['id', 'created_at', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'pid', 'click_id', 'tksource', 'tkzone', 'session_token', 'handoff_id', 'event_type', 'href_scheme', 'sms_recipient', 'sms_body_present', 'sms_body_has_transaction', 'elapsed_ms', 'visibility_state', 'ua_ch_supported', 'ua_ch_mobile', 'ua_ch_platform', 'ua_ch_platform_version', 'ua_ch_model', 'ua_ch_brands', 'ua_ch_full_version_list', 'user_agent', 'raw_context'],
        'indexes' => ['PRIMARY', 'landing_handoff_event', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'pid', 'click_id', 'tksource', 'tkzone', 'handoff_id', 'event_type', 'created_at', 'created_landing_session_event'],
    ],
    'kiwi_landing_kpi_summary' => [
        'columns' => ['id', 'created_at', 'updated_at', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'clicks', 'cta1', 'cta1_cr', 'cta2', 'cta2_cr', 'cta3', 'cta3_cr', 'conv', 'conv_cr'],
        'indexes' => ['PRIMARY', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'updated_at'],
    ],
    'kiwi_landing_page_sessions' => [
        'columns' => ['id', 'created_at', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'country', 'pid', 'tksource', 'tkzone', 'browser_language', 'device_brand', 'os', 'os_version', 'browser', 'request_host', 'request_path', 'session_token', 'click_to_sms_uri', 'referer', 'user_agent', 'remote_ip', 'client_ip_version', 'client_ip_prefix', 'query_params', 'raw_context'],
        'indexes' => ['PRIMARY', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'country', 'pid', 'tksource', 'tkzone', 'browser_language', 'device_brand', 'os', 'os_version', 'browser', 'client_ip_version', 'client_ip_prefix', 'session_token', 'created_at', 'created_landing_session'],
    ],
    'kiwi_nth_events' => [
        'columns' => ['id', 'created_at', 'provider_key', 'service_key', 'country', 'flow_key', 'event_type', 'direction', 'dedupe_key', 'external_event_type', 'external_request_id', 'external_message_id', 'external_report_id', 'subscriber_reference', 'shortcode', 'keyword', 'operator_code', 'operator_name', 'status', 'is_terminal', 'is_success', 'occurred_at', 'raw_payload', 'normalized_payload'],
        'indexes' => ['PRIMARY', 'dedupe_key', 'service_key', 'external_message_id', 'external_request_id', 'subscriber_reference', 'created_at'],
    ],
    'kiwi_nth_flow_transactions' => [
        'columns' => ['id', 'created_at', 'updated_at', 'service_key', 'country', 'flow_key', 'flow_reference', 'sale_reference', 'landing_key', 'landing_session_token', 'subscriber_reference', 'shortcode', 'keyword', 'operator_code', 'operator_name', 'nwc', 'message_text', 'mo_event_id', 'last_event_id', 'mt_submit_event_id', 'last_report_event_id', 'external_request_id', 'external_message_id', 'current_status', 'is_terminal', 'sale_id', 'price', 'currency', 'meta_json'],
        'indexes' => ['PRIMARY', 'flow_reference', 'service_key', 'sale_reference', 'subscriber_reference', 'external_message_id', 'external_request_id', 'updated_at'],
    ],
    'kiwi_operational_events' => [
        'columns' => ['id', 'occurred_at', 'created_at', 'area', 'severity', 'event_type', 'lifecycle_action', 'idempotency_key', 'correlation_key', 'reference_type', 'reference_id', 'message', 'raw_error_text', 'context_json'],
        'indexes' => ['PRIMARY', 'idempotency_key', 'correlation_id', 'occurred_id', 'created_id', 'area_severity_occurred_id', 'event_type_occurred_id', 'reference_id'],
    ],
    'kiwi_premium_sms_fraud_signals' => [
        'columns' => ['id', 'created_at', 'provider_key', 'service_key', 'flow_key', 'pid', 'click_id', 'tksource', 'tkzone', 'country', 'source_event_key', 'identity_type', 'identity_value', 'occurred_at', 'count_1h', 'count_24h', 'count_total', 'is_soft_flag', 'soft_flag_reason', 'billing_outcome', 'billing_outcome_at', 'billing_transaction_id', 'sale_id', 'sale_completed_at', 'aggregator_status_code', 'aggregator_status_text', 'meta_json'],
        'indexes' => ['PRIMARY', 'source_event_identity', 'service_key', 'provider_key', 'flow_key', 'pid', 'click_id', 'tksource', 'tkzone', 'identity_lookup', 'billing_outcome', 'billing_transaction_id', 'sale_id', 'occurred_at', 'is_soft_flag'],
    ],
    'kiwi_premium_sms_landing_engagements' => [
        'columns' => ['id', 'created_at', 'updated_at', 'provider_key', 'service_key', 'flow_key', 'pid', 'click_id', 'tksource', 'tkzone', 'landing_key', 'session_token', 'page_loaded_at', 'first_cta_click_at', 'last_cta_click_at', 'cta_click_count', 'first_cta1_click_at', 'last_cta1_click_at', 'cta1_click_count', 'first_cta2_click_at', 'last_cta2_click_at', 'cta2_click_count', 'first_cta3_click_at', 'last_cta3_click_at', 'cta3_click_count', 'ua_ch_supported', 'ua_ch_mobile', 'ua_ch_platform', 'ua_ch_platform_version', 'ua_ch_model', 'ua_ch_brands', 'ua_ch_full_version_list', 'user_agent', 'last_event_at', 'is_soft_flag', 'soft_flag_reason', 'soft_flag_rule_key', 'soft_flag_evaluated_at'],
        'indexes' => ['PRIMARY', 'landing_session', 'service_key', 'provider_key', 'flow_key', 'pid', 'click_id', 'tksource', 'tkzone', 'updated_at', 'is_soft_flag_updated', 'created_landing_session'],
    ],
    'kiwi_retention_cleanup_runs' => [
        'columns' => ['id', 'run_id', 'source_key', 'source_table', 'status', 'triggered_by', 'enabled', 'dry_run', 'started_at', 'finished_at', 'retention_days_effective', 'cutoff_column', 'cutoff_value', 'eligible_rows', 'archived_rows', 'archive_inserted_rows', 'archive_duplicate_rows', 'deleted_rows', 'delete_batches', 'gate_status', 'gate_results_json', 'worker_phase', 'target_max_primary_key', 'archive_last_primary_key', 'delete_last_primary_key', 'worker_runs', 'worker_last_started_at', 'worker_last_finished_at', 'archive_batch_id', 'archive_db_path', 'archive_integrity_check', 'error_code', 'error_message', 'created_at', 'updated_at'],
        'indexes' => ['PRIMARY', 'run_id', 'source_key_started', 'status_started', 'source_status_started', 'archive_batch_id'],
    ],
    'kiwi_retention_table_growth_snapshots' => [
        'columns' => ['id', 'snapshot_at', 'snapshot_date', 'snapshot_phase', 'source_key', 'source_table', 'retention_days_effective', 'cutoff_column', 'cutoff_value', 'row_count_total', 'data_size_bytes', 'index_size_bytes', 'total_size_bytes', 'min_cutoff_value', 'max_cutoff_value', 'eligible_rows_at_cutoff', 'archived_rows_last_run', 'deleted_rows_last_run', 'cleanup_run_id', 'archive_batch_id', 'created_at'],
        'indexes' => ['PRIMARY', 'snapshot_source_date', 'cleanup_run_id', 'archive_batch_id'],
    ],
    'kiwi_sales' => [
        'columns' => ['id', 'created_at', 'updated_at', 'sale_reference', 'transaction_id', 'pid', 'provider_key', 'service_key', 'country', 'flow_key', 'landing_key', 'session_ref', 'click_id', 'tksource', 'tkzone', 'device_brand', 'os', 'os_version', 'browser', 'attribution_metric_date', 'client_ip', 'client_ip_version', 'client_ip_prefix', 'client_ip_hash', 'sale_type', 'status', 'amount_minor', 'currency', 'subscriber_reference', 'operator_code', 'operator_name', 'shortcode', 'keyword', 'external_sale_id', 'external_transaction_id', 'completed_at', 'context_json'],
        'indexes' => ['PRIMARY', 'sale_reference', 'provider_key', 'service_key', 'country', 'flow_key', 'landing_key', 'session_ref', 'transaction_id', 'pid', 'click_id', 'tksource', 'tkzone', 'device_brand', 'os', 'os_version', 'browser', 'attribution_metric_date', 'client_ip_prefix', 'client_ip_hash', 'external_sale_id', 'created_at', 'status_attribution_metric_date', 'status_completed_at', 'completed_subscriber_context'],
        'legacy_columns' => ['android_version'],
    ],
    'kiwi_sms_body_variant_assignments' => [
        'columns' => ['id', 'created_at', 'updated_at', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'country', 'keyword', 'shortcode', 'pid', 'click_id', 'session_token', 'transaction_id', 'visible_token', 'variant_key', 'seed', 'sms_body', 'cta1_recorded_at', 'handoff_attempted_at', 'handoff_hidden_at', 'handoff_no_hide_at', 'handoff_returned_at', 'conv_recorded_at', 'raw_context'],
        'indexes' => ['PRIMARY', 'transaction_id', 'visible_token', 'landing_session', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'country', 'pid', 'click_id', 'variant_key', 'seed', 'created_at'],
    ],
    'kiwi_sms_body_variant_summary' => [
        'columns' => ['id', 'created_at', 'updated_at', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'variant_key', 'seed', 'assignments', 'cta1', 'handoff_attempted', 'handoff_hidden', 'handoff_no_hide', 'handoff_returned', 'conv', 'cta1_cr', 'handoff_hidden_cr', 'conv_cr', 'conv_per_cta1_cr', 'conv_per_hidden_cr'],
        'indexes' => ['PRIMARY', 'variant_summary', 'landing_key', 'service_key', 'provider_key', 'flow_key', 'variant_key', 'seed', 'updated_at'],
    ],
    'kiwi_v_load_to_cta_by_tksource_tkzone' => [
        'type' => 'view',
        'columns' => ['metric_at', 'service_key', 'tksource', 'tkzone', 'session_count', 'loaded_session', 'cta_session', 'cta_click_events', 'seconds_load_to_cta', 'sale_id', 'successful_sale_amount_minor', 'successful_transaction_id', 'sale_completed_at'],
    ],
    'kiwi_v_one_for_all' => [
        'type' => 'view',
        'columns' => ['landing_key', 'service_key', 'tksource', 'tkzone', 'device_brand', 'android_version', 'browser', 'sessions', 'landing_page_aufrufe', 'page_loaded_sessions', 'cta_sessions', 'cta_click_events', 'handoff_attempts', 'handoff_successes', 'handoff_fails', 'handoff_rate_pct', 'median_hidden_seconds', 'min_hidden_seconds', 'max_hidden_seconds', 'sales'],
    ],
];
