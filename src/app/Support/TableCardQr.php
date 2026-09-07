<?php

declare(strict_types=1);

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use LogicException;

final class TableCardQr
{
    public function baseUrl(): string
    {
        return rtrim(trim((string) config('qr.web_base_url', '')), '/');
    }

    public function url(string $token): string
    {
        if ($this->baseUrl() === '') {
            throw new LogicException('QR_WEB_BASE_URL is not configured.');
        }

        return $this->baseUrl().'/t/'.rawurlencode($token);
    }

    public function svg(string $url): string
    {
        if ($this->baseUrl() === '') {
            throw new LogicException('QR_WEB_BASE_URL is not configured.');
        }
        $renderer = new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($url, 'UTF-8', ErrorCorrectionLevel::M());

        return str_starts_with($svg, '<?xml')
            ? trim(substr($svg, (int) strpos($svg, '?>') + 2)) : $svg;
    }
}
