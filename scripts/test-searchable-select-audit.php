<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertSearchableSelectAudit($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$selectCount = 0;
$pageCount = 0;
$nativeExceptions = [];
$pagesWithoutSharedEnhancer = [];
$explicitOptOuts = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $path = $file->getPathname();
    $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
    if (
        str_starts_with($relative, 'vendor' . DIRECTORY_SEPARATOR)
        || str_starts_with($relative, 'scripts' . DIRECTORY_SEPARATOR)
    ) continue;

    $source = file_get_contents($path);
    if (!preg_match_all('/<select\b[^>]*>/i', $source, $matches)) continue;

    $pageCount++;
    $selectCount += count($matches[0]);
    if (str_contains($source, 'data-searchable="false"')) {
        $explicitOptOuts[] = $relative;
    }

    foreach ($matches[0] as $tag) {
        if (preg_match('/\b(?:multiple|data-native-select)\b|\bsize\s*=\s*["\']?[2-9]/i', $tag)) {
            $nativeExceptions[] = "{$relative}: {$tag}";
        }
        if (str_contains($tag, 'native-transaction-select')) {
            $nativeExceptions[] = "{$relative}: transaction type selector";
            assertSearchableSelectAudit(
                str_contains($source, 'id="txn-type-search"'),
                'The special transaction type selector retains its dedicated search field'
            );
        }
    }

    $loadsSharedEnhancer = str_contains($source, 'includes/footer.php')
        || str_contains($source, 'assets/js/app.js');
    if (!$loadsSharedEnhancer) {
        $pagesWithoutSharedEnhancer[] = $relative;
    }
}

$appJs = file_get_contents($root . '/assets/js/app.js');
assertSearchableSelectAudit($selectCount > 0, 'Selector inventory is not empty');
assertSearchableSelectAudit(empty($explicitOptOuts), 'No selector opts out of search');
assertSearchableSelectAudit(empty($pagesWithoutSharedEnhancer), 'Every page containing a selector loads the shared selector enhancer');
assertSearchableSelectAudit(
    str_contains($appJs, "search.type = 'search'")
        && str_contains($appJs, "search.addEventListener('input'")
        && !str_contains($appJs, 'searchableCount')
        && !str_contains($appJs, 'select.dataset.searchable'),
    'The shared selector enhancer always creates and wires an inline search field'
);
assertSearchableSelectAudit(
    count($nativeExceptions) === 1,
    'The only native selector exception is the transaction type selector with its own search UI'
);

echo "Searchable selector audit completed: {$selectCount} selectors across {$pageCount} PHP pages.\n";
