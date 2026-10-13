<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Service;
final class Protocol {
    public const ISSUER = 'https://app.deferendum.com/integrations/nextcloud';
    public const RESOURCE = self::ISSUER . '/api/v1';
    public const SCOPE = 'deferendum:nextcloud:read';
    public const DISCUSSION_SCOPE = 'deferendum:nextcloud:discussion';
    public const PARTICIPATE_SCOPE = 'deferendum:nextcloud:participate';
    public const PARTICIPATION_ISSUER = 'https://app.deferendum.com/integrations/nextcloud-participation';
    public const PARTICIPATION_RESOURCE = self::PARTICIPATION_ISSUER . '/api/v2';
    public const CALLBACK_SUFFIX = '/index.php/apps/deferendum/oauth/callback';
    public const PROOF_SUFFIX = '/index.php/apps/deferendum/instance-proof';
    public static function scopes(string $value): array {
        $scopes = $value === '' ? [] : explode(' ', $value);
        if ($scopes === [] || count($scopes) !== count(array_unique($scopes))) { throw new ConnectorException('invalid_input'); }
        foreach ($scopes as $scope) { if (!in_array($scope, [self::SCOPE, self::DISCUSSION_SCOPE, self::PARTICIPATE_SCOPE], true)) { throw new ConnectorException('invalid_input'); } }
        if (!in_array(self::SCOPE, $scopes, true) || (in_array(self::PARTICIPATE_SCOPE, $scopes, true) && !in_array(self::DISCUSSION_SCOPE, $scopes, true))) { throw new ConnectorException('invalid_input'); }
        sort($scopes); return $scopes;
    }
    public static function scopeString(array $scopes): string { return implode(' ', self::scopes(implode(' ', $scopes))); }
    public static function issuer(string $value): string { if(!in_array($value,[self::ISSUER,self::PARTICIPATION_ISSUER],true)) { throw new ConnectorException('invalid_input'); } return $value; }
    public static function connectionBinding(string $value): string { if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$value)) { throw new ConnectorException('invalid_input'); } return $value; }
    public static function canonicalJson(mixed $value): string {
        if(is_array($value)) { if(array_is_list($value))return '['.implode(',',array_map(self::canonicalJson(...),$value)).']';ksort($value,SORT_STRING);$entries=[];foreach($value as $key=>$item)$entries[]=self::canonicalJson((string)$key).':'.self::canonicalJson($item);return '{'.implode(',',$entries).'}'; }
        if(!is_null($value)&&!is_bool($value)&&!is_string($value)&&!is_int($value)&&!is_float($value)) { throw new ConnectorException('invalid_input'); }
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS);
    }
    public static function operationId(string $value): string {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value)) { throw new ConnectorException('invalid_input'); }
        return $value;
    }
    public static function boolean(mixed $value): bool { if (!is_bool($value)) { throw new ConnectorException(); } return $value; }
    public static function integer(mixed $value, int $maximum = 2147483647): int { if (!is_int($value) || $value < 0 || $value > $maximum) { throw new ConnectorException(); } return $value; }
    public static function number(mixed $value, float $maximum=6): int|float { if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value<0 || $value>$maximum) { throw new ConnectorException(); } return $value; }
    public static function identifier(string $value): string {
        if (!preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $value)) { throw new ConnectorException('invalid_input'); }
        return $value;
    }
    public static function baseUrl(string $value): string {
        if (strlen($value) > 2048 || preg_match('/[\\\\\x00-\x20]/', $value)) { throw new ConnectorException('invalid_input'); }
        $parts = parse_url($value);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) { throw new ConnectorException('invalid_input'); }
        $host = strtolower($parts['host']);
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host) || filter_var($host, FILTER_VALIDATE_IP) || preg_match('/\.(?:localhost|local|internal|test|invalid|example)$/D', $host)) { throw new ConnectorException('invalid_input'); }
        $path = rtrim($parts['path'] ?? '', '/');
        if (str_contains($path, '%') || str_contains($path, '//') || preg_match('~(?:^|/)\.\.?(/|$)~', $path)) { throw new ConnectorException('invalid_input'); }
        return 'https://' . $host . $path;
    }
    public static function language(string $value): string {
        $primary = strtolower(explode('_', str_replace('-', '_', $value))[0]);
        return in_array($primary, ['ro', 'en', 'fr', 'es', 'pt'], true) ? $primary : 'en';
    }
    public static function sourceUrl(mixed $value): string {
        if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\\\\\x00-\x20]/', $value)) { throw new ConnectorException(); }
        $p = parse_url($value);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') !== 'app.deferendum.com' || isset($p['user']) || isset($p['pass']) || isset($p['port']) || !str_starts_with($p['path'] ?? '', '/')) { throw new ConnectorException(); }
        return $value;
    }
    public static function text(mixed $value, int $max = 10000): string {
        if (!is_string($value) || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8')) { throw new ConnectorException(); }
        return $value;
    }
    public static function date(mixed $value): ?string {
        if ($value === null) { return null; }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/D', $value) || strtotime($value) === false) { throw new ConnectorException(); }
        return $value;
    }
}
