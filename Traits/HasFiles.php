<?php

namespace App\Services\UploadService\Traits;

use App\Services\UploadService\File;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasFiles
{
    /**
     * Get all the files for the model.
     */
    public function files(): MorphToMany
    {
        $tableName = config('upload_service.tables_prefix', 'upload_service_') . 'fileables';

        return $this->morphToMany(File::class, $tableName, 'fileables')
            ->withTimestamps();
    }

    /**
     * Attach a file to the model.
     */
    public function attachFile(File|int|string $file): void
    {
        $this->files()->attach($file);
    }

    /**
     * Detach a file from the model.
     */
    public function detachFile(File|int|string $file): void
    {
        $this->files()->detach($file);
    }
}
