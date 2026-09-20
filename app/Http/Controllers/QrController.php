<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\CryptoIcon;
use App\Support\Network;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Response;

class QrController
{
    public function show(string $address): Response
    {
        $network = $this->detectNetwork($address);

        if ($network === null) {
            return response('Unsupported address type.', 400);
        }

        $logo = CryptoIcon::svg(Network::present($network)['icon']);

        if ($logo === null) {
            return response('Logo not found.', 500);
        }

        $svg = $this->qrSvg($address, $logo);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    private function detectNetwork(string $address): ?string
    {
        foreach (config('networks.address_groups', []) as $group => $meta) {
            if (preg_match($meta['address_regex'], $address)) {
                return Network::enabledInGroup($group);
            }
        }

        return null;
    }

    private function qrSvg(string $address, string $logo): string
    {
        $options = new QROptions([
            'eccLevel' => 'H',
            'outputBase64' => false,
            'svgAddXmlHeader' => true,
            'cssClass' => '',
        ]);

        $qrcode = new QRCode($options);
        $qrcode->addByteSegment($address);

        $size = $qrcode->getQRMatrix()->getSize();

        [$viewBoxWidth, $viewBoxHeight, $cx, $cy] = $this->logoCenter($logo);

        $target = max((int) floor($size * 0.22), 5);

        $radius = max($cx, $viewBoxWidth - $cx, $cy, $viewBoxHeight - $cy);
        $scale = $target / (2 * $radius);

        $imageWidth = $viewBoxWidth * $scale;
        $imageHeight = $viewBoxHeight * $scale;
        $imageX = ($size / 2) - ($cx * $scale);
        $imageY = ($size / 2) - ($cy * $scale);

        $svg = $qrcode->render();

        $image = sprintf(
            '<image x="%.3f" y="%.3f" width="%.3f" height="%.3f" href="data:image/svg+xml;base64,%s" preserveAspectRatio="xMidYMid meet"/>',
            $imageX,
            $imageY,
            $imageWidth,
            $imageHeight,
            base64_encode($logo)
        );

        return str_replace('</svg>', $image.'</svg>', $svg);
    }

    private function logoCenter(string $svg): array
    {
        $viewBoxWidth = 32.0;
        $viewBoxHeight = 32.0;

        if (preg_match('/viewBox=["\']0 0 ([0-9.]+) ([0-9.]+)["\']/', $svg, $m)) {
            $viewBoxWidth = (float) $m[1];
            $viewBoxHeight = (float) $m[2];
        }

        $cx = $viewBoxWidth / 2;
        $cy = $viewBoxHeight / 2;

        if (preg_match('/<circle[^>]*\bcx=["\']([0-9.]+)["\'][^>]*\bcy=["\']([0-9.]+)["\'][^>]*\/?>/', $svg, $m)) {
            $cx = (float) $m[1];
            $cy = (float) $m[2];
        }

        return [$viewBoxWidth, $viewBoxHeight, $cx, $cy];
    }
}
