<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertUiContract($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$legacyCssPath = $root . '/assets/css/style.css';
$uiCssPath = $root . '/assets/css/ui-system.css';
$guidelinesPath = $root . '/docs/UI_GUIDELINES.md';
$auditPath = $root . '/docs/UI_AUDIT.md';

assertUiContract(is_file($uiCssPath), 'The authoritative UI stylesheet exists');
assertUiContract(is_file($guidelinesPath), 'The UI governance document exists');
assertUiContract(is_file($auditPath), 'The system-wide UI audit exists');

$uiCss = file_get_contents($uiCssPath);
$legacyCss = file_get_contents($legacyCssPath);
$combinedCss = $legacyCss . "\n" . $uiCss;

$requiredTokens = [
    '--surface-page',
    '--surface-panel',
    '--text-strong',
    '--text-default',
    '--primary',
    '--space-1',
    '--space-2',
    '--space-3',
    '--space-4',
    '--space-6',
    '--radius-control',
    '--radius-panel',
    '--control-height-md',
    '--page-gutter',
    '--panel-padding',
    '--section-gap',
];
foreach ($requiredTokens as $token) {
    assertUiContract(
        preg_match('/' . preg_quote($token, '/') . '\s*:/', $uiCss) === 1,
        "UI token {$token} is defined"
    );
}

preg_match_all('/(--[A-Za-z0-9_-]+)\s*:/', $combinedCss, $definitionMatches);
preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $combinedCss, $usageMatches);
$definedVariables = array_unique($definitionMatches[1]);
$usedVariables = array_unique($usageMatches[1]);
$undefinedVariables = array_values(array_diff($usedVariables, $definedVariables));
assertUiContract(
    empty($undefinedVariables),
    'Every CSS custom property has a definition' . ($undefinedVariables ? ': ' . implode(', ', $undefinedVariables) : '')
);

$legacyWithoutRoot = preg_replace('/\A.*?:root\s*\{.*?\}\s*/s', '', $legacyCss, 1);
assertUiContract(
    !preg_match('/#[0-9a-fA-F]{3,8}\b/', (string) $legacyWithoutRoot),
    'Legacy feature styles consume color tokens instead of one-off hex values'
);
assertUiContract(
    !preg_match('/(?<![-a-z0-9_])(?:margin(?:-[a-z-]+)?|padding(?:-[a-z-]+)?|gap|row-gap|column-gap)\s*:[^;}]*\d+(?:\.\d+)?px/i', $combinedCss),
    'Spacing declarations consume the shared spacing scale'
);
assertUiContract(
    !preg_match('/(?<![-a-z0-9_])font-size\s*:[^;}]*\d+(?:\.\d+)?px/i', $combinedCss),
    'Typography declarations consume the shared type scale'
);

$shellFiles = [
    'includes/header.php',
    'login.php',
    'setup.php',
];
foreach ($shellFiles as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    $legacyPosition = strpos($source, 'assets/css/style.css');
    $uiPosition = strpos($source, 'assets/css/ui-system.css');
    assertUiContract(
        $legacyPosition !== false && $uiPosition !== false && $uiPosition > $legacyPosition,
        "{$relative} loads the authoritative UI layer after the feature stylesheet"
    );
}

$templateRoots = [
    'cars', 'employees', 'outside-cars', 'parties', 'partners', 'reports',
    'rto', 'settings', 'transactions', 'includes',
];
$templateFiles = [];
foreach ($templateRoots as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $templateFiles[] = $file->getPathname();
        }
    }
}
foreach (['dashboard.php', 'delete_record.php', 'login.php', 'setup.php'] as $file) {
    $templateFiles[] = $root . '/' . $file;
}

$inlineStyleViolations = [];
$buttonTypeViolations = [];
$externalLinkViolations = [];
foreach ($templateFiles as $path) {
    $source = file_get_contents($path);
    $relative = str_replace($root . '/', '', $path);
    if (preg_match_all('/\sstyle\s*=\s*["\']/i', $source, $matches)) {
        $inlineStyleViolations[] = $relative . ' (' . count($matches[0]) . ')';
    }
    if (preg_match_all('/<button\b(?![^>]*\btype\s*=)[^>]*>/i', $source, $matches)) {
        $buttonTypeViolations[] = $relative . ' (' . count($matches[0]) . ')';
    }
    if (preg_match_all('/<a\b(?=[^>]*\btarget\s*=\s*["\']_blank["\'])(?![^>]*\brel\s*=)[^>]*>/i', $source, $matches)) {
        $externalLinkViolations[] = $relative . ' (' . count($matches[0]) . ')';
    }
}
assertUiContract(
    empty($inlineStyleViolations),
    'PHP templates contain no inline style attributes' . ($inlineStyleViolations ? ': ' . implode(', ', $inlineStyleViolations) : '')
);
assertUiContract(
    empty($buttonTypeViolations),
    'Every template button declares its behavior' . ($buttonTypeViolations ? ': ' . implode(', ', $buttonTypeViolations) : '')
);
assertUiContract(
    empty($externalLinkViolations),
    'Every new-tab link declares a safe rel policy' . ($externalLinkViolations ? ': ' . implode(', ', $externalLinkViolations) : '')
);

assertUiContract(
    str_contains($uiCss, '.page-content')
        && str_contains($uiCss, 'gap: var(--section-gap)')
        && str_contains($uiCss, '.card-body')
        && str_contains($uiCss, 'padding: var(--panel-padding)'),
    'Page rhythm and panel padding are controlled by shared semantic tokens'
);
assertUiContract(
    str_contains($uiCss, '@media (max-width: 768px)')
        && str_contains($uiCss, '@media (max-width: 480px)')
        && str_contains($uiCss, '@media (prefers-reduced-motion: reduce)'),
    'The UI contract includes touch, narrow-screen, and reduced-motion behavior'
);
assertUiContract(
    str_contains($uiCss, 'min-height: 44px')
        && str_contains($uiCss, '--control-height-md: 44px'),
    'Touch layouts preserve a minimum 44px interactive target'
);
assertUiContract(
    !str_contains($legacyCss, 'UI System v2'),
    'Superseded shared UI overrides were removed from the feature stylesheet'
);

printf("UI contract audit completed: %d templates checked.\n", count($templateFiles));
