<?php

namespace App\Services\UploadService\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadService\FileService;
use App\Services\UploadService\Http\Requests\CompleteUploadRequest;
use App\Services\UploadService\Http\Requests\InitiateUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileController extends Controller
{
    public function __construct(
        protected FileService $fileService
    ) {}

    public function initiate(InitiateUploadRequest $request): JsonResponse
    {
        $data = $this->fileService->initiateMultipartUpload(
            $request->validated('filename'),
            $request->validated('content_type'),
            $request->validated('size'),
            $request->validated('directory', 'uploads'),
            $request->validated('visibility', 'private')
        );

        return response()->json($data);
    }

    public function complete(CompleteUploadRequest $request): JsonResponse
    {
        $file = $this->fileService->completeMultipartUpload(
            $request->validated('path'),
            $request->validated('parts')
        );

        return response()->json($file);
    }

    public function show(string $id): JsonResponse
    {
        $file = \App\Services\UploadService\File::findOrFail($id);

        // Check access
        if (!$file->isAccessibleBy(auth()->user())) {
            abort(403, 'Unauthorized access to file');
        }

        $downloadUrl = $this->fileService->generateDownloadUrl($file);

        return response()->json([
            'file' => $file,
            'download_url' => $downloadUrl
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $file = \App\Services\UploadService\File::findOrFail($id);

        // Only uploader can delete
        if ($file->user_id !== auth()->id()) {
            abort(403, 'Only the uploader can delete this file');
        }

        $this->fileService->deleteFile($file);

        return response()->json(['message' => 'File deleted successfully']);
    }

    public function addViewer(string $id, Request $request): JsonResponse
    {
        $file = \App\Services\UploadService\File::findOrFail($id);

        if ($file->user_id !== auth()->id()) {
            abort(403, 'Only the uploader can manage viewers');
        }

        $request->validate(['user_id' => 'required|exists:users,id']);

        $file->viewers()->syncWithoutDetaching([$request->user_id]);

        return response()->json(['message' => 'Viewer added successfully']);
    }

    public function removeViewer(string $id, string $userId): JsonResponse
    {
        $file = \App\Services\UploadService\File::findOrFail($id);

        if ($file->user_id !== auth()->id()) {
            abort(403, 'Only the uploader can manage viewers');
        }

        $file->viewers()->detach($userId);

        return response()->json(['message' => 'Viewer removed successfully']);
    }
}
