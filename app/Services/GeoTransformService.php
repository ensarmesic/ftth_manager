<?php

namespace App\Services;

/**
 * Coordinate transforms between WGS84 and the Bosnian state grid
 * (MGI datum / Bessel 1841, Gauss-Krüger zones 5-7).
 */
class GeoTransformService
{
    public function wgs84ToGaussKruger(float $lat, float $lng, int $zone = 6): array
    {
        // WGS84 ellipsoid
        $aW = 6378137.0;
        $eW2 = 0.00669437999014;

        // Bessel 1841 ellipsoid
        $aB = 6377397.155;
        $eB2 = 0.006674372230614;

        // WGS84 geographic → ECEF
        $latR = deg2rad($lat);
        $lngR = deg2rad($lng);
        $sinLat = sin($latR);
        $cosLat = cos($latR);
        $NW = $aW / sqrt(1.0 - $eW2 * $sinLat * $sinLat);
        $X = $NW * $cosLat * cos($lngR);
        $Y = $NW * $cosLat * sin($lngR);
        $Z = $NW * (1.0 - $eW2) * $sinLat;

        // Helmert shift WGS84 → MGI (obrnuto od towgs84=682,-203,480)
        $X -= 682.0;
        $Y += 203.0;
        $Z -= 480.0;

        // ECEF → MGI Bessel geographic (iterativno)
        $p = sqrt($X * $X + $Y * $Y);
        $lngB = atan2($Y, $X);
        $latB = atan2($Z, $p * (1.0 - $eB2));
        for ($i = 0; $i < 10; $i++) {
            $sLB = sin($latB);
            $NB = $aB / sqrt(1.0 - $eB2 * $sLB * $sLB);
            $latB = atan2($Z + $eB2 * $NB * $sLB, $p);
        }

        // Gauss-Krüger (Transverse Mercator)
        $k0 = 0.9999;
        $lon0 = deg2rad($zone * 3.0);
        $falseE = $zone * 1000000.0 + 500000.0;

        $sinLatB = sin($latB);
        $cosLatB = cos($latB);
        $tanLatB = tan($latB);
        $NB = $aB / sqrt(1.0 - $eB2 * $sinLatB * $sinLatB);
        $T = $tanLatB * $tanLatB;
        $eP2 = $eB2 / (1.0 - $eB2);
        $C = $eP2 * $cosLatB * $cosLatB;
        $A = $cosLatB * ($lngB - $lon0);

        $e4 = $eB2 * $eB2;
        $e6 = $e4 * $eB2;
        $M = $aB * (
            (1.0 - $eB2 / 4.0 - 3.0 * $e4 / 64.0 - 5.0 * $e6 / 256.0) * $latB
            - (3.0 * $eB2 / 8.0 + 3.0 * $e4 / 32.0 + 45.0 * $e6 / 1024.0) * sin(2.0 * $latB)
            + (15.0 * $e4 / 256.0 + 45.0 * $e6 / 1024.0) * sin(4.0 * $latB)
            - (35.0 * $e6 / 3072.0) * sin(6.0 * $latB)
        );

        $easting = $falseE + $k0 * $NB * (
            $A
            + (1.0 - $T + $C) * $A ** 3 / 6.0
            + (5.0 - 18.0 * $T + $T * $T + 72.0 * $C - 58.0 * $eP2) * $A ** 5 / 120.0
        );

        $northing = $k0 * (
            $M + $NB * $tanLatB * (
                $A ** 2 / 2.0
                + (5.0 - $T + 9.0 * $C + 4.0 * $C * $C) * $A ** 4 / 24.0
                + (61.0 - 58.0 * $T + $T * $T + 600.0 * $C - 330.0 * $eP2) * $A ** 6 / 720.0
            )
        );

        return [round($easting, 3), round($northing, 3)];
    }

    /**
     * Gauss-Krüger → WGS84, inverted numerically through the forward transform
     * (Newton with numeric Jacobian). Guarantees round-trip consistency with
     * wgs84ToGaussKruger and converges to <1 mm in a few iterations.
     *
     * @return array{0: float, 1: float} [lat, lng]
     */
    public function gaussKrugerToWgs84(float $easting, float $northing, int $zone = 6): array
    {
        // Initial guess from spherical approximation.
        $lat = $northing / 111132.0;
        $lng = ($zone * 3.0) + ($easting - ($zone * 1000000.0 + 500000.0)) / (111320.0 * cos(deg2rad(max(0.1, $lat))));

        for ($i = 0; $i < 12; $i++) {
            [$e0, $n0] = $this->wgs84ToGaussKruger($lat, $lng, $zone);
            $dE = $easting - $e0;
            $dN = $northing - $n0;
            if (abs($dE) < 0.001 && abs($dN) < 0.001) {
                break;
            }

            // Numeric Jacobian around the current guess.
            $h = 1e-6; // ~0.11 m in latitude
            [$eLat, $nLat] = $this->wgs84ToGaussKruger($lat + $h, $lng, $zone);
            [$eLng, $nLng] = $this->wgs84ToGaussKruger($lat, $lng + $h, $zone);
            $j11 = ($eLat - $e0) / $h; // dE/dlat
            $j12 = ($eLng - $e0) / $h; // dE/dlng
            $j21 = ($nLat - $n0) / $h; // dN/dlat
            $j22 = ($nLng - $n0) / $h; // dN/dlng

            $det = $j11 * $j22 - $j12 * $j21;
            if (abs($det) < 1e-9) {
                break;
            }

            $lat += ($dE * $j22 - $dN * $j12) / $det;
            $lng += ($dN * $j11 - $dE * $j21) / $det;
        }

        return [round($lat, 8), round($lng, 8)];
    }

    /**
     * Detect the Gauss-Krüger zone from an easting with the zone-number prefix
     * (e.g. 6549699 → zone 6).
     */
    public function detectZone(float $easting): int
    {
        $zone = (int) floor($easting / 1000000.0);

        return in_array($zone, [5, 6, 7], true) ? $zone : 6;
    }
}
