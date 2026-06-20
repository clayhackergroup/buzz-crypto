<?php
class ChartGenerator {
    public static function generateChart(array $prices, int $days, string $coinName = 'BTC'): string {
        $width = 900;
        $height = 500;
        $padLeft = 70;
        $padRight = 30;
        $padTop = 50;
        $padBottom = 50;
        $chartW = $width - $padLeft - $padRight;
        $chartH = $height - $padTop - $padBottom;

        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, 26, 26, 46);
        $fg = imagecolorallocate($img, 247, 147, 26);
        $grid = imagecolorallocate($img, 50, 50, 80);
        $textColor = imagecolorallocate($img, 200, 200, 200);
        $sma7Color = imagecolorallocate($img, 78, 205, 196);
        $sma25Color = imagecolorallocate($img, 255, 107, 107);
        $fillColor = imagecolorallocatealpha($img, 247, 147, 26, 80);
        imagefill($img, 0, 0, $bg);

        $values = array_map(fn($p) => $p[1], $prices);
        $min = min($values);
        $max = max($values);
        if ($min == $max) { $min -= 1; $max += 1; }
        $range = $max - $min;
        $pad = $range * 0.05;
        $min -= $pad; $max += $pad; $range = $max - $min;
        $total = count($prices);
        if ($total < 2) return '';

        $sma7 = self::calcSma($values, min(7, $total));
        $sma25 = self::calcSma($values, min(25, $total));

        for ($i = 0; $i <= 4; $i++) {
            $y = $padTop + ($chartH / 4) * $i;
            imageline($img, $padLeft, $y, $width - $padRight, $y, $grid);
            $label = ($max - ($range / 4) * $i);
            $fmt = $label >= 100 ? number_format($label, 0) : number_format($label, 2);
            imagestring($img, 2, 5, $y - 6, '$' . $fmt, $textColor);
        }

        $xf = fn($i) => $padLeft + ($i / max($total - 1, 1)) * $chartW;
        $yf = fn($v) => $padTop + $chartH - (($v - $min) / $range) * $chartH;

        $points = [];
        for ($i = 0; $i < $total; $i++) { $points[] = $xf($i); $points[] = $yf($values[$i]); }
        $points[] = $xf($total - 1); $points[] = $padTop + $chartH;
        $points[] = $xf(0); $points[] = $padTop + $chartH;
        imagefilledpolygon($img, $points, intval(count($points) / 2), $fillColor);

        for ($i = 1; $i < $total; $i++) {
            if ($sma7[$i] !== null && $sma7[$i-1] !== null) imageline($img, $xf($i-1), $yf($sma7[$i-1]), $xf($i), $yf($sma7[$i]), $sma7Color);
            if ($sma25[$i] !== null && $sma25[$i-1] !== null) imageline($img, $xf($i-1), $yf($sma25[$i-1]), $xf($i), $yf($sma25[$i]), $sma25Color);
        }
        for ($i = 1; $i < $total; $i++) imageline($img, $xf($i-1), $yf($values[$i-1]), $xf($i), $yf($values[$i]), $fg);

        $title = "$coinName/USD - Last $days Days";
        $xCenter = ($width - strlen($title) * imagefontwidth(5)) / 2;
        imagestring($img, 5, max(0, intval($xCenter)), 15, $title, $textColor);
        imagestring($img, 2, $padLeft, $height - 25, '--- Price', $fg);
        imagestring($img, 2, $padLeft + 120, $height - 25, '--- SMA7', $sma7Color);
        imagestring($img, 2, $padLeft + 240, $height - 25, '--- SMA25', $sma25Color);

        $tmpFile = sys_get_temp_dir() . '/chart_' . time() . '_' . bin2hex(random_bytes(4)) . '.png';
        imagepng($img, $tmpFile);
        imagedestroy($img);
        return $tmpFile;
    }

    private static function calcSma(array $data, int $period): array {
        $result = array_fill(0, count($data), null);
        for ($i = $period - 1; $i < count($data); $i++) {
            $result[$i] = array_sum(array_slice($data, $i - $period + 1, $period)) / $period;
        }
        return $result;
    }
}
