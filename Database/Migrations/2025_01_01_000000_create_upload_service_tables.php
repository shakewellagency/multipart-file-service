<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config("upload_service.tables_prefix", "");
        $filesTable = $prefix . 'files';

        Schema::create($filesTable, function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('original_name');
            $table->string('path')->index();
            $table->string('disk')->default('s3');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('visibility')->default('private'); // public, private
            $table->string('status')->default('initiated'); // initiated, completed, failed
            $table->string('upload_id')->nullable(); // AWS Multipart Upload ID
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create($prefix.'file_viewers', function (Blueprint $table) use ($filesTable) {
            $table->id();
            // Fix: Use the prefixed table name for the constraint
            $table->foreignId('file_id')->constrained($filesTable)->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['file_id', 'user_id']);
        });

        Schema::create($prefix.'fileables', function (Blueprint $table) use ($filesTable) {
            $table->id();
            // Fix: Use the prefixed table name for the constraint
            $table->foreignId('file_id')->constrained($filesTable)->cascadeOnDelete();
            $table->morphs('fileable');
            $table->timestamps();

            $table->unique(['file_id', 'fileable_id', 'fileable_type']);
        });
    }

    public function down(): void
    {
        $prefix = config("upload_service.tables_prefix", "");

        // Fix: Drop tables with the correct prefix
        Schema::dropIfExists($prefix.'fileables');
        Schema::dropIfExists($prefix.'file_viewers');
        Schema::dropIfExists($prefix.'files');
    }
};
