<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Admin status rail is now: draft → planned → active → completed (or cancelled
     * at any pre-active step). `planned` is the "public-facing" draft — admin has
     * finalised the setup and members can now discover and request to join.
     * `draft` stays admin-only (invisible to other users).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE wishis MODIFY COLUMN status ENUM('draft','planned','active','completed','cancelled') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        // Collapse any 'planned' rows back to 'draft' before shrinking the enum.
        DB::statement("UPDATE wishis SET status = 'draft' WHERE status = 'planned'");
        DB::statement("ALTER TABLE wishis MODIFY COLUMN status ENUM('draft','active','completed','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
