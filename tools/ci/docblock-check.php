<?php
/**
 * Lists classes without a class docblock and "complex" functions without a docblock in a directory tree.
 * complex = body >= 15 lines, or >= 4 branch points, or a public/protected method with a body > 5 lines.
 * Usage: php tools/ci/docblock-check.php src [--summary]   (exit code 1 when something is missing)
 */
$dir = $argv[1]; $summary = in_array('--summary', $argv, true);
$missing = []; $classesMissing = []; $fnTotal = 0; $fnComplex = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $t = token_get_all(file_get_contents($f)); $n = count($t);
    $prevSignificant = function (int $i) use ($t) {
        // walk back over modifiers/attributes/whitespace to find a doc comment
        for ($k = $i - 1; $k >= 0; $k--) {
            $tok = $t[$k];
            if (is_array($tok)) {
                if (in_array($tok[0], [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY], true)) continue;
                if ($tok[0] === T_DOC_COMMENT) return true;
                if ($tok[0] === T_COMMENT) continue; // allow // line comments between
                if ($tok[0] === T_ATTRIBUTE) return false;
                return false;
            }
            if ($tok === ']') { // skip attribute block #[...]
                $depth = 0;
                for (; $k >= 0; $k--) { $c = is_array($t[$k]) ? $t[$k][1] : $t[$k]; if ($c === ']') $depth++; if ($c === '[' ) $depth--; if (is_array($t[$k]) && $t[$k][0] === T_ATTRIBUTE) { $depth--; } if ($depth <= 0) break; }
                continue;
            }
            return false;
        }
        return false;
    };
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i])) continue;
        if (in_array($t[$i][0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            // skip ::class and anonymous classes
            $p = $i - 1; while ($p >= 0 && is_array($t[$p]) && $t[$p][0] === T_WHITESPACE) $p--;
            if ($p >= 0 && (is_array($t[$p]) ? $t[$p][0] === T_DOUBLE_COLON || $t[$p][0] === T_NEW : false)) continue;
            $j = $i + 1; while ($j < $n && !(is_array($t[$j]) && $t[$j][0] === T_STRING)) $j++;
            if (!$prevSignificant($i)) $classesMissing[] = "$f::" . ($t[$j][1] ?? '?');
            continue;
        }
        if ($t[$i][0] !== T_FUNCTION) continue;
        // skip closures / arrow fns: next significant token is '(' or '&('
        $j = $i + 1; while ($j < $n && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) $j++;
        if (!is_array($t[$j]) || $t[$j][0] !== T_STRING) continue;
        $name = $t[$j][1];
        // visibility
        $vis = 'public';
        for ($k = $i - 1; $k >= 0 && $k > $i - 12; $k--) { if (is_array($t[$k]) && in_array($t[$k][0], [T_PRIVATE, T_PROTECTED, T_PUBLIC], true)) { $vis = strtolower($t[$k][1]); break; } if (!is_array($t[$k]) && in_array($t[$k], [';', '{', '}'], true)) break; }
        while ($j < $n && $t[$j] !== '{' && $t[$j] !== ';') $j++;
        if (($t[$j] ?? null) !== '{') continue; // abstract/interface
        $depth = 0; $branches = 0; $startLine = $t[$i][2]; $end = $j;
        for ($k = $j; $k < $n; $k++) {
            $c = is_array($t[$k]) ? $t[$k][1] : $t[$k];
            if ($c === '{' || (is_array($t[$k]) && in_array($t[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
            if ($c === '}') { $depth--; if ($depth === 0) { $end = $k; break; } }
            if (is_array($t[$k]) && in_array($t[$k][0], [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_CASE, T_CATCH, T_BOOLEAN_AND, T_BOOLEAN_OR, T_COALESCE, T_MATCH], true)) $branches++;
            if ($c === '?') $branches++;
        }
        $endLine = $startLine; for ($m = $end; $m >= 0; $m--) if (is_array($t[$m])) { $endLine = $t[$m][2]; break; }
        $len = $endLine - $startLine;
        $fnTotal++;
        $complex = $len >= 15 || $branches >= 4 || (in_array($vis, ['public', 'protected'], true) && $len > 5 && $name !== '__construct');
        if (!$complex) continue;
        $fnComplex++;
        if (!$prevSignificant($i)) $missing[] = sprintf('%4d %2d %-9s %s::%s:%d', $len, $branches, $vis, $f, $name, $startLine);
    }
}
printf("functions: %d, complex: %d, complex without docblock: %d, classes without docblock: %d\n", $fnTotal, $fnComplex, count($missing), count($classesMissing));
if (!$summary) { foreach ($classesMissing as $c) echo "CLASS $c\n"; rsort($missing); foreach ($missing as $m) echo "$m\n"; }
exit(count($missing) + count($classesMissing) > 0 ? 1 : 0);
