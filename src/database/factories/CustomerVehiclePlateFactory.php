<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerVehiclePlate>
 *
 * Default: an Oman-style plate string. Caller MUST pass BOTH
 *   ->for($customer, 'customer')
 *   ->for($company, 'company')
 * where the company matches the customer's company. Without
 * the explicit company, a fresh Company gets minted — almost
 * never what tests want, and the (company_id, plate_number)
 * unique constraint becomes a mystery to debug.
 */
class CustomerVehiclePlateFactory extends Factory
{
    public function definition(): array
    {
        // Oman plate-like form: 5 digits + space + uppercase
        // letter. Random suffix keeps the (company_id,
        // plate_number) unique-constraint trip from blocking
        // factory()->count() loops within the same company.
        $digits = str_pad((string) random_int(0, 99_999), 5, '0', STR_PAD_LEFT);
        $letters = strtoupper(Str::random(2));

        return [
            'uuid' => (string) Str::uuid(),
            'customer_id' => Customer::factory(),
            'company_id' => Company::factory(),
            'plate_number' => $digits . ' ' . $letters,
        ];
    }
}
