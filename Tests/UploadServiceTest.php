<?php

use App\Models\User;
use App\Services\UploadService\File;
use App\Services\UploadService\FileService;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Auth;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;


beforeEach(function () {
    // Mock S3Client
    $this->s3ClientMock = Mockery::mock(S3Client::class);

    // Bind the mock to the service container or inject it
    // Since FileService is a singleton in the provider, we need to re-bind it with the mock
    $this->app->bind(FileService::class, function ($app) {
        return new FileService($app['auth']->guard(), $this->s3ClientMock);
    });
});

test('can initiate multipart upload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Mock S3 responses
    $this->s3ClientMock->shouldReceive('createMultipartUpload')
        ->once()
        ->andReturn(new Result(['UploadId' => 'test-upload-id']));

    $this->s3ClientMock->shouldReceive('getCommand')
        ->andReturn(Mockery::mock(CommandInterface::class));

    $uriMock = Mockery::mock(UriInterface::class);
    $uriMock->shouldReceive('__toString')->andReturn('http://s3-presigned-url');

    $requestMock = Mockery::mock(RequestInterface::class);
    $requestMock->shouldReceive('getUri')->andReturn($uriMock);

    $this->s3ClientMock->shouldReceive('createPresignedRequest')
        ->andReturn($requestMock);

    $response = $this->postJson('/api/files/initiate', [
        'filename' => 'test.jpg',
        'content_type' => 'image/jpeg',
        'size' => 1024 * 1024 * 5, // 5MB
        'directory' => 'uploads',
        'visibility' => 'private',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'file_id',
            'uploadId',
            'key',
            'parts'
        ]);

    $this->assertDatabaseHas(config('upload_service.tables_prefix').'files', [
        'original_name' => 'test.jpg',
        'user_id' => $user->id,
        'status' => 'initiated',
        'upload_id' => 'test-upload-id',
    ]);
});

test('can complete multipart upload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::create([
        'user_id' => $user->id,
        'name' => 'uploads/test_key',
        'original_name' => 'test.jpg',
        'path' => 'uploads/test_key',
        'disk' => 's3',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'visibility' => 'private',
        'status' => 'initiated',
        'upload_id' => 'test-upload-id',
    ]);

    $this->s3ClientMock->shouldReceive('completeMultipartUpload')
        ->once()
        ->with(Mockery::on(function ($args) use ($file) {
            return $args['Bucket'] === config('filesystems.disks.s3.bucket') &&
                   $args['Key'] === $file->path &&
                   $args['UploadId'] === $file->upload_id;
        }))
        ->andReturn(new Result([]));

    $parts = [
        ['ETag' => 'etag1', 'PartNumber' => 1]
    ];

    $response = $this->postJson('/api/files/complete', [
        'path' => $file->path,
        'parts' => $parts,
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas(config('upload_service.tables_prefix').'files', [
        'id' => $file->id,
        'status' => 'completed',
    ]);
});

test('can show file details and download url', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::create([
        'user_id' => $user->id,
        'name' => 'uploads/test_key',
        'original_name' => 'test.jpg',
        'path' => 'uploads/test_key',
        'disk' => 's3',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'visibility' => 'private',
        'status' => 'completed',
    ]);

    $this->s3ClientMock->shouldReceive('getCommand')
        ->once()
        ->andReturn(Mockery::mock(CommandInterface::class));

    $uriMock = Mockery::mock(UriInterface::class);
    $uriMock->shouldReceive('__toString')->andReturn('http://download-url');

    $requestMock = Mockery::mock(RequestInterface::class);
    $requestMock->shouldReceive('getUri')->andReturn($uriMock);

    $this->s3ClientMock->shouldReceive('createPresignedRequest')
        ->once()
        ->andReturn($requestMock);

    $response = $this->getJson("/api/files/{$file->id}");

    $response->assertStatus(200)
        ->assertJson([
            'file' => [
                'id' => $file->id,
                'original_name' => 'test.jpg',
            ],
            'download_url' => 'http://download-url',
        ]);
});

