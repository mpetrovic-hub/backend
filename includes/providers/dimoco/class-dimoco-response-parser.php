<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Dimoco_Response_Parser
{
    /**
    * Parse DIMOCO sync responses and callback XML into a standardized array
    */
    public function parse(array $response): array
    {
        $success     = $response['success'] ?? false;
        $status_code = (int) ($response['status_code'] ?? 0);
        $request     = $response['request'] ?? [];
        $xml         = $response['xml'] ?? '';
        $error       = $response['error'] ?? '';

        if ($xml === '' || !is_string($xml)) {
            return [
                'success'            => false,
                'provider'           => 'dimoco',
                'feature'            => 'refund',
                'http_success'       => (bool) $success,
                'status_code'        => $status_code,
                'action'             => '',
                'action_status'      => null,
                'action_status_text' => 'unknown',
                'action_code'        => '',
                'detail'             => $error !== '' ? $error : 'Empty XML response.',
                'detail_psp'         => '',
                'request_id'         => $request['request_id'] ?? '',
                'reference'          => '',
                'transaction_id'     => $request['transaction'] ?? '',
                'order_id'           => $request['order'] ?? '',
                'messages'           => [],
                'raw'                => $response,
            ];
        }

        libxml_use_internal_errors(true);
        $xml_object = simplexml_load_string($xml);

        if ($xml_object === false) {
            $xml_errors = libxml_get_errors();
            libxml_clear_errors();

            $messages = [];
            foreach ($xml_errors as $xml_error) {
                $messages[] = trim($xml_error->message);
            }

            return [
                'success'            => false,
                'provider'           => 'dimoco',
                'feature'            => 'refund',
                'http_success'       => (bool) $success,
                'status_code'        => $status_code,
                'action'             => '',
                'action_status'      => null,
                'action_status_text' => 'invalid_xml',
                'action_code'        => '',
                'detail'             => 'Unable to parse XML response.',
                'detail_psp'         => '',
                'request_id'         => $request['request_id'] ?? '',
                'reference'          => '',
                'transaction_id'     => $request['transaction'] ?? '',
                'order_id'           => $request['order'] ?? '',
                'messages'           => $messages,
                'raw'                => $response,
            ];
        }

        $action        = $this->xml_value($xml_object->action ?? null);
        $action_code   = $this->xml_value($xml_object->action_result->code ?? null);
        $detail        = $this->xml_value($xml_object->action_result->detail ?? null);
        $detail_psp    = $this->xml_value($xml_object->action_result->detail_psp ?? null);
        $action_status = $this->xml_int($xml_object->action_result->status ?? null);

        $request_id = $this->xml_value($xml_object->request_id ?? null);
        if ($request_id === '') {
            $request_id = $request['request_id'] ?? '';
        }

        $reference = $this->xml_value($xml_object->reference ?? null);

        $order_id = $this->xml_value($xml_object->payment_parameters->order ?? null);
        if ($order_id === '') {
            $order_id = $request['order'] ?? '';
        }

        $transaction_id = $this->xml_value($xml_object->transactions->transaction->id ?? null);
        if ($transaction_id === '') {
            $transaction_id = $request['transaction'] ?? '';
        }

        $messages = [];
        if ($detail !== '') {
            $messages[] = $detail;
        }
        if ($detail_psp !== '') {
            $messages[] = $detail_psp;
        }

        return [
            'success'            => $this->is_successful($action_status),
            'provider'           => 'dimoco',
            'feature'            => $action !== '' ? $action : 'refund',
            'http_success'       => (bool) $success,
            'status_code'        => $status_code,
            'action'             => $action,
            'action_status'      => $action_status,
            'action_status_text' => $this->map_status($action_status),
            'action_code'        => $action_code,
            'detail'             => $detail,
            'detail_psp'         => $detail_psp,
            'request_id'         => $request_id,
            'reference'          => $reference,
            'transaction_id'     => $transaction_id,
            'order_id'           => $order_id,
            'messages'           => $messages,
            'raw'                => $response,
        ];
    }

    /**
     * Convert a DIMOCO status number into a readable text
     */
    private function map_status(?int $status): string
    {
        $map = [
            0 => 'success',
            1 => 'failure',
            2 => 'unbilled',
            3 => 'redirect_required',
            4 => 'validation_failed',
            5 => 'pending',
        ];

        return $map[$status] ?? 'unknown';
    }

    /**
     * Treat only callback/final status 0 as success
     */
    private function is_successful(?int $status): bool
    {
        return $status === 0;
    }

    /**
     * Extract string value from a SimpleXML node
     */
    private function xml_value($node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) $node);
    }

    /**
     * Extract integer value from a SimpleXML node
     */
    private function xml_int($node): ?int
    {
        $value = $this->xml_value($node);

        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}