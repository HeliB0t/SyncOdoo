<?php
function dol_sanitizeFileName($value) { return (string) $value; }
function dol_mkdir($path) { return @mkdir($path, 0777, true) || is_dir($path) ? 1 : -1; }
function get_exdir() { return ''; }
function dol_now() { return time(); }
function dol_string_nohtmltag($v) { return strip_tags((string) $v); }
