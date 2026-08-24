<?php

declare(strict_types=1);

/** Deterministic dependency-free PNG generator for the PWA icon set. */
function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . hash('crc32b', $type . $data, true);
}

function renderIcon(int $size, string $path): void
{
    $green = [25, 62, 47];
    $cream = [245, 244, 238];
    $coral = [239, 121, 93];
    $rows = '';
    for ($y = 0; $y < $size; $y++) {
        $rows .= "\0";
        for ($x = 0; $x < $size; $x++) {
            $nx = $x / $size;
            $ny = $y / $size;
            $color = $green;
            $vertical = $nx >= .285 && $nx <= .435 && $ny >= .25 && $ny <= .75;
            $top = $nx >= .40 && $nx <= .64 && $ny >= .25 && $ny <= .38;
            $middle = $nx >= .40 && $nx <= .62 && $ny >= .43 && $ny <= .56;
            $right = $nx >= .57 && $nx <= .70 && $ny >= .31 && $ny <= .50;
            $leg = $ny >= .52 && $ny <= .75 && $nx >= (.44 + ($ny - .52) * .66) && $nx <= (.58 + ($ny - .52) * .66);
            if ($vertical || $top || $middle || $right || $leg) $color = $cream;
            $dx = $nx - .738;
            $dy = $ny - .262;
            if ($dx * $dx + $dy * $dy <= .00345) $color = $coral;
            $rows .= pack('C3', ...$color);
        }
    }
    $png = "\x89PNG\r\n\x1a\n";
    $png .= pngChunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0));
    $png .= pngChunk('IDAT', gzcompress($rows, 9));
    $png .= pngChunk('IEND', '');
    if (file_put_contents($path, $png) === false) throw new RuntimeException('Не удалось записать ' . $path);
}

$directory = dirname(__DIR__) . '/public/icons';
foreach ([180, 192, 512] as $size) renderIcon($size, $directory . '/icon-' . $size . '.png');
fwrite(STDOUT, "PWA icons generated.\n");
