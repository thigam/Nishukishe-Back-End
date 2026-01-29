<?php
namespace App\Services;

use FFI;

class H3Wrapper
{
    private static ?FFI $ffi = null;

    private static function init(): void
    {
        if (self::$ffi === null) {
            self::$ffi = FFI::cdef(
                "typedef unsigned long long H3Index;\n" .
                "typedef long long int64_t;\n" .
                "typedef struct { double lat; double lng; } LatLng;\n" .
                "int latLngToCell(const LatLng *g, int res, H3Index *out);\n" .
                "int maxGridDiskSize(int k, int64_t *out);\n" .
                "int gridDisk(H3Index origin, int k, H3Index *out);\n",
                '/usr/local/lib/libh3.so'
            );
        }
    }

    public static function latLngToCell(float $lat, float $lng, int $res): string
    {
        self::init();
        $coord = self::$ffi->new('LatLng');
        $coord->lat = deg2rad($lat);
        $coord->lng = deg2rad($lng);
        $out = self::$ffi->new('H3Index');
        self::$ffi->latLngToCell(FFI::addr($coord), $res, FFI::addr($out));
        return dechex($out->cdata);
    }

    public static function kRing(string $index, int $k): array
    {
        self::init();
        $origin = hexdec($index);
        $size = self::$ffi->new('int64_t');
        self::$ffi->maxGridDiskSize($k, FFI::addr($size));
        $out = self::$ffi->new("H3Index[{$size->cdata}]");
        self::$ffi->gridDisk($origin, $k, $out);
        $result = [];
        for ($i = 0; $i < $size->cdata; $i++) {
            $result[] = dechex($out[$i]);
        }
        return $result;
    }
}
