<?php

if (!defined('ABSPATH')) {
    exit;
}

class Kiwi_Csv_Exporter
{
    public function export(array $rows, string $filename = 'kiwi-hlr-results.csv'): void
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        if ($output === false) {
            return;
        }

        fputcsv($output, [
            'MSISDN',
            'Provider',
            'Feature',
            'Success',
            'Status Code',
            'API Status',
            'HLR Status',
            'Operator',
            'Messages',
        ], ';');

        foreach ($rows as $row) {
            $messages = '';

            if (!empty($row['messages']) && is_array($row['messages'])) {
                $messages = implode(' | ', $row['messages']);
            }

            $msisdn = $row['msisdn'] ?? '';

            // Force Excel to treat MSISDN as text
            if ($msisdn !== '') {
                $msisdn = '="' . $msisdn . '"';
            }

            fputcsv($output, [
                $msisdn,
                $row['provider'] ?? '',
                $row['feature'] ?? '',
                !empty($row['success']) ? '1' : '0',
                $row['status_code'] ?? '',
                $row['api_status'] ?? '',
                $row['hlr_status'] ?? '',
                $row['operator'] ?? '',
                $messages,
            ], ';');
        }

        fclose($output);
        exit;
    }

    public function export_columns(array $rows, array $columns, string $filename): void
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        if ($output === false) {
            return;
        }

        fputcsv($output, array_values($columns), ';');

        foreach ($rows as $row) {
            $csv_row = [];

            foreach (array_keys($columns) as $field) {
                $csv_row[] = $row[$field] ?? '';
            }

            fputcsv($output, $csv_row, ';');
        }

        fclose($output);
        exit;
    }
}
