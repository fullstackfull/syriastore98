<?php
// Strip PHP comments through the tokenizer, for a census that must not be satisfied -- or
// broken -- by prose about the thing it measures (CLAUDE.md §3). A regex that removes /* */
// also removes it from inside a string literal, and the files this is pointed at contain SQL
// with both. Usage: php strip-php-comments.php <file>
$path = $argv[1] ?? '';
if ($path === '' || ! is_readable($path)) {
    fwrite(STDERR, "strip-php-comments: cannot read " . $path . "\n");
    exit(3);
}
$out = '';
foreach (token_get_all(file_get_contents($path)) as $tok) {
    if (is_array($tok) && ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT)) {
        continue;
    }
    $out .= is_array($tok) ? $tok[1] : $tok;
}
echo $out;
