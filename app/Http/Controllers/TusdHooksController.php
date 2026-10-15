<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\File;
use App\Models\UploadSession;
use App\Utils\FileHelper;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use App\Services\SettingsService;
use App\Services\AnonymousLimits;
use Symfony\Component\HttpFoundation\IpUtils;

class TusdHooksController extends Controller
{
    /**
     * Handle incoming tusd hook requests
     * tusd sends different hook types: pre-create, post-create, post-receive, post-finish, post-terminate
     * 
     * Security: This endpoint should only be called by the tusd process.
     * We validate the request comes from the loopback address (tusd runs in the same container).
     */
    public function handleHook(Request $request)
    {
        // Security: only the tusd process (same container, loopback) may call this endpoint.
        // The Caddyfile also answers 404 to everyone else; this is the second lock.
        // The real connection address, captured before TrustCloudflareIp rewrites it.
        $clientIp = $request->attributes->get('lnc_peer') ?? $request->server->get('REMOTE_ADDR');
        if (!is_string($clientIp) || !IpUtils::checkIp($clientIp, ['127.0.0.0/8', '::1/128'])) {
            Log::warning('tusd hook rejected: unauthorized source IP', [
                'ip' => $clientIp
            ]);
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $payload = $request->all();
        // Hook type is in the payload's Type field, not in headers
        $hookName = $payload['Type'] ?? null;

        Log::debug('tusd hook received', [
            'hook' => $hookName
        ]);

        switch ($hookName) {
            case 'pre-create':
                return $this->preCreate($request, $payload);
            case 'post-create':
                return $this->postCreate($request, $payload);
            case 'post-finish':
                return $this->postFinish($request, $payload);
            case 'post-terminate':
                return $this->postTerminate($request, $payload);
            default:
                // Other hooks (post-receive) - just acknowledge
                return response()->json(['ok' => true]);
        }
    }

    /**
     * Pre-create hook: Validate JWT, check user permissions, and enforce max upload size
     * Note: Upload ID is not available yet at this stage
     */
    protected function preCreate(Request $request, array $payload)
    {
        try {
            // Extract authorization header from the upload request metadata
            $authHeader = $payload['Event']['HTTPRequest']['Header']['Authorization'][0] ?? null;

            if (!$authHeader) {
                Log::warning('tusd pre-create: No authorization header');
                return response()->json([
                    'ok' => false,
                    'message' => 'Unauthorized: No authorization header'
                ], 401);
            }

            // Extract token from "Bearer <token>"
            $token = str_replace('Bearer ', '', $authHeader);

            // People who are not signed in use a short-lived upload token instead of a JWT
            if (AnonymousLimits::isAnonymousToken($token)) {
                return $this->preCreateAnonymous($payload, $token);
            }

            // Validate the JWT token
            $user = JWTAuth::setToken($token)->authenticate();

            if (!$user) {
                Log::warning('tusd pre-create: Invalid token');
                return response()->json([
                    'ok' => false,
                    'message' => 'Unauthorized: Invalid token'
                ], 401);
            }

            $fileSize = $payload['Event']['Upload']['Size'] ?? 0;
            $settingsService = app(SettingsService::class);
            $maxUploadSize = $settingsService->getMaxUploadSize();

            if ($maxUploadSize) {
                // Check individual file size
                if ($fileSize > $maxUploadSize) {
                    $maxSizeFormatted = $this->formatBytes($maxUploadSize);
                    Log::warning('tusd pre-create: File exceeds max upload size', [
                        'user_id' => $user->id,
                        'file_size' => $fileSize,
                        'max_size' => $maxUploadSize
                    ]);
                    return response()->json([
                        'ok' => false,
                        'message' => "File size exceeds maximum allowed size of {$maxSizeFormatted}"
                    ], 413);
                }

                // Check cumulative size of all pending uploads for this user
                // This prevents malicious users from bypassing frontend validation
                // by uploading multiple files that together exceed the limit
                $pendingUploadsSize = UploadSession::where('user_id', $user->id)
                    ->whereIn('status', ['pending', 'complete'])
                    ->sum('filesize');

                $totalSizeAfterUpload = $pendingUploadsSize + $fileSize;

                if ($totalSizeAfterUpload > $maxUploadSize) {
                    $maxSizeFormatted = $this->formatBytes($maxUploadSize);
                    $currentSizeFormatted = $this->formatBytes($pendingUploadsSize);
                    
                    Log::warning('tusd pre-create: Cumulative upload size exceeds max', [
                        'user_id' => $user->id,
                        'file_size' => $fileSize,
                        'pending_size' => $pendingUploadsSize,
                        'total_would_be' => $totalSizeAfterUpload,
                        'max_size' => $maxUploadSize
                    ]);

                    // Clean up all pending uploads for this user since they're trying to exceed the limit
                    $this->cleanupPendingUploads($user->id);

                    return response()->json([
                        'ok' => false,
                        'message' => "Total upload size would exceed maximum allowed size of {$maxSizeFormatted}. Current pending uploads: {$currentSizeFormatted}. All pending uploads have been cancelled."
                    ], 413);
                }
            }

            Log::info('tusd pre-create: Upload authorized', [
                'user_id' => $user->id,
                'file_size' => $fileSize
            ]);

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('tusd pre-create error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized: ' . $e->getMessage()
            ], 401);
        }
    }

