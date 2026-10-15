<?php

namespace App\ClubUnio\Services;

class PmpInventory
{
    public function read(string $path): array
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException('PMP without DTD/entities required');
        }
        $old = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (! $document->loadXML($source, LIBXML_NONET) || $document->doctype !== null) {
                throw new \RuntimeException('PMP without DTD required');
            }
            $xml = simplexml_import_dom($document);
            if (! $xml) {
                throw new \RuntimeException('Invalid PMP XML');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        // Explicit metadata allowlist: never project attributes, SQL, defaults, code or values.
        $out = ['source_sha256' => hash('sha256', $source), 'version' => (string) $xml['Version'], 'tables' => [], 'relations' => [], 'hooks' => []];
        $allowed = ['FldName', 'FldTypeName', 'FldRequired', 'FldReq', 'FldIsPrimaryKey', 'FldAutoIncrement', 'FldAdd', 'FldEdit', 'FldView', 'FldList', 'FldHtmlTagReadOnly',
            'FldSelectType', 'FldTagLnkTbl', 'FldTagLnkFld', 'FldTagLnkDisplay', 'FldTagLnkDisp2', 'FldTagLnkDisp3', 'FldTagLnkDisp4', 'FldParentSelect', 'FldSelectFilterFld', 'FldAutoFillSourceFields', 'FldAutoFillTargetFields'];
        foreach ($xml->xpath('//Table') as $table) {
            $fields = [];
            foreach ($table->Field as $field) {
                $item = [];
                foreach ($allowed as $key) {
                    if (isset($field[$key])) {
                        $item[$key] = (string) $field[$key];
                    }
                }
                $item['has_default'] = isset($field['FldDefault']);
                $item['has_filter'] = isset($field['FldTagFilter']);
                $fields[] = $item;
            }
            $out['tables'][(string) $table['TblName']] = ['type' => (string) $table['TblType'], 'generated' => (string) $table['TblGen'] === '1', 'fields' => $fields];
        }
        foreach ($xml->MasterDetail as $relation) {
            $fields = [];
            foreach ($relation->Relation as $field) {
                $fields[] = ['master' => (string) $field['MasterField'], 'detail' => (string) $field['DetailField']];
            }
            $out['relations'][] = ['master' => (string) $relation['MasterTable'], 'detail' => (string) $relation['DetailTable'], 'fields' => $fields];
        }
        foreach ($xml->CustomScript as $hook) {
            $out['hooks'][] = ['object' => (string) $hook['ScriptL1Key'], 'name' => (string) $hook['ScriptName'], 'type' => (string) $hook['ScriptCodeType'],
                'page' => (string) $hook['ScriptCtrlID'], 'sha256' => hash('sha256', (string) $hook)];
        }

        return $out;
    }
}
