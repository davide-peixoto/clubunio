<?php

// Export metadata only. Never export connections, event code or parameter values.
require_once __DIR__.'/legacy-source-paths.php';
$source = clubunioLegacyBackendPath('phpmaker/svs2026_prerelease_v3.pmp');
$xml = simplexml_load_file($source, options: LIBXML_NONET);
if (! $xml) {
    throw new RuntimeException('Invalid PMP XML');
}
$out = ['relations' => [], 'tables' => []];
foreach ($xml->MasterDetail as $relation) {
    $item = ['master' => (string) $relation['MasterTable'], 'detail' => (string) $relation['DetailTable'], 'fields' => []];
    foreach ($relation->Relation as $field) {
        $item['fields'][] = ['master' => (string) $field['MasterField'], 'detail' => (string) $field['DetailField']];
    }
    $out['relations'][] = $item;
}
$allowed = ['FldName', 'FldCaption', 'FldTypeName', 'FldRequired', 'FldSelectType', 'FldTagValues', 'FldTagLnkTbl', 'FldTagLnkFld', 'FldTagLnkDisplay', 'FldTagLnkDisp2', 'FldTagLnkDisp3', 'FldTagLnkDisp4', 'FldTagLnkDisplay2', 'FldTagLnkDisplay3', 'FldTagLnkDisplay4', 'FldParentSelect', 'FldSelectFilterFld', 'FldAutoFillSourceFields', 'FldAutoFillTargetFields', 'FldAutoFill'];
foreach ($xml->xpath('//Table') as $table) {
    $fields = [];
    foreach ($table->Field as $field) {
        $entry = [];
        foreach ($allowed as $key) {
            if (isset($field[$key])) {
                $entry[$key] = (string) $field[$key];
            }
        }
        $fields[] = $entry;
    }
    $out['tables'][(string) $table['TblName']] = $fields;
}
file_put_contents(dirname(__DIR__).'/deploy/pmp-relations.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
echo count($out['relations']).' relations exported; no credentials or event code.'.PHP_EOL;
