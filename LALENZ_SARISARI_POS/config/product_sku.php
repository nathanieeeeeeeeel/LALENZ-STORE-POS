<?php

function productSkuPrefix(PDO $pdo, int $categoryId): string
{
    $statement = $pdo->prepare('SELECT name FROM categories WHERE id = ? LIMIT 1');
    $statement->execute([$categoryId]);
    $categoryName = $statement->fetchColumn();

    if (!is_string($categoryName)) {
        throw new RuntimeException('Cannot generate a SKU for an unknown category.');
    }

    $prefixes = [
        'beverages' => 'BEV',
        'condiments' => 'CON',
        'food' => 'FOOD',
        'household' => 'HOU',
        'load & e-services' => 'LES',
        'others' => 'OTH',
        'personal care' => 'PC',
        'softdrinks' => 'SOFT',
        'vegetables' => 'VEG',
    ];
    $normalizedName = strtolower(trim($categoryName));

    if (isset($prefixes[$normalizedName])) {
        return $prefixes[$normalizedName];
    }

    $words = preg_split('/[^a-z0-9]+/i', $categoryName, -1, PREG_SPLIT_NO_EMPTY);
    $prefix = '';
    foreach ($words ?: [] as $word) {
        $prefix .= strtoupper($word[0]);
    }

    if ($prefix === '') {
        throw new RuntimeException('Cannot generate a SKU for a category without a usable name.');
    }

    return substr($prefix, 0, 10);
}

function generateProductSku(PDO $pdo, int $categoryId): string
{
    $prefix = productSkuPrefix($pdo, $categoryId);

    $statement = $pdo->prepare('SELECT sku FROM products WHERE category_id = ? AND sku IS NOT NULL');
    $statement->execute([$categoryId]);
    $highestNumber = 0;

    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $existingSku) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/i', (string) $existingSku, $matches)) {
            $highestNumber = max($highestNumber, (int) $matches[1]);
        }
    }

    $exists = $pdo->prepare('SELECT 1 FROM products WHERE sku = ? LIMIT 1');
    do {
        $highestNumber++;
        $sku = sprintf('%s-%03d', $prefix, $highestNumber);
        $exists->execute([$sku]);
    } while ($exists->fetchColumn());

    return $sku;
}