    /**
     * Pre-create for someone who is not signed in. Every Light NC Share limit is checked here,
     * before a single byte is stored: this is the lock that matters.
     * Refusals go back to tusd as a rejection so the browser can show the message.
     */
    protected function preCreateAnonymous(array $payload, string $token)
    {
        $ip = $this->hookClientIp($payload);
        $data = AnonymousLimits::readToken($token, $ip);
        if (!$data) {
            return $this->rejectUpload(401, AnonymousLimits::MSG_TOKEN);
        }

        $upload = $payload['Event']['Upload'] ?? [];
        $metadata = $upload['MetaData'] ?? [];
        $size = $upload['Size'] ?? 0;

        if (($metadata['isBundle'] ?? 'false') === 'true') {
            return $this->rejectUpload(400, AnonymousLimits::MSG_BUNDLE);
        }
        // A file of unknown length could not be counted against the limits
        if (!empty($upload['SizeIsDeferred']) || !is_numeric($size) || $size < 0) {
            return $this->rejectUpload(400, AnonymousLimits::MSG_SIZE);
        }

        $held = AnonymousLimits::tokenSessions($data['jti']);
        if (
            $size > config('anonymous.max_share_bytes')
            || (clone $held)->count() + 1 > config('anonymous.max_files')
            || (int) (clone $held)->sum('filesize') + $size > config('anonymous.max_share_bytes')
        ) {
            return $this->rejectUpload(413, AnonymousLimits::MSG_SIZE);
        }

        if (AnonymousLimits::tooManyShares($ip)) {
            return $this->rejectUpload(429, AnonymousLimits::MSG_RATE);
        }

        if (AnonymousLimits::totalBytes() + $size > config('anonymous.total_bytes')) {
            return $this->rejectUpload(503, AnonymousLimits::MSG_FULL);
        }

        return response()->json(['ok' => true]);
    }

    /** tusd's way of refusing an upload with a status code and a message. */
    private function rejectUpload(int $status, string $message)
    {
        return response()->json([
            'ok' => false,
            'message' => $message,
            'RejectUpload' => true,
            'HTTPResponse' => ['StatusCode' => $status, 'Body' => $message],
        ]);
    }

    /**
     * The visitor's address as seen by tusd. Caddy puts the direct peer in X-Lnc-Peer
     * (overwriting anything the client sent) and Cloudflare adds CF-Connecting-IP.
     */
    private function hookClientIp(array $payload): string
    {
        $peer = $this->hookHeader($payload, 'X-Lnc-Peer');
        if ($peer === null) {
            // tusd's own view of the caller, "ip:port" or "[ipv6]:port"
            $remote = (string) ($payload['Event']['HTTPRequest']['RemoteAddr'] ?? '');
            $peer = trim(preg_replace('/:\d+$/', '', $remote), '[]');
        }

        return AnonymousLimits::clientIp($peer, $this->hookHeader($payload, 'CF-Connecting-IP'));
    }

