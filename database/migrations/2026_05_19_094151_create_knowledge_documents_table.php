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
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
        
            $table->uuid('uuid')->unique();
        
            $table->string('title');
        
            $table->string('slug')->nullable()->unique();
        
            $table->enum('practice_area', [
                'property_law',
                'family_law',
                'wills_and_probate',
                'general'
            ])->default('general');
        
            $table->string('category')->nullable();
        
            $table->text('summary')->nullable();
        
            $table->longText('content');
        
            $table->enum('status', [
                'draft',
                'published',
                'archived'
            ])->default('draft');
        
            $table->enum('visibility', [
                'internal',
                'public',
                'ai_only'
            ])->default('ai_only');
        
            $table->enum('source_type', [
                'manual',
                'uploaded_document',
                'policy',
                'faq',
                'website',
                'generated'
            ])->default('manual');
        
            $table->string('source_reference')->nullable();
        
            $table->json('tags')->nullable();
        
            $table->json('metadata')->nullable();
        
            $table->integer('version')->default(1);
        
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        
            $table->timestamp('published_at')->nullable();
        
            $table->timestamp('last_used_at')->nullable();
        
            $table->timestamps();
        
            $table->index('practice_area');
            $table->index('status');
            $table->index('visibility');
            $table->index('category');
        
            $table->fullText(['title', 'content']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
