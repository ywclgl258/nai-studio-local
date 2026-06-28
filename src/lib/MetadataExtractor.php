<?php
/**
 * NAI Studio - PNG metadata extractor
 * Parses PNG tEXt / iTXt chunks, including NAI / SD-style metadata, and EXIF.
 *
 * Recognized keys:
 *   - Software, Comment, Description (NAI)
 *   - Source, Title, Author
 *   - NAI-specific: software comment "NAI Diffusion ..." with JSON params
 *   - SD-style: parameters (full string) and png:parameters
 */

declare(strict_types=1);

namespace NaiStudio;

class MetadataExtractor {
    /**
     * Extract metadata from an image file path.
     * @return array<string,mixed>
     */
    public static function fromFile(string $path): array {
        if (!is_file($path)) return ['error' => 'File not found'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $data = ['path' => $path, 'type' => $ext];
        if ($ext === 'png') {
            $data = array_merge($data, self::parsePng($path));
        } elseif (in_array($ext, ['jpg', 'jpeg'], true)) {
            $data = array_merge($data, self::parseJpeg($path));
        } elseif ($ext === 'webp') {
            $data = array_merge($data, self::parseWebp($path));
        }
        $data['prompt']     = $data['prompt']     ?? null;
        $data['negative']   = $data['negative']   ?? null;
        $data['model']      = $data['model']      ?? null;
        $data['sampler']    = $data['sampler']    ?? null;
        $data['steps']      = isset($data['steps']) ? (int)$data['steps'] : null;
        $data['scale']      = isset($data['scale']) ? (float)$data['scale'] : null;
        $data['seed']       = isset($data['seed']) ? (int)$data['seed'] : null;
        $data['width']      = isset($data['width']) ? (int)$data['width'] : null;
        $data['height']     = isset($data['height']) ? (int)$data['height'] : null;
        $data['cfg_rescale']= isset($data['cfg_rescale']) ? (float)$data['cfg_rescale'] : null;
        $data['noise_schedule'] = $data['noise_schedule'] ?? null;
        $data['size']       = $data['size']       ?? filesize($path);
        return $data;
    }

    public static function parsePng(string $path): array {
        $out = [];
        $f = fopen($path, 'rb');
        if (!$f) return ['error' => 'Cannot open'];
        $sig = fread($f, 8);
        if ($sig !== "\x89PNG\r\n\x1a\n") {
            fclose($f);
            return ['error' => 'Not a PNG'];
        }
        while (!feof($f)) {
            $lenBytes = fread($f, 4);
            if (strlen($lenBytes) < 4) break;
            $len = unpack('N', $lenBytes)[1];
            $type = fread($f, 4);
            $data = $len > 0 ? fread($f, $len) : '';
            $crc = fread($f, 4);
            if (in_array($type, ['tEXt', 'iTXt', 'zTXt'], true)) {
                $parts = explode("\x00", $data, 2);
                $key = $parts[0] ?? '';
                $val = $parts[1] ?? '';
                if ($type === 'zTXt' && isset($parts[1])) {
                    // Compressed text: method byte + compressed data
                    $method = ord($parts[1][0] ?? "\x00");
                    $comp = substr($parts[1], 1);
                    $val = $method === 0 ? @gzuncompress($comp) : @gzinflate($comp);
                    if ($val === false) $val = '';
                } elseif ($type === 'iTXt') {
                    // iTXt: keyword \0 compflag compmethod langtag \0 transkey \0 text
                    $rest = $data;
                    $keyEnd = strpos($rest, "\x00");
                    $key = substr($rest, 0, $keyEnd);
                    $rest = substr($rest, $keyEnd + 1);
                    $compFlag = ord($rest[0] ?? "\x00");
                    $rest = substr($rest, 1);
                    $compMethod = ord($rest[0] ?? "\x00");
                    $rest = substr($rest, 1);
                    $langEnd = strpos($rest, "\x00");
                    $rest = substr($rest, $langEnd + 1);
                    $transEnd = strpos($rest, "\x00");
                    $rest = substr($rest, $transEnd + 1);
                    $val = $rest;
                    if ($compFlag === 1) {
                        $val = $compMethod === 0 ? @gzuncompress($val) : @gzinflate($val);
                        if ($val === false) $val = '';
                    }
                }
                $out[$key] = $val;
            } elseif ($type === 'IHDR') {
                $ihdr = unpack('Nwidth/Nheight', $data);
                $out['width']  = $ihdr['width'];
                $out['height'] = $ihdr['height'];
            } elseif ($type === 'IEND') {
                break;
            }
        }
        fclose($f);

        // Parse SD-style "parameters" or "Description" into structured fields
        $paramsStr = $out['parameters'] ?? $out['Description'] ?? $out['Comment'] ?? null;
        if (is_string($paramsStr)) {
            $parsed = self::parseSdParameters($paramsStr);
            foreach ($parsed as $k => $v) {
                if (!isset($out[$k]) || $out[$k] === null) {
                    $out[$k] = $v;
                }
            }
        }
        // Try to extract NAI prompt from "Software" comment
        if (isset($out['Software']) && str_contains($out['Software'], 'NAI')) {
            // NAI may embed full prompt in comment as JSON
            $j = json_decode($out['Software'], true);
            if (is_array($j)) {
                foreach (['prompt','negative_prompt','model','sampler','steps','scale','seed','width','height'] as $k) {
                    if (isset($j[$k]) && empty($out[$k])) $out[$k] = $j[$k];
                }
            }
        }
        return $out;
    }

    public static function parseJpeg(string $path): array {
        $out = [];
        // EXIF
        if (function_exists('exif_read_data')) {
            $ex = @exif_read_data($path, 'IFD0,EXIF', true);
            if (is_array($ex)) {
                foreach (['Make','Model','DateTime','ImageWidth','ImageLength','UserComment'] as $k) {
                    if (isset($ex['IFD0'][$k])) $out[$k] = $ex['IFD0'][$k];
                    elseif (isset($ex['EXIF'][$k])) $out[$k] = $ex['EXIF'][$k];
                }
            }
        }
        return $out;
    }

    public static function parseWebp(string $path): array {
        $out = [];
        // WebP: RIFF + EXIF chunk
        $f = fopen($path, 'rb');
        if (!$f) return [];
        $riff = fread($f, 12);
        if (substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WEBP') {
            fclose($f); return [];
        }
        while (!feof($f)) {
            $h = fread($f, 8);
            if (strlen($h) < 8) break;
            [$fourcc, $size] = [substr($h, 0, 4), unpack('V', substr($h, 4, 4))[1]];
            $data = fread($f, $size + ($size & 1));
            if ($fourcc === 'EXIF') {
                $exif = @exif_read_data('data://image/webp;base64,' . base64_encode($data));
                if (is_array($exif)) {
                    foreach (['Make','Model','DateTime','UserComment'] as $k) {
                        if (isset($exif[$k])) $out[$k] = $exif[$k];
                    }
                }
            }
        }
        fclose($f);
        return $out;
    }

    /**
     * Parse Stable Diffusion-style parameters string:
     *   "positive prompt\nNegative prompt: negative\nSteps: 28, Sampler: ..., CFG scale: 5, Seed: 123, Size: 832x1216, ..."
     */
    public static function parseSdParameters(string $str): array {
        $out = [];
        $str = trim($str);
        // Split on "Negative prompt:" to isolate positive and negative
        $negIdx = stripos($str, 'Negative prompt:');
        if ($negIdx !== false) {
            $positive = trim(substr($str, 0, $negIdx));
            $rest = substr($str, $negIdx + strlen('Negative prompt:'));
            $nlPos = strpos($rest, "\n");
            if ($nlPos !== false) {
                $negative = trim(substr($rest, 0, $nlPos));
                $paramsLine = substr($rest, $nlPos);
            } else {
                // No further newline; try splitting on "Steps:"
                $stepPos = stripos($rest, 'Steps:');
                if ($stepPos !== false) {
                    $negative = trim(substr($rest, 0, $stepPos));
                    $paramsLine = substr($rest, $stepPos);
                } else {
                    $negative = trim($rest);
                    $paramsLine = '';
                }
            }
            $out['prompt']   = $positive;
            $out['negative'] = $negative;
        } else {
            $out['prompt'] = $str;
        }
        // Parse the params line (key: value, key: value, ...)
        if (!empty($paramsLine)) {
            $paramsLine = trim($paramsLine, " ,\n\r");
            $pairs = preg_split('/,\s+/', $paramsLine);
            foreach ($pairs as $pair) {
                if (preg_match('/^\s*([A-Za-z][A-Za-z _]*?):\s*(.+?)\s*$/', $pair, $m)) {
                    $key = trim($m[1]);
                    $val = trim($m[2]);
                    $keyLower = strtolower($key);
                    $valLower = strtolower($val);
                    if ($keyLower === 'steps')              $out['steps'] = (int)$val;
                    elseif ($keyLower === 'sampler')         $out['sampler'] = $val;
                    elseif ($keyLower === 'cfg scale')       $out['scale'] = (float)$val;
                    elseif ($keyLower === 'seed')            $out['seed'] = (int)$val;
                    elseif ($keyLower === 'size') {
                        if (preg_match('/^(\d+)x(\d+)$/', $val, $sz)) {
                            $out['width']  = (int)$sz[1];
                            $out['height'] = (int)$sz[2];
                        }
                    }
                    elseif ($keyLower === 'model')           $out['model'] = $val;
                    elseif ($keyLower === 'model hash')      {} // skip
                    elseif ($keyLower === 'schedule type')   $out['noise_schedule'] = $valLower;
                    elseif ($keyLower === 'cfg rescale')     $out['cfg_rescale'] = (float)$val;
                    elseif ($keyLower === 'version')         $out['software_version'] = $val;
                    elseif ($keyLower === 'denoising strength') $out['strength'] = (float)$val;
                }
            }
        }
        return $out;
    }
}
