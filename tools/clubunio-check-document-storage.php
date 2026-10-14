<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection('clubunio');
if (in_array('--read-expense-sample', $argv, true)) {
    $line = $db->table('clubora.expense_lines')->where('active', true)->whereNotNull('storage_reference')->where('storage_reference', '!=', '')->orderByDesc('id')->first();
    if (!$line) { throw new LogicException('No expense document reference'); }
    try {
        $filename = trim(explode(',', $line->storage_reference)[0]);
        $bytes = app(\App\ClubUnio\Services\AzureDocumentReader::class)->read('azure.blob://files/', $filename);
        echo 'Expense sample '.$line->id.': bytes='.strlen($bytes).'; mime='.(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes).PHP_EOL;
    } catch (Throwable $error) {
        echo 'Expense sample: failed, code '.$error->getCode().PHP_EOL;
        exit(1);
    }
    exit(0);
}
foreach ($db->table('clubora.member_documents')->selectRaw('storage_path, count(*) AS total')->groupBy('storage_path')->get() as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES).PHP_EOL;
    if (in_array('--read-sample', $argv, true)) {
        $doc = $db->table('clubora.member_documents')->where('storage_path', $row->storage_path)->where('active', true)->orderBy('id')->first();
        if ($doc) {
            try {
                $bytes = app(\App\ClubUnio\Services\AzureDocumentReader::class)->read($doc->storage_path, $doc->filename);
                echo 'Sample '.$doc->id.': bytes='.strlen($bytes).'; mime='.(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes).PHP_EOL;
            } catch (Throwable $error) {
                echo 'Sample '.$doc->id.': failed, code '.$error->getCode().PHP_EOL;
                exit(1);
            }
        }
    }
}
