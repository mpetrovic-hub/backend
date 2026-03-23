<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Callback_Handler
{
    private $config;
    private $verifier;
    private $parser;

    public function __construct(
        Kiwi_Config $config,
        Kiwi_Dimoco_Callback_Verifier $verifier,
        Kiwi_Dimoco_Response_Parser $parser
    ) {
        $this->config = $config;
        $this->verifier = $verifier;
        $this->parser = $parser;
    }

    /**
     * Handle incoming DIMOCO callback POST
     */
    public function handle(): void
    {
        error_log('KIWI DIMOCO CALLBACK HIT pipapo');

        $xml = isset($_POST['data']) ? wp_unslash($_POST['data']) : '';
        $received_digest = isset($_POST['digest']) ? wp_unslash($_POST['digest']) : '';

        error_log('KIWI DIMOCO CALLBACK STEP 1: params read');
        error_log('KIWI DIMOCO CALLBACK XML LENGTH: ' . strlen($xml));
        error_log('KIWI DIMOCO CALLBACK DIGEST LENGTH: ' . strlen($received_digest));

        if ($xml === '' || $received_digest === '') {
            error_log('KIWI DIMOCO CALLBACK: missing data or digest');
            status_header(400);
            echo 'Missing callback parameters.';
            exit;
        }

        $service = $this->resolve_service_by_order_from_xml($xml);
        error_log('KIWI DIMOCO CALLBACK STEP 2: service resolved');

        if ($service === null) {
            error_log('KIWI DIMOCO CALLBACK: unknown order');
            status_header(400);
            echo 'Unknown DIMOCO order.';
            exit;
        }

        $secret = $service['secret'] ?? '';
        error_log('KIWI DIMOCO CALLBACK STEP 3: secret loaded');

        $is_valid = $this->verifier->verify($xml, $received_digest, $secret);
        error_log('KIWI DIMOCO CALLBACK STEP 4: digest checked => ' . ($is_valid ? 'valid' : 'invalid'));

        if (!$is_valid) {
            error_log('KIWI DIMOCO CALLBACK: invalid digest');
            status_header(403);
            echo 'Invalid callback digest.';
            exit;
        }

        $parsed_result = $this->parser->parse([
            'success'     => true,
            'status_code' => 200,
            'request'     => [],
            'xml'         => $xml,
        ]);
        error_log('KIWI DIMOCO CALLBACK STEP 5: parsed');

        error_log('KIWI DIMOCO CALLBACK PARSED: ' . wp_json_encode($parsed_result));

        $this->maybe_log_callback($parsed_result);

        error_log('KIWI DIMOCO CALLBACK STEP 6: returning 200 OK');

        status_header(200);
        echo 'OK';
        exit;
    }

    /**
     * Resolve configured DIMOCO service by order id found in callback XML
     */
    private function resolve_service_by_order_from_xml(string $xml): ?array
    {
        libxml_use_internal_errors(true);
        $xml_object = simplexml_load_string($xml);

        if ($xml_object === false) {
            libxml_clear_errors();
            return null;
        }

        $order_id = trim((string) ($xml_object->payment_parameters->order ?? ''));

        if ($order_id === '') {
            return null;
        }

        foreach ($this->config->get_dimoco_services() as $service) {
            if (($service['order_id'] ?? '') === $order_id) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Temporary logging for development/debugging
     */
    private function maybe_log_callback(array $parsed_result): void
    {
        if (!$this->config->is_dimoco_debug()) {
            return;
        }

        error_log('KIWI DIMOCO CALLBACK: ' . wp_json_encode($parsed_result));
    }
}