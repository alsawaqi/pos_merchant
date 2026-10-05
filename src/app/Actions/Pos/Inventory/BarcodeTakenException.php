<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use RuntimeException;

/**
 * Fix order B-1 (M2) — the barcode is already on another live item. Carries
 * the holding pos_item_barcodes row's uuid (null when it is a product's own
 * barcode) so the portal can offer to remove it from there.
 */
final class BarcodeTakenException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $barcodeUuid)
    {
        parent::__construct($message);
    }
}
