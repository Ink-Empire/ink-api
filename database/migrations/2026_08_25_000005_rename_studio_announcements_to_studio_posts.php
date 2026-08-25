<?php

use App\Enums\StudioPostStatus;
use App\Enums\StudioPostType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Announcements and guides share a publishing envelope - title, body, status,
 * slug, SEO - and differ only in whether they carry dates or a default flag.
 * They live in one table rather than two so there is one editor, one renderer
 * and one sitemap query.
 *
 * Existing rows become general announcements, published at their creation
 * date, so nothing changes for anyone until the new types are used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('studio_announcements', 'studio_posts');

        Schema::table('studio_posts', function (Blueprint $table) {
            $table->string('type')->default(StudioPostType::General->value)->after('studio_id');
            $table->string('slug')->nullable()->after('title');
            $table->text('excerpt')->nullable()->after('slug');
            $table->foreignId('media_id')->nullable()->after('content')->constrained('images')->nullOnDelete();

            $table->string('status')->default(StudioPostStatus::Published->value)->after('media_id');
            $table->timestamp('published_at')->nullable()->after('status');

            // Announcements only: when the notice starts and stops applying.
            $table->timestamp('starts_at')->nullable()->after('published_at');
            $table->timestamp('ends_at')->nullable()->after('starts_at');

            // Guides only: the aftercare guide sent after an appointment.
            $table->boolean('is_default')->default(false)->after('ends_at');

            $table->string('meta_title')->nullable()->after('is_default');
            $table->string('meta_description')->nullable()->after('meta_title');

            $table->index(['studio_id', 'type']);
            $table->unique(['studio_id', 'slug'], 'studio_posts_studio_slug_unique');
        });

        // Backfill: everything that exists today is a general announcement that
        // has always been live.
        DB::table('studio_posts')->update([
            'type' => StudioPostType::General->value,
            'status' => StudioPostStatus::Published->value,
        ]);

        DB::table('studio_posts')->whereNull('published_at')->update([
            'published_at' => DB::raw('created_at'),
        ]);

        foreach (DB::table('studio_posts')->whereNull('slug')->get() as $post) {
            $base = Str::slug($post->title) ?: 'post';
            $slug = $base;
            $suffix = 2;

            while (DB::table('studio_posts')
                ->where('studio_id', $post->studio_id)
                ->where('slug', $slug)
                ->exists()
            ) {
                $slug = $base.'-'.$suffix;
                $suffix++;
            }

            DB::table('studio_posts')->where('id', $post->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('studio_posts', function (Blueprint $table) {
            $table->dropUnique('studio_posts_studio_slug_unique');
            $table->dropIndex(['studio_id', 'type']);
            $table->dropForeign(['media_id']);
            $table->dropColumn([
                'type', 'slug', 'excerpt', 'media_id', 'status', 'published_at',
                'starts_at', 'ends_at', 'is_default', 'meta_title', 'meta_description',
            ]);
        });

        Schema::rename('studio_posts', 'studio_announcements');
    }
};
