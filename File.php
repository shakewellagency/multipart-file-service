<?php

namespace App\Services\UploadService;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends Model
{
    use SoftDeletes;

    public function getTable()
    {
        return config('upload_service.tables_prefix', 'upload_service_') . 'files';
    }

    protected $fillable = [
        'user_id',
        'name',           // The name stored in storage
        'original_name',  // The original filename uploaded by user
        'path',           // Full path or key in storage
        'disk',           // s3, local, etc.
        'mime_type',
        'size',
        'visibility',     // 'public', 'private'
        'status',         // 'initiated', 'completed', 'failed'
        'upload_id',      // AWS Multipart Upload ID
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
    ];

    /**
     * The user who uploaded the file.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Users who have been granted view access to this file.
     */
    public function viewers(): BelongsToMany
    {
        $viewersTable = config('upload_service.tables_prefix', 'upload_service_') . 'file_viewers';

        return $this->belongsToMany(User::class, $viewersTable, 'file_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Check if the file is accessible by the given user.
     */
    public function isAccessibleBy(?User $user): bool
    {
        if ($this->visibility === 'public') {
            return true;
        }

        if (!$user) {
            return false;
        }

        // Uploader can always access
        if ($this->user_id === $user->id) {
            return true;
        }

        // Check viewers
        return $this->viewers()->where('users.id', $user->id)->exists();
    }
}
