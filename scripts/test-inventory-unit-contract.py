#!/usr/bin/env python3
"""Fail-closed source contract for unit interpretation (issue #28)."""
from pathlib import Path
s = (Path(__file__).resolve().parents[1] / 'api/index.php').read_text()

def between(start, end):
    a = s.index(start); b = s.index(end, a + len(start)); return s[a:b]

add = between('function addToInventory(PDO $db): void {', 'function _isWasteNotes(')
imp = between('function inventoryImportAddStock(', 'function importInventory(')
upd = between('function executeProductUpdate(', 'function normalizeProductName(')
assert 'function inventoryUnitConversionFactor(' in s, 'Missing shared unit conversion helper'
assert 'inventoryUnitConversionFactor(' in add, 'Online add must convert incoming quantity before stock write'
assert 'inventoryUnitConversionFactor(' in imp, 'CSV import must share conversion semantics'
assert 'inventoryLiveStockRows(' in add, 'Online add must protect existing stock'
assert 'inventoryLiveStockRows(' in imp, 'CSV import must protect existing stock'
assert 'unit=CASE WHEN EXISTS(SELECT 1 FROM inventory' in upd, 'Product edit must protect existing stock atomically'
assert add.index('inventoryUnitConversionFactor(') < add.index('inventoryMinAddQuantity('), 'Apply minimum after unit conversion'
print('PASS: conversion and stable unit interpretation guarded across add/import/edit')
