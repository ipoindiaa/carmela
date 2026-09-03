<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertOwnerDealerVisibility($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$newEntry = file_get_contents($root . '/transactions/new.php');
$carView = file_get_contents($root . '/cars/view.php');

assertOwnerDealerVisibility(
    str_contains($newEntry, "Owner's Name <span class=\"text-muted\">(Vehicle Owner / Seller)</span>")
        && str_contains($newEntry, "Dealer's Name <span class=\"text-muted\">(Purchase Dealer / Broker)</span>"),
    'Bought a Car presents linked owner and dealer name fields'
);
assertOwnerDealerVisibility(
    str_contains($newEntry, 'Purchase Owner &amp; Dealer')
        && str_contains($newEntry, "<tr><td class=\"text-muted\">Owner's Name</td><td id=\"ps-owner\">—</td></tr>")
        && str_contains($newEntry, "<tr><td class=\"text-muted\">Dealer's Name</td><td id=\"ps-dealer\">—</td></tr>"),
    'Sell Car shows the purchased car owner and dealer names as read-only facts'
);
assertOwnerDealerVisibility(
    str_contains($carView, "<tr><td class=\"text-muted\">Owner's Name</td><td>")
        && str_contains($carView, "<tr><td class=\"text-muted\">Dealer's Name</td><td>"),
    'Car Details displays owner and dealer names'
);

echo "Owner and dealer visibility contract completed.\n";