    /** One header of the upload request that tusd forwarded, whatever its capitalisation. */
    private function hookHeader(array $payload, string $name): ?string
    {
        foreach ($payload['Event']['HTTPRequest']['Header'] ?? [] as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }

    /**
     * Clean up all pending uploads for a user (used when they exceed the limit)
     */
    private function cleanupPendingUploads(int $userId): void
    {
        $sessions = UploadSession::where('user_id', $userId)
            ->whereIn('status', ['pending', 'complete'])
            ->get();

        foreach ($sessions as $session) {
            // Delete the uploaded file from disk
            $uploadPath = storage_path('app/uploads/' . $session->upload_id);
            if (file_exists($uploadPath)) {
                unlink($uploadPath);
            }
            // Also delete the .info file that tusd creates
            $infoPath = $uploadPath . '.info';
            if (file_exists($infoPath)) {
                unlink($infoPath);
            }

            // Delete associated File record if exists
            if ($session->file_id) {
                $file = File::find($session->file_id);
                if ($file) {
                    $file->delete();
                }
            }

            $session->delete();
        }

        Log::info('tusd: Cleaned up pending uploads for user exceeding limit', [
            'user_id' => $userId,
            'sessions_deleted' => $sessions->count()
        ]);
    }

    /**
     * Post-create hook: Create upload session after tusd has assigned an ID
     */
    protected function postCreate(Request $request, array $payload)
    {
        try {
            // Extract authorization header to get user
            $authHeader = $payload['Event']['HTTPRequest']['Header']['Authorization'][0] ?? null;
            $token = str_replace('Bearer ', '', $authHeader);

            // Upload from someone who is not signed in: the session belongs to the upload token
            $anonymousToken = null;
            if (AnonymousLimits::isAnonymousToken($token)) {
                $anonymousToken = AnonymousLimits::readToken($token, $this->hookClientIp($payload));
                if (!$anonymousToken) {
                    return response()->json(['ok' => true]);
                }
                $user = null;
            } else {
                $user = JWTAuth::setToken($token)->authenticate();
            }

            // Get metadata from the upload
            $metadata = $payload['Event']['Upload']['MetaData'] ?? [];
            $filename = $metadata['filename'] ?? 'unknown';
            $filesize = $payload['Event']['Upload']['Size'] ?? 0;
            $filetype = $metadata['filetype'] ?? 'application/octet-stream';
            $uploadId = $payload['Event']['Upload']['ID'] ?? null;
            
            // Security: Validate upload ID is a safe hex string
            if (!$uploadId || !preg_match('/^[a-f0-9]+$/i', $uploadId)) {
                Log::warning('tusd post-create: Invalid upload ID format', [
                    'upload_id' => $uploadId
                ]);
                return response()->json(['ok' => true]);
            }

            // Create an upload session to track this upload
            $session = UploadSession::create([
                'upload_id' => $uploadId,
                'user_id' => $user?->id,
                'anon_token' => $anonymousToken['jti'] ?? null,
                'filename' => $filename,
                'filesize' => $filesize,
                'filetype' => $filetype,
                'total_chunks' => 1, // tusd handles chunking internally
                'chunks_received' => 0,
                'status' => 'pending'
            ]);

            Log::info('tusd post-create: Upload session created', [
                'user_id' => $user?->id,
                'upload_id' => $uploadId,
                'filename' => $filename
            ]);

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('tusd post-create error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['ok' => true]); // Don't fail the upload
        }
    }

    /**
     * Post-finish hook: Create File record in database after upload completes
     */
    protected function postFinish(Request $request, array $payload)
    {
        try {
            $uploadId = $payload['Event']['Upload']['ID'] ?? null;
            
            // Security: Validate upload ID is a safe hex string (tusd generates 32-char hex IDs)
            if (!$uploadId || !preg_match('/^[a-f0-9]+$/i', $uploadId)) {
                Log::warning('tusd post-finish: Invalid upload ID format', [
                    'upload_id' => $uploadId
                ]);
                return response()->json(['ok' => true]);
            }
            
            $metadata = $payload['Event']['Upload']['MetaData'] ?? [];
            $filename = $metadata['filename'] ?? 'unknown';
            $filesize = $payload['Event']['Upload']['Size'] ?? 0;
            $filetype = $metadata['filetype'] ?? 'application/octet-stream';
            $storagePath = $payload['Event']['Upload']['Storage']['Path'] ?? null;
            $isBundle = ($metadata['isBundle'] ?? 'false') === 'true';

            // Find the upload session
            // For very small/fast uploads, post-finish may arrive before post-create completes
            // So we retry a few times with a small delay
            $session = null;
            $maxRetries = 10;
            for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
                $session = UploadSession::where('upload_id', $uploadId)->first();
                if ($session) {
                    break;
                }
                if ($attempt < $maxRetries - 1) {
                    usleep(100000); // 100ms
                }
            }

            if (!$session) {
                Log::warning('tusd post-finish: Upload session not found after retries', [
                    'upload_id' => $uploadId,
                    'attempts' => $maxRetries
                ]);
                return response()->json(['ok' => true]);
            }

            // Handle bundle uploads differently
            if ($isBundle) {
                // Bundles are unpacked on the server, so they are for signed-in users only
                if ($session->anon_token) {
                    $session->status = 'failed';
                    $session->save();
                    return response()->json(['ok' => true]);
                }
                return $this->handleBundleUpload($uploadId, $session, $metadata);
            }

            // Sanitize filename for storage
            $sanitizedFilename = FileHelper::sanitizeFilename($filename);

            // Create file record
            $file = File::create([
                'name' => $sanitizedFilename,
                'original_name' => $filename,
                'type' => $filetype,
                'size' => $filesize,
                'temp_path' => 'uploads/' . $uploadId // Path relative to storage/app
            ]);

            // Update session
            $session->status = 'complete';
            $session->chunks_received = 1;
            $session->file_id = $file->id;
            $session->save();

            Log::info('tusd post-finish: File record created', [
                'file_id' => $file->id,
                'upload_id' => $uploadId,
                'filename' => $filename
            ]);

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('tusd post-finish error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['ok' => true]); // Don't fail the upload
        }
    }

    /**
     * Handle a bundle upload - extract the zip and create File records for each file
     */
    protected function handleBundleUpload(string $uploadId, UploadSession $session, array $metadata)
    {
        try {
            $bundlePath = storage_path('app/uploads/' . $uploadId);
            $extractDir = storage_path('app/uploads/' . $uploadId . '_extracted');

            Log::info('tusd post-finish: Processing bundle upload', [
                'upload_id' => $uploadId,
                'bundle_path' => $bundlePath
            ]);

            // Create extraction directory
            if (!file_exists($extractDir)) {
                mkdir($extractDir, 0755, true);
            }

            // Open and extract the zip
            $zip = new \ZipArchive();
            $result = $zip->open($bundlePath);

            if ($result !== true) {
                Log::error('tusd post-finish: Failed to open bundle zip', [
                    'upload_id' => $uploadId,
                    'error_code' => $result
                ]);
                $session->status = 'failed';
                $session->save();
                return response()->json(['ok' => true]);
            }

            // Read the manifest
            $manifestContent = $zip->getFromName('__erugo_manifest__.json');
            if (!$manifestContent) {
                Log::error('tusd post-finish: Bundle manifest not found', [
                    'upload_id' => $uploadId
                ]);
                $zip->close();
                $session->status = 'failed';
                $session->save();
                return response()->json(['ok' => true]);
            }

            $manifest = json_decode($manifestContent, true);
            if (!$manifest || !isset($manifest['files'])) {
                Log::error('tusd post-finish: Invalid bundle manifest', [
                    'upload_id' => $uploadId
                ]);
                $zip->close();
                $session->status = 'failed';
                $session->save();
                return response()->json(['ok' => true]);
            }

            // Extract all files
            $zip->extractTo($extractDir);
            $zip->close();

            // Delete the manifest file from extracted directory
            $manifestPath = $extractDir . '/__erugo_manifest__.json';
            if (file_exists($manifestPath)) {
                unlink($manifestPath);
            }

            // Create File records for each file in the manifest
            $fileIds = [];
            foreach ($manifest['files'] as $fileInfo) {
                $filePath = $fileInfo['path'];
                
                // Sanitize the file path to prevent path traversal attacks
                // Remove ../ sequences and normalize path
                $safePath = $this->sanitizeBundlePath($filePath);
                if ($safePath === null) {
                    Log::warning('tusd post-finish: Skipping file with dangerous path', [
                        'upload_id' => $uploadId,
                        'file_path' => $filePath
                    ]);
                    continue;
                }
                
                $extractedFilePath = $extractDir . '/' . $safePath;
                
                // Verify the resolved path is within the extraction directory
                $resolvedPath = realpath($extractedFilePath);
                $resolvedExtractDir = realpath($extractDir);
                
                if ($resolvedPath === false || $resolvedExtractDir === false ||
                    !str_starts_with($resolvedPath, $resolvedExtractDir . DIRECTORY_SEPARATOR)) {
                    Log::warning('tusd post-finish: Path traversal attempt in bundle', [
                        'upload_id' => $uploadId,
                        'file_path' => $filePath,
                        'resolved_path' => $resolvedPath
                    ]);
                    continue;
                }

                if (!file_exists($extractedFilePath)) {
                    Log::warning('tusd post-finish: Bundle file not found after extraction', [
                        'upload_id' => $uploadId,
                        'file_path' => $safePath
                    ]);
                    continue;
                }

                $sanitizedFilename = FileHelper::sanitizeFilename($fileInfo['originalName']);

                // Create file record - store relative path for bundle files
                $file = File::create([
                    'name' => $sanitizedFilename,
                    'original_name' => $fileInfo['originalName'],
                    'type' => $fileInfo['type'] ?? 'application/octet-stream',
                    'size' => $fileInfo['size'],
                    'temp_path' => 'uploads/' . $uploadId . '_extracted/' . $safePath
                ]);

                $fileIds[] = $file->id;
            }

            // Update session with bundle info
            // We store the file IDs as JSON in a special way - the first file_id points to a pseudo-record
            // and we store the actual IDs in the session metadata
            $session->status = 'complete';
            $session->chunks_received = 1;
            $session->is_bundle = true;
            $session->bundle_file_ids = json_encode($fileIds);
            $session->save();

            // Delete the original zip file to save space
            if (file_exists($bundlePath)) {
                unlink($bundlePath);
            }
            // Also delete the .info file
            $infoPath = $bundlePath . '.info';
            if (file_exists($infoPath)) {
                unlink($infoPath);
            }

            Log::info('tusd post-finish: Bundle extracted successfully', [
                'upload_id' => $uploadId,
                'file_count' => count($fileIds)
            ]);

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('tusd post-finish: Bundle extraction error', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $session->status = 'failed';
            $session->save();

            return response()->json(['ok' => true]);
        }
    }

    /**
     * Post-terminate hook: Clean up when upload is cancelled
     */
    protected function postTerminate(Request $request, array $payload)
    {
        try {
            $uploadId = $payload['Event']['Upload']['ID'] ?? null;
            
            // Security: Validate upload ID is a safe hex string
            if (!$uploadId || !preg_match('/^[a-f0-9]+$/i', $uploadId)) {
                Log::warning('tusd post-terminate: Invalid upload ID format', [
                    'upload_id' => $uploadId
                ]);
                return response()->json(['ok' => true]);
            }

            // Find and delete the upload session
            $session = UploadSession::where('upload_id', $uploadId)->first();

            if ($session) {
                // If a file was created, delete it
                if ($session->file_id) {
                    $file = File::find($session->file_id);
                    if ($file) {
                        $file->delete();
                    }
                }
                $session->delete();

                Log::info('tusd post-terminate: Upload session cleaned up', [
                    'upload_id' => $uploadId
                ]);
            }

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('tusd post-terminate error', [
                'error' => $e->getMessage()
            ]);

            return response()->json(['ok' => true]);
        }
    }

    /**
     * Sanitize a bundle file path to prevent path traversal attacks
     * Returns null if the path is dangerous and should be skipped
     */
    private function sanitizeBundlePath(string $path): ?string
    {
        // Normalize directory separators
        $path = str_replace('\\', '/', $path);
        
        // Check for dangerous patterns before sanitization
        if (strpos($path, '..') !== false) {
            return null;
        }
        
        // Remove leading slashes
        $path = ltrim($path, '/');
        
        // Remove null bytes
        $path = str_replace("\0", '', $path);
        
        // Clean up double slashes
        $path = preg_replace('/\/+/', '/', $path);
        
        // If empty after sanitization, reject
        if (empty($path)) {
            return null;
        }
        
        return $path;
    }

    /**
     * Format bytes into human readable format
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) { // 1 GB
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) { // 1 MB
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) { // 1 KB
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}

