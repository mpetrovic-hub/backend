<?php

if (!defined('ABSPATH')) { exit; }

/** Read-only accounting for the groups represented in a frozen cleanup scope. */
class Kiwi_Retention_Sms_Statistics
{
    private const DIMENSIONS = Kiwi_Sms_Body_Variant_Repository::SUMMARY_GROUP_COLUMNS;
    public const MARKERS = ['assignments' => 'id', 'cta1' => 'cta1_recorded_at',
        'handoff_attempted' => 'handoff_attempted_at', 'handoff_hidden' => 'handoff_hidden_at',
        'handoff_no_hide' => 'handoff_no_hide_at', 'handoff_returned' => 'handoff_returned_at', 'conv' => 'conv_recorded_at'];

    private $source;
    private $reader;
    private $deletions;
    private $sales;
    private $checked_group_schema;

    public function __construct(Kiwi_Retention_Archive_Reader $reader, Kiwi_Retention_Cleanup_Run_Repository $runs,
        Kiwi_Sms_Confirmed_Sale_History $sales)
    {
        $this->source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
        $this->reader = $reader;
        $this->deletions = new Kiwi_Retention_Sms_Deletion_Evidence($runs);
        $this->sales = $sales;
    }

    public static function group_key(array $row): array
    {
        $key = [];
        foreach (self::DIMENSIONS as $column) {
            if (!is_string($row[$column] ?? null)) { throw new RuntimeException('sms_group_dimension_unknown'); }
            $key[$column] = $row[$column];
        }
        return $key;
    }

    public static function group_id(array $key): string
    {
        return Kiwi_Sms_Body_Variant_Repository::group_comparison()->key(self::group_key($key));
    }

    private static function source_comparison(bool $refresh = false): Kiwi_Mysql_Text_Comparison
    {
        $source = Kiwi_Retention_Source_Registry::sms_body_variant_assignments();
        return Kiwi_Mysql_Text_Comparison::for_columns($source['source_table'], self::DIMENSIONS, $refresh);
    }

    public static function current_group_schema(): string
    {
        return hash('sha256', Kiwi_Sms_Body_Variant_Repository::group_comparison(true)->fingerprint()
            . self::source_comparison(true)->fingerprint());
    }

    public static function verify_group_schema(array $state): void
    {
        if (!is_string($state['group_schema'] ?? null) || !hash_equals($state['group_schema'], self::current_group_schema())) {
            throw new RuntimeException('sms_group_schema_changed');
        }
    }

    private function source_group_columns(): array
    {
        $summary = Kiwi_Sms_Body_Variant_Repository::group_comparison();
        $source = self::source_comparison();
        $expressions = [];
        foreach (self::DIMENSIONS as $column) { $expressions[$column] = $summary->column_sql($column, $column, $source); }
        return $expressions;
    }

