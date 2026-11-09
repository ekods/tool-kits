<?php
// Real XLSX writer, synthetic paged database; no WordPress installation or real submissions.
require __DIR__ . '/cf7-database-regression.php';
class WP_Error {
    public $code;
    public function __construct($code, $message) { $this->code = $code; }
}
class ExportDatabase extends CollectionDatabase {
    public $last_error = '';
    public $batches = array();
    public $fail = false;
    public $empty = false;
    public function get_var($query) { return $this->empty ? 0 : 1201; }
    public function get_results($query, $format = null) {
        if ($this->fail) { $this->last_error = 'database unavailable'; return null; }
        check_database(strpos($query, 'LIMIT 500') !== false && strpos($query, 'OFFSET') === false, 'Export query is not bounded');
        preg_match('/id <= (\d+)/', $query, $maximum);
        preg_match('/form_id = (\d+)/', $query, $form);
        preg_match("/submitted_at < '([^']+)'/", $query, $time);
        preg_match('/AND id < (\d+)/', $query, $cursor);
        $rows = array();
        for ($id = 1202; $id >= 1 && count($rows) < 500; $id--) {
            $submitted = $id > 700 ? '2026-11-09 10:00:00' : '2026-11-08 10:00:00';
            if ($id > (int) $maximum[1] || ($form && $id % 2 + 1 !== (int) $form[1])
                || ($cursor && !($submitted < $time[1] || ($submitted === $time[1] && $id < (int) $cursor[1])))) { continue; }
            $data = array('your-message' => $id === 1201 ? '=1+1' : str_repeat('message ', 1000), 'tk_captcha_answer' => 'secret');
            if ($id === 1) { $data['last-field'] = "Ayu & Budi\n日本語"; }
            $rows[] = (object) array('id' => $id, 'form_id' => $id % 2 + 1, 'form_title' => 'Contact', 'submitted_at' => $submitted,
                'read_status' => $id % 2 ? 'unread' : 'read', 'read_at' => null, 'user_ip' => '192.0.2.1', 'page_url' => '/', 'data' => json_encode($data));
        }
        $this->batches[] = count($rows);
        return $rows;
    }
}
function export_sheet($file) {
    check_database(is_string($file), 'Export failed to create a file');
    $zip = new ZipArchive();
    check_database($zip->open($file) === true, 'Export ZIP is invalid');
    $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
    $zip->close(); unlink($file);
    return $xml;
}
$wpdb = new ExportDatabase();
$sheet = export_sheet(tk_cf7dbc_prepare_export(0, 'submissions.xlsx'));
check_database(count($sheet->sheetData->row) === 1202 && $wpdb->batches === array(500, 500, 201), 'Rows missing or repeated across export batches');
check_database((string) $sheet->sheetData->row[1]->c[0]->is->t === '1201' && (string) $sheet->sheetData->row[1201]->c[0]->is->t === '1', 'Date/ID ordering or snapshot boundary changed');
check_database((string) $sheet->sheetData->row[0]->c[10]->is->t === 'last-field' && (string) $sheet->sheetData->row[1201]->c[10]->is->t === "Ayu & Budi\n日本語", 'Late dynamic columns or Unicode lost');
check_database((string) $sheet->sheetData->row[1]->c[9]->is->t === "'=1+1" && strpos($sheet->asXML(), 'tk_captcha_answer') === false && strpos($sheet->asXML(), 'secret') === false, 'Formula or internal-field protection lost');
check_database((string) $sheet->autoFilter['ref'] === 'A1:K1202', 'Streaming XLSX filter range is incorrect');
$wpdb = new ExportDatabase();
$sheet = export_sheet(tk_cf7dbc_prepare_export(1, 'filtered.xlsx'));
check_database(count($sheet->sheetData->row) === 601, 'Form filter row count is incorrect');
foreach ($sheet->sheetData->row as $i => $row) {
    if ((string) $row['r'] === '1') { continue; }
    check_database((string) $row->c[1]->is->t === '1', 'A different form leaked into the export');
}
$wpdb->empty = true;
$sheet = export_sheet(tk_cf7dbc_prepare_export(0, 'empty.xlsx'));
check_database(count($sheet->sheetData->row) === 1 && (string) $sheet->autoFilter['ref'] === 'A1:I1', 'Empty export is not a valid header-only workbook');
$wpdb->fail = true;
check_database(tk_cf7dbc_prepare_export(0, 'failed.xlsx') instanceof WP_Error, 'Database errors produced a partial workbook');
$before = glob(sys_get_temp_dir() . '/tk-cf7-xlsx-*');
$rows = (function () { yield array('first'); throw new RuntimeException('stream failed'); })();
check_database(tk_cf7dbc_create_excel_file(array('Message'), $rows, 'failed.xlsx') instanceof WP_Error, 'Writer errors were not reported');
check_database(glob(sys_get_temp_dir() . '/tk-cf7-xlsx-*') === $before, 'Failed writer leaked temporary files');
echo "PASS: CF7 batch export, stable ordering, snapshot, dynamic columns, form filter, empty/error output and temporary-file cleanup\n";
