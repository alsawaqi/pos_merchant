<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * LAUNCH item kind, F7 — report screens show ingredient quantities the
 * friendly way (1000 g / ml and above in kg / l); the CSV / Excel / PDF
 * exports keep the raw stored-unit numbers, so every quantity there must say
 * its unit: ingredient rows carry a `unit` column, and a total that adds
 * several ingredients says it is in mixed stored units.
 */
final class ReportUnits
{
    public const MIXED = 'mixed: each ingredient in its stored unit';
}