    public function begin(string $cutoff, int $max_id, array $settings): array
    {
        global $wpdb;
        $schema = self::current_group_schema();
        $expressions = $this->source_group_columns();
        $select = [];
        foreach ($expressions as $column => $expression) { $select[] = $expression . ' AS `' . $column . '`'; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT ' . implode(',', $select) . ', COUNT(*) AS eligible_rows FROM '
            . $this->source['source_table'] . ' WHERE id<=%d AND created_at<%s GROUP BY ' . implode(',', $expressions),
            $max_id, $cutoff), ARRAY_A);
        if (!is_array($rows) || (string) $wpdb->last_error !== '') { throw new RuntimeException('sms_candidates_read_failed'); }
        $groups = [];
        foreach ($rows as $row) {
            $key = self::group_key($row);
            $id = self::group_id($key);
            if (isset($groups[$id])) { throw new RuntimeException('sms_group_identity_conflict'); }
            $groups[$id] = ['key' => $key, 'eligible_rows' => (int) $row['eligible_rows']];
        }
        return $this->new_measurement($groups, ['percent' => (float) ($settings['tolerance_percent'] ?? 2),
            'absolute' => (int) ($settings['tolerance_absolute'] ?? 2)], $schema);
    }

    /** Repeat the full historical check for specified groups without changing the deletion scope. */
    public function new_measurement(array $groups, array $tolerance, string $group_schema): array
    {
        foreach ($groups as $id => $group) {
            $groups[$id] = ['key' => $group['key'], 'eligible_rows' => $group['eligible_rows'],
                'archive_counts' => array_fill_keys(array_keys(self::MARKERS), 0), 'late_sales' => 0,
                'uncertain_sales' => 0, 'reasons' => []];
        }
        return ['version' => 1, 'group_schema' => $group_schema, 'tolerance' => $tolerance, 'groups' => $groups,
            'progress' => ['phase' => $groups ? 'archive' : 'done', 'cursor' => 0, 'file' => 0, 'files' => null]];
    }

    /** Advance the read-only examination by one bounded page and report the work consumed. */
    public function advance(array &$state, int $limit): int
    {
        if ($this->checked_group_schema === null || $this->checked_group_schema !== ($state['group_schema'] ?? null)) {
            self::verify_group_schema($state);
            $this->checked_group_schema = $state['group_schema'];
        }
        $progress = &$state['progress'];
        if (!in_array($progress['phase'], ['archive', 'evidence', 'orphans', 'sales', 'measure', 'done'], true)
            || !is_int($progress['cursor']) || $progress['cursor'] < 0 || $limit < 1) {
            throw new RuntimeException('sms_statistics_checkpoint_invalid');
        }
        if ($progress['phase'] === 'done') { return 0; }
        if (in_array($progress['phase'], ['sales', 'measure'], true) && empty($progress['inventory_error'])) {
            try {
                if (array_column($this->reader->files(true), 'path') !== $progress['files']) { throw new RuntimeException('archive_inventory_changed'); }
            } catch (Throwable $error) {
                $this->note($state, null, 'archive_inventory_changed');
                $progress['phase'] = 'measure';
                $progress['cursor'] = 0;
                $progress['inventory_error'] = true;
            }
        }
        if (in_array($progress['phase'], ['archive', 'evidence', 'orphans'], true)) {
            try {
                $paths = array_column($this->reader->files(true), 'path');
                if ($progress['files'] !== null && $paths !== $progress['files']) { throw new RuntimeException('archive_inventory_changed'); }
                $progress['files'] = $paths;
                if ($progress['phase'] === 'archive') { return $this->accumulate_archive($state, $paths, $limit); }
                if ($progress['phase'] === 'evidence') { return $this->check_deletion_evidence($state, $paths, $limit); }
                return $this->check_orphans($state, $paths, $limit);
            } catch (Throwable $error) {
                $this->note($state, null, 'archive_history_unavailable');
                $progress['inventory_error'] = true;
                $progress['phase'] = 'measure';
                $progress['cursor'] = 0;
                return 1;
            } finally {
                $this->reader->close();
            }
        }
        if ($progress['phase'] === 'sales') {
            try { return $this->accumulate_sales($state, $limit); }
            catch (Throwable $error) {
                $this->note($state, null, 'confirmed_sales_read_failed', null, 'conv');
                $progress['phase'] = 'measure';
                $progress['cursor'] = 0;
                return 1;
            } finally {
                $this->reader->close();
            }
        }
        $ids = array_keys($state['groups']);
        $offset = $state['progress']['cursor'];
        $count = 0;
        foreach (array_slice($ids, $offset, $limit) as $id) {
            $group = &$state['groups'][$id];
            $group['before'] = $this->measure_live($group, $state['tolerance']);
            unset($group);
            ++$count;
        }
        $state['progress']['cursor'] += $count;
        if ($state['progress']['cursor'] >= count($ids)) { $state['progress']['phase'] = 'done'; }
        return $count;
    }

    private function accumulate_archive(array &$state, array $paths, int $limit): int
    {
        $ids = $this->reader->original_ids_after($this->source, $state['progress']['cursor'], $limit);
        if (!$ids) {
            $state['progress']['phase'] = 'evidence';
            $state['progress']['audit_cursor'] = [];
            $state['progress']['cursor'] = 0;
            return 0;
        }
        $absent = $this->absent_from_mysql($ids);
        if ($absent) {
            foreach ($this->latest($paths, $absent) as $id => $copy) {
                $group_id = self::group_id($copy['row']);
                if (!isset($state['groups'][$group_id])) { continue; }
                if (!$copy['verified']) {
                    $this->note($state, $group_id, 'archive_row_receipt_invalid', $id);
                    continue;
                }
                foreach (self::MARKERS as $counter => $field) {
                    if ($copy['row'][$field] !== null) { ++$state['groups'][$group_id]['archive_counts'][$counter]; }
                }
            }
        }
        $state['progress']['cursor'] = max($ids);
        return count($ids);
    }

    private function check_orphans(array &$state, array $paths, int $limit): int
    {
        $progress = &$state['progress'];
        if (!isset($paths[$progress['file']])) {
            $progress['phase'] = 'sales';
            $progress['cursor'] = 0;
            unset($progress['discounted_ids']);
            return 0;
        }
        $path = $paths[$progress['file']];
        $ids = $this->reader->missing_original_ids($this->source, $path, $progress['cursor'], $limit);
        if (!$ids) {
            ++$progress['file'];
            $progress['cursor'] = 0;
            return 0;
        }
        $absent = $this->absent_from_mysql($ids);
        $copies = $absent ? $this->latest($paths, $absent) : [];
        foreach ($absent as $id) {
            $copy = $copies[$id] ?? null;
            if ($copy && array_search($copy['path'], $paths, true) > $progress['file'] && $copy['verified']) { continue; }
            // A missing newer snapshot can change group. Its deletion manifest can identify the affected group.
            $group = $progress['known_gaps'][$id]['group'] ?? null;
            if ($copy && $copy['verified'] && !isset($progress['discounted_ids'][$id])) {
                $obsolete = self::group_id($copy['row']);
                if (isset($state['groups'][$obsolete])) {
                    foreach (self::MARKERS as $counter => $field) {
                        if ($copy['row'][$field] !== null) { --$state['groups'][$obsolete]['archive_counts'][$counter]; }
                    }
                }
                $progress['discounted_ids'][$id] = true;
            }
            $this->note($state, $group, 'archive_receipted_original_missing', $id);
        }
        $progress['cursor'] = max($ids);
        return count($ids);
    }

    private function check_deletion_evidence(array &$state, array $paths, int $limit): int
    {
        $visit = function (array $run, array $batch) use (&$state, $paths): void {
            $group = self::group_id($batch['group']);
            $path = (string) $run['archive_db_path'];
            foreach (array_chunk($batch['ids'], 500) as $chunk) {
                $ids = $this->absent_from_mysql($chunk);
                if (!$ids) { continue; }
                $rows = in_array($path, $paths, true) ? $this->reader->receipted_rows($this->source, $path, $ids) : [];
                $latest = $rows && end($paths) !== $path ? $this->latest($paths, $ids) : $rows;
                foreach ($ids as $id) {
                    $copy = $rows[$id] ?? null;
                    if (!$copy || !$copy['verified'] || $copy['row']['_archive_batch_id'] !== $batch['batch_id']
                        || self::group_id($copy['row']) !== $group) {
                        $this->note($state, $group, 'deleted_original_receipt_missing_or_mismatched', $id);
                        $position = array_search($path, $paths, true);
                        if ($position !== false && $position >= ($state['progress']['known_gaps'][$id]['file'] ?? -1)) {
                            $state['progress']['known_gaps'][$id] = ['group' => $group, 'file' => $position];
                        }
                        if ($copy && self::group_id($copy['row']) !== $group) {
                            $this->note($state, self::group_id($copy['row']), 'deleted_original_group_conflict', $id);
                        }
                        continue;
                    }
                    $canonical = $latest[$id] ?? null;
                    if ($canonical && $canonical['path'] !== $path && $canonical['verified']
                        && Kiwi_Retention_Archive_Reader::snapshot($this->source, $canonical['row'])
                            !== Kiwi_Retention_Archive_Reader::snapshot($this->source, $copy['row'])) {
                        $current = self::group_key($canonical['row']);
                        try {
                            $bound = $this->deletions->finished_after_deletion($canonical['row'], $canonical['path'], $current);
                        } catch (Throwable $error) {
                            $bound = null;
                        }
                        if ($bound === null) {
                            $this->note($state, self::group_id($current), 'deleted_original_version_conflict', $id);
                            if ($group !== self::group_id($current)) { $this->note($state, $group, 'deleted_original_version_conflict', $id); }
                        }
                    }
                }
            }
        };
        $incomplete = function (array $run, ?array $key, string $reason) use (&$state): void {
            $this->note($state, $key === null ? null : self::group_id($key), $reason);
        };
        $done = $this->deletions->visit_page($state['progress']['audit_cursor'], min($limit, 4), $visit, $incomplete);
        if ($done) {
            $state['progress']['phase'] = 'orphans';
            $state['progress']['cursor'] = 0;
            unset($state['progress']['audit_cursor']);
        }
        return min($limit, 4);
    }

    private function accumulate_sales(array &$state, int $limit): int
    {
        $progress = &$state['progress'];
        $services = [];
        foreach ($state['groups'] as $group) {
            if ($group['archive_counts']['assignments'] > 0) { $services[$group['key']['service_key']] = true; }
        }
        $sales = [];
        if ($services) {
            if (!isset($progress['sale_max'])) {
                $progress['sale_max'] = $this->sales->max_sale_id();
            }
            $sales = $this->sales->confirmed_sales_page(array_keys($services), $progress['cursor'], $progress['sale_max'], $limit);
        }
        if (!$sales) {
            $progress['phase'] = 'measure';
            $progress['cursor'] = 0;
            unset($progress['known_gaps']);
            return 0;
        }
        foreach ($sales as $sale) {
            $progress['cursor'] = (int) $sale['id'];
            $found = $sale['proof'];
            if ($found['status'] === 'read_error' || $found['status'] === 'ambiguous') {
                $this->note_unresolved_sale($state, $sale);
                continue;
            }
            if ($found['status'] !== 'found' || $found['source'] !== 'archive') { continue; }
            $original = $found['assignment'];
            $group = self::group_id($original);
            if (!isset($state['groups'][$group])) { continue; }
            $copies = $this->reader->copies($this->source, (int) $original['id'], null, true);
            $path = array_key_last($copies);
            if ($path === null || $copies[$path] !== $original) {
                $this->note($state, $group, 'sale_original_changed', (int) $sale['id'], 'conv');
                continue;
            }
            $created = $this->timestamp($sale['created_at'] ?? null);
            $copied = $this->timestamp($original['_archived_at'] ?? null);
            try {
                $bound = $this->deletions->finished_after_deletion($original, $path, self::group_key($original));
            } catch (Throwable $error) {
                $this->note($state, $group, 'sale_deletion_audit_invalid', (int) $sale['id'], 'conv');
                continue;
            }
            if ($copied !== null && $bound !== null && $copied > $bound) {
                $this->note($state, $group, 'sale_archive_deletion_dates_conflict', (int) $sale['id'], 'conv');
                continue;
            }
            // The copy proves only that deletion was not earlier. An equal second is still ambiguous.
            if ($created !== null && $copied !== null && $created < $copied) { continue; }
            if ($created !== null && $bound !== null && $created > $bound) { ++$state['groups'][$group]['late_sales']; }
            else { ++$state['groups'][$group]['uncertain_sales']; }
        }
        return count($sales);
    }

    private function note_unresolved_sale(array &$state, array $sale): void
    {
        $found = $sale['proof'];
        $candidates = null;
        $code = $found['original_code'] ?? [];
        $column = array_key_first($code);
        if (($found['source'] ?? null) === 'archive' && in_array($column, ['transaction_id', 'visible_token'], true)) {
            try {
                $matches = Kiwi_Sms_Body_Variant_Service::original_code_matcher($this->source, $column, $code[$column]);
                $ids = $this->reader->matching_source_ids($this->source, $column, $code[$column], $matches);
                $identified = [];
                foreach (array_chunk($ids, 250) as $page) {
                    $rows = $this->latest($state['progress']['files'], $page);
                    foreach ($page as $id) {
                        $known = $state['progress']['known_gaps'][$id] ?? null;
                        if ($known !== null) { $identified[$known['group']] = true; }
                        if (isset($rows[$id])) {
                            $file = array_search($rows[$id]['path'], $state['progress']['files'], true);
                            if (($known === null || $file > $known['file']) && $matches($rows[$id]['row'][$column])) {
                                $identified[self::group_id($rows[$id]['row'])] = true;
                            }
                        }
                        elseif ($known === null) { throw new RuntimeException('sale_candidate_group_unknown'); }
                    }
                }
                if ($identified) { $candidates = $identified; }
            } catch (Throwable $error) {
                // Without a readable candidate inventory, the service is the narrowest evidenced scope.
            }
        }
        foreach ($state['groups'] as $key => $group) {
            if (self::group_id(array_replace($group['key'], ['service_key' => $sale['service_key']])) !== $key) { continue; }
            if ($candidates !== null ? !isset($candidates[$key]) : $group['archive_counts']['assignments'] === 0) { continue; }
            $this->note($state, $key, 'sale_original_' . $found['status'], (int) $sale['id'], 'conv');
        }
    }

    private function timestamp($value): ?string
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value)) { return null; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    private function absent_from_mysql(array $ids): array
    {
        global $wpdb;
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $active = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . $this->source['source_table']
            . ' WHERE id IN (' . $marks . ')', ...$ids));
        if (!is_array($active) || (string) $wpdb->last_error !== '') { throw new RuntimeException('sms_original_lookup_failed'); }
        return array_values(array_diff($ids, array_map('intval', $active)));
    }

    private function latest(array $paths, array $ids): array
    {
        $latest = [];
        foreach ($paths as $path) {
            $latest = array_replace($latest, $this->reader->receipted_rows($this->source, $path, $ids));
        }
        return $latest;
    }

    private function note(array &$state, ?string $group_id, string $code, ?int $id = null, ?string $counter = null): void
    {
        foreach ($state['groups'] as $key => &$group) {
            if ($group_id !== null && $key !== $group_id) { continue; }
            $reason = $group['reasons'][$code] ?? ['code' => $code, 'counter' => $counter, 'count' => 0, 'example_ids' => []];
            ++$reason['count'];
            if ($id !== null && count($reason['example_ids']) < 8) { $reason['example_ids'][] = $id; }
            $group['reasons'][$code] = $reason;
        }
        unset($group);
    }

    private function measure_live(array $group, array $tolerance): array
    {
        global $wpdb;
        $predicate = implode(' AND ', array_map(static fn ($field) => $field . '=%s', $this->source_group_columns()));
        $aggregates = [];
        $actuals = [];
        foreach (self::MARKERS as $counter => $field) {
            $aggregates[] = "COUNT({$field}) AS {$counter}";
            $actuals[] = "s.{$counter} AS actual_{$counter}";
        }
        $source = $this->source['source_table'];
        $summary = $wpdb->prefix . 'kiwi_sms_body_variant_summary';
        $sql = 'SELECT a.*,s.id AS summary_id,' . implode(',', $actuals)
            . ' FROM (SELECT MIN(created_at) AS first_created_at, MAX(created_at) AS last_created_at,'
            . implode(',', $aggregates) . " FROM {$source} WHERE {$predicate}) a
             LEFT JOIN {$summary} s ON " . implode(' AND ', array_map(static fn ($field) => 's.' . $field . '=%s', self::DIMENSIONS));
        $values = array_values($group['key']);
        $row = $wpdb->get_row($wpdb->prepare($sql, ...array_merge($values, $values)), ARRAY_A);
        $reasons = array_values($group['reasons']);
        $known_live = is_array($row) && (string) $wpdb->last_error === '';
        if (!$known_live) { $reasons[] = ['code' => 'live_statistics_read_failed']; }
        elseif ($row['summary_id'] === null) { $reasons[] = ['code' => 'summary_row_missing']; }
        $result = ['status' => 'passed', 'reasons' => $reasons, 'summary_id' => $row['summary_id'] ?? null,
            'mysql_baseline' => ['first_created_at' => $row['first_created_at'] ?? null,
                'last_created_at' => $row['last_created_at'] ?? null, 'counters' => []], 'counters' => []];
        foreach (self::MARKERS as $counter => $field) {
            $live = $known_live ? (int) $row[$counter] : null;
            $result['mysql_baseline']['counters'][$counter] = $live;
            $actual = isset($row['actual_' . $counter]) ? (int) $row['actual_' . $counter] : null;
            $expected = $live === null ? null : $live + $group['archive_counts'][$counter];
            foreach ($group['reasons'] as $reason) {
                if ($reason['counter'] === null || $reason['counter'] === $counter) { $expected = null; break; }
            }
            if ($counter === 'conv' && $expected !== null) { $expected += $group['late_sales']; }
            $result['counters'][$counter] = $this->assess($expected, $actual, $counter === 'conv' ? $group['uncertain_sales'] : 0, $tolerance);
            if ($result['counters'][$counter]['status'] !== 'passed') { $result['status'] = 'outside_tolerance'; }
        }
        if ($reasons) { $result['status'] = 'incomplete'; }
        return $result;
    }

    private function assess(?int $expected, ?int $actual, int $uncertain, array $tolerance): array
    {
        $result = ['expected' => $expected, 'actual' => $actual, 'distance' => null, 'limit' => null, 'status' => 'incomplete'];
        if ($expected === null) { return $result; }
        $comparisons = [];
        for ($i = 0; $i <= $uncertain; $i++) {
            $possible = $expected + $i;
            $percent_limit = $possible * ($tolerance['percent'] / 100);
            $limit = max($tolerance['absolute'], is_finite($percent_limit) ? $percent_limit : PHP_FLOAT_MAX);
            $distance = $actual === null ? null : abs($actual - $possible);
            $status = $distance === null ? 'incomplete' : ($distance <= $limit ? 'passed' : 'outside_tolerance');
            $comparisons[] = ['expected' => $possible, 'distance' => $distance, 'limit' => $limit, 'status' => $status];
        }
        if ($uncertain === 0) { return array_replace($result, $comparisons[0]); }
        $result['expected'] = null;
        $result['possible_expected'] = array_column($comparisons, 'expected');
        $result['comparisons'] = $comparisons;
        $result['status'] = $actual === null ? 'incomplete'
            : (in_array('outside_tolerance', array_column($comparisons,'status'),true) ? 'outside_tolerance' : 'passed');
        return $result;
    }
}
