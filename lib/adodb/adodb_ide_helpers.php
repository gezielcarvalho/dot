<?php
/**
 * IDE helper and fallback implementations for ADOdb extension helpers.
 *
 * This file provides lightweight PHP implementations of optional
 * compiled helpers such as `adodb_getall` so language servers
 * (Intelephense) stop flagging undefined functions. It is wrapped
 * in `function_exists` checks so a real compiled extension will
 * take precedence at runtime.
 */

if (!function_exists('adodb_getall')) {
    /**
     * Return recordset as a 2-dimensional array.
     * @param object $rs Recordset object (ADODB_Iterator / ADORecordSet)
     * @param int $nRows Number of rows to return, -1 for all
     * @return array
     */
    function adodb_getall($rs, $nRows = -1) {
        $results = array();
        $cnt = 0;
        // Defensive checks: ensure we have the expected methods
        if (!is_object($rs) || !method_exists($rs, 'MoveNext') || !property_exists($rs, 'fields')) {
            return $results;
        }
        while (isset($rs->EOF) && !$rs->EOF && ($nRows == -1 || $cnt != $nRows)) {
            $results[] = $rs->fields;
            $rs->MoveNext();
            $cnt++;
        }
        return $results;
    }
}

if (!function_exists('adodb_movenext')) {
    /**
     * Move to the next row in the recordset helper (IDE stub).
     * Real extension will provide a faster implementation; this
     * fallback delegates to the recordset object's MoveNext() method.
     *
     * @param object $rs Recordset
     * @return bool
     */
    function adodb_movenext($rs) {
        if (!is_object($rs)) {
            return false;
        }
        if (method_exists($rs, 'MoveNext')) {
            return $rs->MoveNext();
        }
        // Some drivers use lowercase moveNext names
        if (method_exists($rs, 'movenext')) {
            return $rs->movenext();
        }
        return false;
    }
}
