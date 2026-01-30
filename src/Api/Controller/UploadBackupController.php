<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UploadBackupController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertAdmin();

            $uploadedFiles = $request->getUploadedFiles();
            
            if (!isset($uploadedFiles['backup'])) {
                return new JsonResponse(['success' => false, 'error' => 'No file uploaded'], 400);
            }

            $file = $uploadedFiles['backup'];
            
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return new JsonResponse(['success' => false, 'error' => 'Upload failed with error code: ' . $file->getError()], 400);
            }

            $filename = $file->getClientFilename();
            if (pathinfo($filename, PATHINFO_EXTENSION) !== 'zip') {
                return new JsonResponse(['success' => false, 'error' => 'Only ZIP files are allowed'], 400);
            }

            $uploadPath = storage_path('migration_upload');
            if (!is_dir($uploadPath)) {
                @mkdir($uploadPath, 0755, true);
            }

            $newFilename = "uploaded_backup_" . time() . ".zip";
            $targetPath = $uploadPath . '/' . $newFilename;

            $file->moveTo($targetPath);

            return new JsonResponse([
                'success' => true,
                'filename' => $newFilename,
                'path' => $targetPath,
                'size' => filesize($targetPath)
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
