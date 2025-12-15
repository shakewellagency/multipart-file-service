<?php

namespace App\Services\UploadService;

use App\Services\UploadService\File;
use App\Services\UploadService\UploadFailedException;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Log;

class FileService
{
    private S3Client $s3Client;

    private int $maxRetries;

    private int $partSize;

    private string $bucket;

    private string $region;

    public function __construct(
        protected readonly Guard $guard,
        ?S3Client $s3Client = null
    )
    {
        $config = config('filesystems.disks.s3');

        $this->bucket = $config['bucket'];
        $this->region = $config['region'];
        $this->partSize = config('filesystems.part_size', 5242880); // default 5MB
        $this->maxRetries = config('filesystems.s3_retry_count', 3);

        if ($s3Client) {
            $this->s3Client = $s3Client;
            return;
        }

        $s3Config = [
            'version' => 'latest',
            'region' => $this->region,
        ];

        // Use explicit credentials only if both key and secret are provided.
        if (!empty($config['key']) && !empty($config['secret'])) {
            $s3Config['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
        }

        $this->s3Client = new S3Client($s3Config);
    }

    public function initiateMultipartUpload(string $filename, string $contentType, int $size, string $directory, string $visibility = 'private'): array
    {
        $fileName = htmlspecialchars($filename);
        $key = $directory . '/' . time() . '_' . uniqid() . '_' . $fileName;
        $fileSize = $size;

        try {
            // Create multipart upload with retry
            $result = retry($this->maxRetries, function () use ($key, $contentType) {
                return $this->s3Client->createMultipartUpload([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'ContentType' => $contentType,
                ]);
            }, 500);

            $uploadId = $result['UploadId'];

            // Calculate number of parts
            $totalParts = $fileSize > 0 ? (int)ceil($fileSize / $this->partSize) : 1;

            // Generate pre-signed URLs for each part
            $parts = [];
            for ($partNumber = 1; $partNumber <= $totalParts; $partNumber++) {
                $command = $this->s3Client->getCommand('UploadPart', [
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                    'PartNumber' => $partNumber,
                ]);

                $presignedUrl = (string)$this->s3Client->createPresignedRequest(
                    $command,
                    '+60 minutes'
                )->getUri();

                $parts[] = [
                    'partNumber' => $partNumber,
                    'url' => $presignedUrl,
                ];
            }

            // Create File record
            $file = File::create([
                'user_id' => $this->guard->id(),
                'name' => $key,
                'original_name' => $fileName,
                'path' => $key,
                'disk' => 's3',
                'mime_type' => $contentType,
                'size' => $fileSize,
                'visibility' => $visibility,
                'status' => 'initiated',
                'upload_id' => $uploadId,
            ]);

        } catch (S3Exception $e) {
            Log::error('FileService::initiateMultipartUpload', [
                'log_for' => 'S3 multipart initiate failed',
                'error' => $e->getMessage(),
                'file_name' => $fileName,
                'bucket' => $this->bucket,
                'key' => $key,
                'aws_error_code' => $e->getAwsErrorCode(),
            ]);

            throw new UploadFailedException(500, 'Failed to initiate multipart upload');
        } catch (AwsException $e) {
            Log::error('FileService::initiateMultipartUpload', [
                'log_for' => 'AWS error during multipart initiate',
                'error' => $e->getMessage(),
                'file_name' => $fileName,
            ]);

            throw new UploadFailedException(500, 'Upload provider service error');
        } catch (\Throwable $e) {
            Log::error('FileService::initiateMultipartUpload', [
                'log_type' => 'system error',
                'error' => $e->getMessage(),
                'file_name' => $fileName,
            ]);

            throw new UploadFailedException(500, 'System error during upload initiation');
        }

        return [
            'file_id' => $file->id,
            'uploadId' => $uploadId,
            'key' => $key,
            'parts' => $parts,
        ];
    }

    public function completeMultipartUpload(string $path, array $parts): File
    {
        /** @var File $file */
        $file = File::query()
            ->where('path', $path)
            ->where('status', 'initiated')
            ->firstOrFail();

        try {
            // Complete multipart upload with retry
            retry($this->maxRetries, function () use ($file, $parts) {
                return $this->s3Client->completeMultipartUpload([
                    'Bucket' => $this->bucket,
                    'Key' => $file->path,
                    'UploadId' => $file->upload_id,
                    'MultipartUpload' => [
                        'Parts' => $parts,
                    ],
                ]);
            }, 500);

            $file->status = 'completed';
            $file->metadata = array_merge($file->metadata ?? [], ['parts_count' => count($parts)]);
            $file->save();

            return $file;

        } catch (S3Exception $e) {
            $file->status = 'failed';
            $file->save();

            Log::error('FileService::completeMultipartUpload', [
                'log_for' => 'S3 multipart complete failed',
                'error' => $e->getMessage(),
                'file_id' => $file->id,
                'key' => $file->path,
                'aws_error_code' => $e->getAwsErrorCode(),
            ]);

            throw new UploadFailedException(500, 'Failed to complete multipart upload');
        } catch (AwsException $e) {
            $file->status = 'failed';
            $file->save();

            Log::error('FileService::completeMultipartUpload', [
                'log_for' => 'AWS error during multipart complete',
                'error' => $e->getMessage(),
                'file_id' => $file->id,
            ]);

            throw new UploadFailedException(500, 'Upload provider service error');
        } catch (\Throwable $e) {
            Log::error('FileService::completeMultipartUpload', [
                'log_type' => 'system error',
                'error' => $e->getMessage(),
                'file_id' => $file->id,
            ]);

            throw new UploadFailedException(500, 'System error during upload completion');
        }
    }

    public function generateDownloadUrl(File $file, int $expiry = 3600): string
    {
        try {
            $command = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $file->path,
                'ResponseContentDisposition' => 'attachment; filename="' . $file->original_name . '"',
            ]);

            return (string)$this->s3Client->createPresignedRequest(
                $command,
                "+{$expiry} seconds"
            )->getUri();
        } catch (\Throwable $e) {
            Log::error('FileService::generateDownloadUrl', [
                'error' => $e->getMessage(),
                'file_id' => $file->id,
            ]);
            return '';
        }
    }

    public function deleteFile(File $file): bool
    {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $file->path,
            ]);

            return $file->delete();
        } catch (\Throwable $e) {
            Log::error('FileService::deleteFile', [
                'error' => $e->getMessage(),
                'file_id' => $file->id,
            ]);
            return false;
        }
    }

    public function copyFile(string $sourcePath, string $destPath): bool
    {
        // TODO: implement copy
        return false;
    }

    public function moveFile(string $sourcePath, string $destPath): bool
    {
        // Default implementation: copy then delete
        // TODO: implement atomic move if supported
        return false;
    }


    public function getFileMetadata(string $path): array
    {
        // TODO: implement metadata retrieval
        return [];
    }


    public function listFiles(string $directory): array
    {
        // TODO: implement listing
        return [];
    }

}
