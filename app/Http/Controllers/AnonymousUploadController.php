<?php

namespace App\Http\Controllers;

use App\Jobs\CreateShareZip;
use App\Models\File;
use App\Models\Share;
use App\Models\UploadSession;
use App\Services\AnonymousLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Sending files without an account (Light NC Share, D295).
 * Step 1: ask for an upload token. Step 2: send the files to tusd with that token.
 * Step 3: turn the finished uploads into a share. The limits are checked on the server
 * at every step; nothing the browser says is believed.
 */
class AnonymousUploadController extends Controller
{
    public function token(Request $request)
    {
        $ip = $request->ip();

        if (!AnonymousLimits::allowTokenRequest($ip)) {
            return $this->refuse(429, AnonymousLimits::MSG_TOKENS);
        }
        if (AnonymousLimits::tooManyShares($ip)) {
            return $this->refuse(429, AnonymousLimits::MSG_RATE);
        }
        if (AnonymousLimits::totalBytes() >= config('anonymous.total_bytes')) {
            return $this->refuse(503, AnonymousLimits::MSG_FULL);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Upload token created',
            'data' => [
                'token' => AnonymousLimits::issueToken($ip),
                'limits' => [
                    'max_share_bytes' => config('anonymous.max_share_bytes'),
                    'max_files' => config('anonymous.max_files'),
                    'max_expiry_days' => config('anonymous.max_expiry_days'),
                    'max_downloads' => config('anonymous.max_downloads'),
                ],
            ],
        ]);
    }

    public function createShare(Request $request)
    {
        $ip = $request->ip();
        $token = AnonymousLimits::readToken($request->bearerToken(), $ip);
        if (!$token) {
            return $this->refuse(401, AnonymousLimits::MSG_TOKEN);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:255'],
            'uploadIds' => ['required', 'array', 'min:1', 'max:' . config('anonymous.max_files')],
            'uploadIds.*' => ['required', 'string', 'regex:/^[a-f0-9]+$/i'],
            'expiry_days' => ['required', 'integer', 'min:1', 'max:' . config('anonymous.max_expiry_days')],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        if (AnonymousLimits::tooManyShares($ip)) {
            return $this->refuse(429, AnonymousLimits::MSG_RATE);
        }

        // The last upload's "finished" message may still be on its way, so wait a moment
        $expected = count(array_unique($request->uploadIds));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $sessions = UploadSession::where('anon_token', $token['jti'])
                ->whereIn('upload_id', $request->uploadIds)
                ->where('status', 'complete')
                ->get();
            if ($sessions->count() === $expected) {
                break;
            }
            usleep(100000 * (2 ** $attempt));
        }
        if ($sessions->count() !== $expected) {
            return $this->refuse(400, 'Some uploads were not found or did not finish.');
        }

        $files = File::whereIn('id', $sessions->pluck('file_id')->filter())->get();
        $totalSize = (int) $files->sum('size');
        if (
            $files->isEmpty()
            || $files->count() > config('anonymous.max_files')
            || $totalSize > config('anonymous.max_share_bytes')
        ) {
            return $this->refuse(422, AnonymousLimits::MSG_SIZE);
        }

        if (AnonymousLimits::totalBytes($token['jti']) + $totalSize > config('anonymous.total_bytes')) {
            return $this->refuse(503, AnonymousLimits::MSG_FULL);
        }

        $longId = app(SharesController::class)->generateLongId();
        $sharePath = 'anon/' . $longId;
        $destDir = storage_path('app/shares/' . $sharePath);
        mkdir($destDir, 0755, true);

        $share = Share::create([
            'name' => $request->name,
            'expires_at' => now()->addDays((int) $request->expiry_days),
            'user_id' => null,
            'path' => $sharePath,
            'long_id' => $longId,
            'size' => $totalSize,
            'file_count' => $files->count(),
            'download_limit' => config('anonymous.max_downloads'),
            'status' => 'pending',
            'anonymous' => true,
        ]);

        // Files are named by the server-cleaned name; two files with the same name get a number
        $used = [];
        foreach ($files as $file) {
            $name = $file->name;
            for ($n = 2; isset($used[strtolower($name)]); $n++) {
                $name = pathinfo($file->name, PATHINFO_FILENAME) . "-$n"
                    . (pathinfo($file->name, PATHINFO_EXTENSION) !== '' ? '.' . pathinfo($file->name, PATHINFO_EXTENSION) : '');
            }
            $used[strtolower($name)] = true;

            $source = storage_path('app/' . $file->temp_path);
            if (!rename($source, $destDir . '/' . $name)) {
                Log::error('anonymous share: could not move an uploaded file', ['upload' => $file->temp_path]);
            }
            @unlink($source . '.info');

            $file->share_id = $share->id;
            $file->name = $name;
            $file->full_path = '';
            $file->temp_path = null;
            $file->save();
        }

        foreach ($sessions as $session) {
            $session->delete();
        }

        AnonymousLimits::countShare($ip);
        CreateShareZip::dispatch($share);

        return response()->json([
            'status' => 'success',
            'message' => 'Share created',
            'data' => ['share' => ['long_id' => $share->long_id, 'expires_at' => $share->expires_at]],
        ]);
    }

    private function refuse(int $status, string $message)
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }
}
