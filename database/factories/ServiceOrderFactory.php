<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceOrder>
 */
class ServiceOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $warehouse = Warehouse::where('id', '>', 1)->inRandomOrder()->first();
        $user      = User::where('id', '>', 1)->inRandomOrder()->first();
        $contact   = Contact::inRandomOrder()->first();
        $phoneType = Product::where('category_id', 5)->inRandomOrder()->first();

        return [
            'date_issued'  => now(),
            'invoice'      => null,
            'order_number' => fake()->unique()->numerify('ORDER-####-#####'),
            'phone_number' => $contact->phone_number, // ambil dari ContactFactory
            'phone_type'   => $phoneType->name,
            'description'  => $this->faker->sentence(),
            'status'       => 'Pending',
            'technician_id' => null,
            'warehouse_id' => $warehouse->id,
            'user_id'      => $user->id,
        ];
    }
}
