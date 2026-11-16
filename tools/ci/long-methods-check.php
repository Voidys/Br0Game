<?php
/**
 * Lists functions/methods longer than 80 lines (token based) and exits with code 1 if there are any.
 * Usage: php tools/ci/long-methods-check.php src
 */
$found = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1]));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $t = token_get_all(file_get_contents($f));
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_FUNCTION) continue;
        $name = '?'; $j = $i + 1;
        while ($j < $n && !(is_array($t[$j]) && $t[$j][0] === T_STRING) && $t[$j] !== '(') $j++;
        if (is_array($t[$j] ?? null)) $name = $t[$j][1];
        while ($j < $n && $t[$j] !== '{' && $t[$j] !== ';') $j++;
        if (($t[$j] ?? null) !== '{') continue;
        $start = null; $depth = 0;
        for ($k = $j; $k < $n; $k++) {
            if (is_array($t[$k]) && $start === null) $start = $t[$k][2];
            $c = is_array($t[$k]) ? $t[$k][1] : $t[$k];
            if ($c === '{' || (is_array($t[$k]) && in_array($t[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
            if ($c === '}') { $depth--; if ($depth === 0) break; }
        }
        $endLine = 0; for ($m = $k; $m >= 0; $m--) if (is_array($t[$m])) { $endLine = $t[$m][2]; break; }
        $startLine = is_array($t[$i]) ? $t[$i][2] : 0;
        $len = $endLine - $startLine;
        if ($len > 80) { echo "$len {$f}::$name:$startLine\n"; $found++; }
    }
}
exit($found > 0 ? 1 : 0);
