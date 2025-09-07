<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('finances', function (Blueprint $table) {
            $table->id();
            $table->dateTime('date_issued');
            $table->dateTime('due_date');
            $table->string('invoice', 60)->index();
            $table->string('description', 160);
            $table->decimal('bill_amount', 15, 2);
            $table->decimal('payment_amount', 15, 2);
            $table->enum('status', ['Unpaid', 'Paid', 'Partially Paid'])->default('Unpaid');
            $table->integer('payment_nth');
            $table->enum('finance_type', ['Payable', 'Receivable']); // ✅ diperbaiki

            $table->foreignId('contact_id')->constrained('contacts')->onDelete('restrict');
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finances');
    }
};
