<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustCloudflareIp;
use App\Models\File;
use App\Models\Share;
use App\Models\UploadSession;
use App\Services\AnonymousLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Light NC Share: sending files without an account (D295 limits, Q9).
 * One test per limit, plus the visitor-address rule and the locked hook endpoint.
 */
class AnonymousUploadTest extends TestCase
{
    use RefreshDatabase;

    private const VISITOR = '203.0.113.7';
    private const GIB = 1073741824;

    /** @var string[] upload files created by a test, removed afterwards */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }
        $shares = storage_path('app/shares/anon');
        if (is_dir($shares)) {
            foreach (glob($shares . '/*') as $dir) {
                array_map('unlink', glob($dir . '/*'));
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    // ---- helpers --------------------------------------------------------------------

    /** A request as it arrives through the tunnel: the peer is a Docker address. */
    private function viaTunnel(string $visitor = self::VISITOR)
    {
        $this->defaultHeaders = [];
        $this->serverVariables = [];

        return $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
            ->withHeaders(['CF-Connecting-IP' => $visitor]);
    }

    private function token(string $ip = self::VISITOR): string
    {
        return AnonymousLimits::issueToken($ip);
    }

    /** The same token with one character in the middle changed. */
    private function tampered(string $token): string
    {
        $i = intdiv(strlen($token), 2);

        return substr_replace($token, $token[$i] === 'A' ? 'B' : 'A', $i, 1);
    }

    private function jti(string $token): string
    {
        return json_decode(Crypt::decryptString(substr($token, 5)), true)['jti'];
    }

    /** A tusd hook body for an upload made with $token. */
    private function hook(string $type, ?string $token, array $upload = [], array $headers = []): array
    {
        $header = array_merge([
            'X-Lnc-Peer' => ['172.18.0.2'],
            'Cf-Connecting-Ip' => [self::VISITOR],
        ], $headers);
        if ($token !== null) {
            $header['Authorization'] = ["Bearer $token"];
        }

        return [
            'Type' => $type,
            'Event' => [
                'Upload' => array_merge(['ID' => bin2hex(random_bytes(16)), 'Size' => 1000, 'MetaData' => ['filename' => 'a.txt']], $upload),
                'HTTPRequest' => ['Header' => $header],
            ],
        ];
    }

    private function callHook(array $body, string $peer = '127.0.0.1')
    {
        $this->defaultHeaders = [];
        $this->serverVariables = [];

        return $this->withServerVariables(['REMOTE_ADDR' => $peer])->postJson('/api/tusd-hooks', $body);
    }

    private function assertRejected($response, int $status, string $message): void
    {
        $response->assertOk()->assertJson([
            'ok' => false,
            'RejectUpload' => true,
            'HTTPResponse' => ['StatusCode' => $status, 'Body' => $message],
        ]);
    }

    /** A finished upload for $token: a session, a file record and the bytes on disk. */
    private function finishedUpload(string $token, string $name = 'a.txt', int $size = 10): string
    {
        $uploadId = bin2hex(random_bytes(16));
        $path = storage_path('app/uploads/' . $uploadId);
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, str_repeat('x', 10));
        $this->created[] = $path;

        $file = File::create(['name' => $name, 'original_name' => $name, 'type' => 'text/plain', 'size' => $size, 'temp_path' => 'uploads/' . $uploadId]);
        UploadSession::create([
            'upload_id' => $uploadId, 'user_id' => null, 'anon_token' => $this->jti($token),
            'filename' => $name, 'filesize' => $size, 'filetype' => 'text/plain',
            'total_chunks' => 1, 'chunks_received' => 1, 'status' => 'complete', 'file_id' => $file->id,
        ]);

        return $uploadId;
    }

    private function pendingSession(string $token, int $size): void
    {
        UploadSession::create([
            'upload_id' => bin2hex(random_bytes(16)), 'user_id' => null, 'anon_token' => $this->jti($token),
            'filename' => 'f', 'filesize' => $size, 'filetype' => 'x', 'total_chunks' => 1, 'status' => 'pending',
        ]);
    }

    private function createShare(string $token, array $uploadIds, array $extra = [], string $visitor = self::VISITOR)
    {
        return $this->viaTunnel($visitor)->withToken($token)->postJson('/api/anon/create-share', array_merge([
            'uploadIds' => $uploadIds, 'expiry_days' => 7,
        ], $extra));
    }

    // ---- the visitor-address rule ---------------------------------------------------

    public function test_ip_rule_believes_cf_header_only_from_private_peers(): void
    {
        foreach (['10.1.2.3', '172.17.0.5', '172.31.255.1', '192.168.1.9', '127.0.0.1', '::1', 'fd00::5'] as $peer) {
            $this->assertSame('198.51.100.4', AnonymousLimits::clientIp($peer, '198.51.100.4'), "peer $peer");
        }
        // public peers cannot choose their own address
        foreach (['203.0.113.9', '172.32.0.1', '11.0.0.1', '2001:db8::1'] as $peer) {
            $this->assertSame($peer, AnonymousLimits::clientIp($peer, '198.51.100.4'), "peer $peer");
        }
        // junk or missing header: the peer is used
        $this->assertSame('172.17.0.5', AnonymousLimits::clientIp('172.17.0.5', 'not-an-ip'));
        $this->assertSame('172.17.0.5', AnonymousLimits::clientIp('172.17.0.5', null));
    }

    public function test_middleware_sets_request_ip_from_cf_header_only_behind_private_peer(): void
    {
        $next = fn (Request $r) => response($r->ip());
        $run = fn (string $peer) => (new TrustCloudflareIp())->handle(
            Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $peer, 'HTTP_CF_CONNECTING_IP' => '198.51.100.4']),
            $next
        )->getContent();

        $this->assertSame('198.51.100.4', $run('172.18.0.2'));
        $this->assertSame('203.0.113.9', $run('203.0.113.9'));
    }

    public function test_limits_are_counted_per_visitor_address_behind_the_tunnel(): void
    {
        config(['anonymous.per_ip_hour' => 1]);
        AnonymousLimits::countShare(self::VISITOR);

        // another visitor through the same tunnel peer is not affected
        $this->viaTunnel('198.51.100.20')->postJson('/api/anon/upload-token')->assertOk();
        $this->viaTunnel(self::VISITOR)->postJson('/api/anon/upload-token')
            ->assertStatus(429)->assertJson(['message' => AnonymousLimits::MSG_RATE]);
    }

    // ---- the hook endpoint is locked -------------------------------------------------

    public function test_hook_endpoint_refuses_anyone_but_loopback(): void
    {
        $body = $this->hook('post-terminate', null);
        $this->callHook($body, '172.18.0.2')->assertStatus(403);
        $this->callHook($body, '203.0.113.9')->assertStatus(403);
        // a visitor coming through the tunnel looks like their public address, not loopback
        $this->viaTunnel()->postJson('/api/tusd-hooks', $body)->assertStatus(403);
        $this->callHook($body, '127.0.0.1')->assertOk();
    }

    public function test_caddyfile_hides_hooks_and_sets_frame_ancestors(): void
    {
        $caddy = file_get_contents(base_path('docker/alpine/Caddyfile'));
        $this->assertStringContainsString('path /api/tusd-hooks', $caddy);
        $this->assertStringContainsString('not remote_ip 127.0.0.1/32 ::1/128', $caddy);
        $this->assertStringContainsString('respond 404', $caddy);
        $this->assertStringContainsString('Content-Security-Policy "frame-ancestors https://cloud.lightnc.org"', $caddy);
        $this->assertStringNotContainsString('X-Frame-Options', $caddy);
        $this->assertStringContainsString('max_size 50MB', $caddy);
        $this->assertStringContainsString('header_up X-Lnc-Peer {remote_host}', $caddy);
    }

    // ---- upload tokens ---------------------------------------------------------------

    public function test_token_is_issued_with_the_agreed_limits(): void
    {
        $this->viaTunnel()->postJson('/api/anon/upload-token')->assertOk()->assertJson([
            'data' => ['limits' => [
                'max_share_bytes' => 2 * self::GIB, 'max_files' => 20, 'max_expiry_days' => 7, 'max_downloads' => 100,
            ]],
        ]);
    }

    public function test_token_only_works_from_the_address_it_was_issued_to_and_expires(): void
    {
        $token = $this->token();
        $this->assertNotNull(AnonymousLimits::readToken($token, self::VISITOR));
        $this->assertNull(AnonymousLimits::readToken($token, '198.51.100.99'));
        $this->assertNull(AnonymousLimits::readToken($this->tampered($token), self::VISITOR));
        $this->assertNull(AnonymousLimits::readToken('anon.garbage', self::VISITOR));
        $this->assertNull(AnonymousLimits::readToken('Bearer-not-ours', self::VISITOR));

        config(['anonymous.token_ttl_minutes' => -1]);
        $this->assertNull(AnonymousLimits::readToken($this->token(), self::VISITOR));
    }

    public function test_token_requests_are_rate_limited(): void
    {
        config(['anonymous.tokens_per_ip_hour' => 2]);
        $this->viaTunnel()->postJson('/api/anon/upload-token')->assertOk();
        $this->viaTunnel()->postJson('/api/anon/upload-token')->assertOk();
        $this->viaTunnel()->postJson('/api/anon/upload-token')
            ->assertStatus(429)->assertJson(['message' => AnonymousLimits::MSG_TOKENS]);
    }

    public function test_token_is_refused_when_sharing_is_full(): void
    {
        Share::create(['name' => 'big', 'path' => 'anon/x', 'long_id' => 'x', 'size' => 20 * self::GIB, 'file_count' => 1,
            'expires_at' => now()->addDay(), 'status' => 'ready', 'anonymous' => true]);

        $this->viaTunnel()->postJson('/api/anon/upload-token')
            ->assertStatus(503)->assertJson(['message' => AnonymousLimits::MSG_FULL]);
    }

    // ---- limits at the start of an upload (tusd pre-create) -------------------------------

    public function test_pre_create_accepts_an_upload_inside_the_limits(): void
    {
        $this->callHook($this->hook('pre-create', $this->token()))->assertOk()->assertJson(['ok' => true]);
        $this->callHook($this->hook('pre-create', $this->token(), ['Size' => 2 * self::GIB]))->assertJson(['ok' => true]);
    }

    public function test_pre_create_needs_a_valid_token_from_the_same_address(): void
    {
        $this->assertRejected($this->callHook($this->hook('pre-create', 'anon.forged')), 401, AnonymousLimits::MSG_TOKEN);
        // the token was issued to another visitor
        $this->assertRejected(
            $this->callHook($this->hook('pre-create', $this->token('198.51.100.99'))),
            401,
            AnonymousLimits::MSG_TOKEN
        );
        // a client cannot claim the address by sending its own X-Lnc-Peer from outside the tunnel
        $this->assertRejected(
            $this->callHook($this->hook('pre-create', $this->token(), [], ['X-Lnc-Peer' => ['203.0.113.50']])),
            401,
            AnonymousLimits::MSG_TOKEN
        );
    }

    public function test_pre_create_refuses_a_file_over_2_gib(): void
    {
        $this->assertRejected(
            $this->callHook($this->hook('pre-create', $this->token(), ['Size' => 2 * self::GIB + 1])),
            413,
            AnonymousLimits::MSG_SIZE
        );
    }

    public function test_pre_create_refuses_the_21st_file_in_a_share(): void
    {
        $token = $this->token();
        for ($i = 0; $i < 20; $i++) {
            $this->pendingSession($token, 10);
        }
        $this->assertRejected($this->callHook($this->hook('pre-create', $token)), 413, AnonymousLimits::MSG_SIZE);
    }

    public function test_pre_create_refuses_when_the_files_together_pass_2_gib(): void
    {
        $token = $this->token();
        $this->pendingSession($token, (int) (1.5 * self::GIB));
        $this->assertRejected($this->callHook($this->hook('pre-create', $token, ['Size' => self::GIB])), 413, AnonymousLimits::MSG_SIZE);
        $this->callHook($this->hook('pre-create', $token, ['Size' => (int) (0.5 * self::GIB)]))->assertJson(['ok' => true]);
    }

    public function test_pre_create_refuses_folders_bundles_and_unknown_sizes(): void
    {
        $token = $this->token();
        $this->assertRejected(
            $this->callHook($this->hook('pre-create', $token, ['MetaData' => ['filename' => 'b.zip', 'isBundle' => 'true']])),
            400,
            AnonymousLimits::MSG_BUNDLE
        );
        $this->assertRejected(
            $this->callHook($this->hook('pre-create', $token, ['SizeIsDeferred' => true, 'Size' => 0])),
            400,
            AnonymousLimits::MSG_SIZE
        );
    }

    public function test_pre_create_refuses_an_address_that_sent_too_many_shares(): void
    {
        config(['anonymous.per_ip_hour' => 5]);
        for ($i = 0; $i < 5; $i++) {
            AnonymousLimits::countShare(self::VISITOR);
        }
        $this->assertRejected($this->callHook($this->hook('pre-create', $this->token())), 429, AnonymousLimits::MSG_RATE);
    }

    public function test_pre_create_refuses_when_all_visitor_shares_together_pass_20_gib(): void
    {
        Share::create(['name' => 'old', 'path' => 'anon/o', 'long_id' => 'o', 'size' => 20 * self::GIB - 1000, 'file_count' => 1,
            'expires_at' => now()->addDay(), 'status' => 'ready', 'anonymous' => true]);

        $this->callHook($this->hook('pre-create', $this->token(), ['Size' => 1000]))->assertJson(['ok' => true]);
        $this->assertRejected($this->callHook($this->hook('pre-create', $this->token(), ['Size' => 1001])), 503, AnonymousLimits::MSG_FULL);
    }

    public function test_expired_shares_and_stale_uploads_stop_counting_toward_the_total(): void
    {
        Share::create(['name' => 'old', 'path' => 'anon/e', 'long_id' => 'e', 'size' => 20 * self::GIB, 'file_count' => 1,
            'expires_at' => now()->subDay(), 'status' => 'ready', 'anonymous' => true]);
        $this->assertSame(0, AnonymousLimits::totalBytes());

        $token = $this->token();
        $this->pendingSession($token, 500);
        $this->assertSame(500, AnonymousLimits::totalBytes());
        UploadSession::query()->update(['created_at' => now()->subHours(25)]);
        $this->assertSame(0, AnonymousLimits::totalBytes());
        $this->assertSame(1, AnonymousLimits::purgeStale());
        $this->assertSame(0, UploadSession::count());
    }

    // ---- session bookkeeping (tusd post-create / post-finish) ----------------------------

    public function test_post_create_stores_the_upload_against_the_token_with_no_user(): void
    {
        $token = $this->token();
        $body = $this->hook('post-create', $token);
        $this->callHook($body)->assertOk();

        $session = UploadSession::where('upload_id', $body['Event']['Upload']['ID'])->first();
        $this->assertNotNull($session);
        $this->assertNull($session->user_id);
        $this->assertSame($this->jti($token), $session->anon_token);
    }

    public function test_post_create_with_a_bad_token_stores_nothing(): void
    {
        $this->callHook($this->hook('post-create', 'anon.forged'))->assertOk();
        $this->assertSame(0, UploadSession::count());
    }

    public function test_a_bundle_from_someone_not_signed_in_is_never_unpacked(): void
    {
        $token = $this->token();
        $create = $this->hook('post-create', $token);
        $this->callHook($create);
        $finish = $this->hook('post-finish', $token, ['ID' => $create['Event']['Upload']['ID'], 'MetaData' => ['filename' => 'b.zip', 'isBundle' => 'true']]);
        $this->callHook($finish)->assertOk();

        $this->assertSame('failed', UploadSession::first()->status);
        $this->assertSame(0, File::count());
    }

    // ---- making the share ------------------------------------------------------------------

    public function test_a_share_is_made_with_the_agreed_settings(): void
    {
        Queue::fake();
        $token = $this->token();
        $ids = [$this->finishedUpload($token, 'one.txt'), $this->finishedUpload($token, 'two.txt')];

        $response = $this->createShare($token, $ids)->assertOk();
        $share = Share::where('long_id', $response->json('data.share.long_id'))->first();

        $this->assertTrue($share->anonymous);
        $this->assertNull($share->user_id);
        $this->assertSame(100, (int) $share->download_limit);
        $this->assertSame(2, (int) $share->file_count);
        $this->assertTrue($share->expires_at->lessThanOrEqualTo(now()->addDays(7)->addMinute()));
        $this->assertSame(0, UploadSession::count());
        $this->assertFileExists(storage_path('app/shares/anon/' . $share->long_id . '/one.txt'));
        $this->assertFileExists(storage_path('app/shares/anon/' . $share->long_id . '/two.txt'));
    }

    public function test_two_files_with_the_same_name_both_survive(): void
    {
        Queue::fake();
        $token = $this->token();
        $ids = [$this->finishedUpload($token, 'photo.png'), $this->finishedUpload($token, 'photo.png')];

        $long = $this->createShare($token, $ids)->assertOk()->json('data.share.long_id');
        $this->assertCount(2, glob(storage_path("app/shares/anon/$long/*")));
    }

    public function test_expiry_longer_than_7_days_is_refused(): void
    {
        $token = $this->token();
        $id = $this->finishedUpload($token);
        $this->createShare($token, [$id], ['expiry_days' => 8])->assertStatus(422);
        $this->createShare($token, [$id], ['expiry_days' => 0])->assertStatus(422);
        $this->assertSame(0, Share::count());
    }

    public function test_more_than_20_files_in_one_share_is_refused(): void
    {
        $token = $this->token();
        $ids = array_map(fn () => bin2hex(random_bytes(16)), range(1, 21));
        $this->createShare($token, $ids)->assertStatus(422);
    }

    public function test_a_share_over_2_gib_is_refused_even_if_pieces_slipped_through(): void
    {
        $token = $this->token();
        $id = $this->finishedUpload($token, 'big.bin', 2 * self::GIB + 1);
        $this->createShare($token, [$id])->assertStatus(422)->assertJson(['message' => AnonymousLimits::MSG_SIZE]);
        $this->assertSame(0, Share::count());
    }

    public function test_share_creation_needs_the_token_from_the_same_address(): void
    {
        $token = $this->token();
        $id = $this->finishedUpload($token);
        $this->viaTunnel()->postJson('/api/anon/create-share', ['uploadIds' => [$id], 'expiry_days' => 7])->assertStatus(401);
        $this->createShare($token, [$id], [], '198.51.100.99')->assertStatus(401);
        $this->createShare($this->tampered($token), [$id])->assertStatus(401);
    }

    public function test_one_token_cannot_take_another_tokens_uploads(): void
    {
        $theirs = $this->token();
        $id = $this->finishedUpload($theirs);
        $mine = $this->token();

        $this->createShare($mine, [$id])->assertStatus(400);
        $this->assertSame(0, Share::count());
    }

    public function test_sixth_share_in_an_hour_is_refused(): void
    {
        Queue::fake();
        for ($i = 1; $i <= 5; $i++) {
            $token = $this->token();
            $this->createShare($token, [$this->finishedUpload($token)])->assertOk();
        }
        $token = $this->token();
        $this->createShare($token, [$this->finishedUpload($token)])
            ->assertStatus(429)->assertJson(['message' => AnonymousLimits::MSG_RATE]);
        $this->assertSame(5, Share::count());
    }

    public function test_twenty_first_share_in_a_day_is_refused(): void
    {
        Queue::fake();
        config(['anonymous.per_ip_hour' => 1000, 'anonymous.per_ip_day' => 3]);
        for ($i = 1; $i <= 3; $i++) {
            $token = $this->token();
            $this->createShare($token, [$this->finishedUpload($token)])->assertOk();
        }
        $token = $this->token();
        $this->createShare($token, [$this->finishedUpload($token)])->assertStatus(429);
        // a different visitor is not affected
        $other = $this->token('198.51.100.20');
        $this->createShare($other, [$this->finishedUpload($other)], [], '198.51.100.20')->assertOk();
    }

    public function test_share_is_refused_when_sharing_is_full(): void
    {
        $token = $this->token();
        $id = $this->finishedUpload($token);
        Share::create(['name' => 'old', 'path' => 'anon/f', 'long_id' => 'f', 'size' => 20 * self::GIB, 'file_count' => 1,
            'expires_at' => now()->addDay(), 'status' => 'ready', 'anonymous' => true]);

        $this->createShare($token, [$id])->assertStatus(503)->assertJson(['message' => AnonymousLimits::MSG_FULL]);
    }

    public function test_limits_come_from_config_keys(): void
    {
        $this->assertSame(
            [2 * self::GIB, 20, 7, 100, 5, 20, 20 * self::GIB, 50 * 1048576],
            array_map('config', [
                'anonymous.max_share_bytes', 'anonymous.max_files', 'anonymous.max_expiry_days', 'anonymous.max_downloads',
                'anonymous.per_ip_hour', 'anonymous.per_ip_day', 'anonymous.total_bytes', 'anonymous.chunk_max_bytes',
            ])
        );
    }
}
