<?php

declare(strict_types=1);

/**
 * Merchant-portal business switches (not auth — see pos_merchant_auth.php).
 */
return [
    'inventory' => [
        /*
         * LAUNCH-P2 P2-4 — one way to bring stock in for the pilot: Goods
         * received (purchase receipts), with a unit choice and the correct
         * weighted-average cost. While ON, the portal hides the branch
         * Restock button, the branch Purchase dialog and the central
         * warehouse Receive / Receive & distribute, and the server refuses
         * those endpoints with a 422. Allocation from the warehouse, device
         * restock requests, waste, transfers, adjustments and counts stay.
         */
        'single_stock_in' => (bool) env('POS_INVENTORY_SINGLE_STOCK_IN', true),
    ],

    /*
     * LAUNCH-P5 — the business day ("same day" for a shift re-open, the
     * Hours report days and the shift-end reminder) is the Muscat day, not
     * the UTC day the database stores.
     */
    'business_timezone' => env('POS_BUSINESS_TIMEZONE', 'Asia/Muscat'),

    /*
     * LAUNCH-P5 — PBKDF2-HMAC-SHA256 iterations for the offline approver
     * verifier made at PIN mint and reset (pos_staff.pin_offline_*). Stored
     * per row, so changing it later is safe; pos_api reads the same key.
     */
    'approver_kdf_iterations' => (int) env('POS_APPROVER_KDF_ITERATIONS', 100000),
];