test('cannot access private file of another user', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $file = File::create([
        'user_id' => $owner->id,
        'name' => 'uploads/test_key',
        'original_name' => 'test.jpg',
        'path' => 'uploads/test_key',
        'disk' => 's3',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'visibility' => 'private',
        'status' => 'completed',
    ]);

    $this->actingAs($otherUser);

    $response = $this->getJson("/api/files/{$file->id}");

    $response->assertStatus(403);
});

test('can access public file of another user', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $file = File::create([
        'user_id' => $owner->id,
        'name' => 'uploads/test_key',
        'original_name' => 'test.jpg',
        'path' => 'uploads/test_key',
        'disk' => 's3',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'visibility' => 'public',
        'status' => 'completed',
    ]);

    $this->actingAs($otherUser);

    // Mock S3 for download URL generation
    $this->s3ClientMock->shouldReceive('getCommand')->andReturn(Mockery::mock(CommandInterface::class));
    $uriMock = Mockery::mock(UriInterface::class);
    $uriMock->shouldReceive('__toString')->andReturn('http://download-url');
    $requestMock = Mockery::mock(RequestInterface::class);
    $requestMock->shouldReceive('getUri')->andReturn($uriMock);
    $this->s3ClientMock->shouldReceive('createPresignedRequest')->andReturn($requestMock);

    $response = $this->getJson("/api/files/{$file->id}");

    $response->assertStatus(200);
});

test('can add and remove viewers', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();

    // FIX: Use actingAs instead of $this->actingAs
    $this->actingAs($owner);

    $file = File::create([
        'user_id' => $owner->id,
        'name' => 'uploads/test_key',
        'original_name' => 'test.jpg',
        'path' => 'uploads/test_key',
        'disk' => 's3',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'visibility' => 'private',
        'status' => 'completed',
    ]);

    // Add viewer
    $response = $this->postJson("/api/files/{$file->id}/viewers", [
        'user_id' => $viewer->id,
    ]);

    $response->assertStatus(200);
    expect($file->viewers()->where('users.id', $viewer->id)->exists())->toBeTrue();

    // Viewer can now access
    // FIX: Use actingAs to switch user
    $this->actingAs($viewer);

    // Mock S3
    $this->s3ClientMock->shouldReceive('getCommand')->andReturn(Mockery::mock(CommandInterface::class));
    $uriMock = Mockery::mock(UriInterface::class);
    $uriMock->shouldReceive('__toString')->andReturn('http://download-url');
    $requestMock = Mockery::mock(RequestInterface::class);
    $requestMock->shouldReceive('getUri')->andReturn($uriMock);
    $this->s3ClientMock->shouldReceive('createPresignedRequest')->andReturn($requestMock);

    $this->getJson("/api/files/{$file->id}")->assertStatus(200);

    // Remove viewer (switch back to owner)
    // FIX: Use actingAs to switch back
    $this->actingAs($owner);
    $response = $this->deleteJson("/api/files/{$file->id}/viewers/{$viewer->id}");

    $response->assertStatus(200);
    expect($file->viewers()->where('users.id', $viewer->id)->exists())->toBeFalse();
});

test('can delete file', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::create([
        'user_id' => $user->id,
        'name' => 'uploads/test_key',
        'original_name' => 'test.jpg',
        'path' => 'uploads/test_key',
        'disk' => 's3',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'visibility' => 'private',
        'status' => 'completed',
    ]);

    $this->s3ClientMock->shouldReceive('deleteObject')
        ->once()
        ->with([
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $file->path,
        ]);

    $response = $this->deleteJson("/api/files/{$file->id}");

    $response->assertStatus(200);
    $this->assertSoftDeleted(config('upload_service.tables_prefix').'files', ['id' => $file->id]);
});

