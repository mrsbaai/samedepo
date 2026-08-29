<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

return new class extends Migration
{
    /**
     * Cloudflare proxy ranges that terminate TLS to the origin.
     *
     * @see https://www.cloudflare.com/ips/
     */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.192.0/18',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public function up(): void
    {
        $blocks = DB::table('security_blocks')->where('type', 'ip')->pluck('id', 'value');
        $idsToDelete = [];

        foreach ($blocks as $ip => $id) {
            foreach (self::CLOUDFLARE_RANGES as $range) {
                if (IpUtils::checkIp($ip, $range)) {
                    $idsToDelete[] = $id;
                    break;
                }
            }
        }

        if ($idsToDelete !== []) {
            DB::table('security_blocks')->whereIn('id', array_unique($idsToDelete))->delete();
            Cache::forget('security.blocked.ip');
        }
    }

    public function down(): void
    {
        // One-time cleanup; no reverse.
    }
};
