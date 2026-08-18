<?php
declare (strict_types=1);

namespace app\common\service;

use app\model\Substation;
use app\model\SubstationProfile;
use app\model\SubstationProfileAudit;
use think\Exception;
use think\facade\Db;
use think\Request;

class SubstationService
{
    public static function resolveByRequest(Request $request): ?array
    {
        return self::resolveByHost(self::extractHostFromRequest($request));
    }

    public static function resolveByHost(string $host): ?array
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return null;
        }
        $profile = SubstationProfile::where('full_domain', $host)->where('status', 1)->find();
        if (!$profile) {
            return null;
        }
        return [
            'is_substation' => 1,
            'substation_id' => (int)$profile['substation_id'],
            'substation_uid' => (int)$profile['uid'],
            'site_name' => (string)($profile['site_name'] ?? ''),
            'notice' => (string)($profile['notice'] ?? ''),
            'logo' => (string)($profile['logo'] ?? ''),
            'subdomain' => (string)($profile['subdomain'] ?? ''),
            'full_domain' => (string)($profile['full_domain'] ?? ''),
        ];
    }

    public static function extractHostFromRequest(Request $request): string
    {
        $forwardedHost = (string)$request->header('x-forwarded-host', '');
        $host = (string)$request->host();

        if ($forwardedHost !== '') {
            foreach (explode(',', $forwardedHost) as $item) {
                $normalizedHost = self::normalizeHost($item);
                if ($normalizedHost !== '') {
                    return $normalizedHost;
                }
            }
        }

        return self::normalizeHost($host);
    }

    public static function normalizeHost(string $host): string
    {
        $host = trim(strtolower($host));
        if ($host === '') {
            return '';
        }

        return preg_replace('/:\d+$/', '', $host) ?: '';
    }

    public static function getBaseDomain(): string
    {
        $baseDomain = self::normalizeHost((string)(getConfig('substation_base_domain') ?? ''));
        if ($baseDomain === '') {
            $baseDomain = 'example.com';
        }
        return $baseDomain;
    }

    public static function normalizeSubdomain(string $subdomain): string
    {
        $subdomain = strtolower(trim($subdomain));
        if ($subdomain === '') {
            throw new Exception('二级域名前缀不能为空');
        }

        $subdomain = preg_replace('/[^a-z0-9\-]/', '', $subdomain) ?: '';
        $subdomain = trim($subdomain, '-');
        if ($subdomain === '' || strlen($subdomain) < 2 || strlen($subdomain) > 50) {
            throw new Exception('二级域名前缀格式不正确，仅支持 2-50 位小写字母、数字、短横线');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/', $subdomain)) {
            throw new Exception('二级域名前缀格式不正确');
        }

        self::assertSubdomainAllowed($subdomain);

        return $subdomain;
    }

    public static function getBlockedSubdomains(): array
    {
        $raw = (string)(getConfig('substation_blocked_subdomains') ?? '');
        if ($raw === '') {
            return [];
        }
        $items = preg_split('/[\s,，]+/', strtolower($raw)) ?: [];
        $items = array_values(array_filter(array_unique(array_map(static function ($item) {
            return trim((string)$item);
        }, $items))));
        return $items;
    }

    public static function assertSubdomainAllowed(string $subdomain): void
    {
        $subdomain = strtolower(trim($subdomain));
        if ($subdomain === '') {
            return;
        }
        if (in_array($subdomain, self::getBlockedSubdomains(), true)) {
            throw new Exception('该二级域名前缀禁止使用');
        }
    }

    public static function buildFullDomain(string $subdomain, ?string $baseDomain = null): string
    {
        $subdomain = self::normalizeSubdomain($subdomain);
        $baseDomain = self::normalizeHost((string)($baseDomain ?: self::getBaseDomain()));
        return $baseDomain !== '' ? ($subdomain . '.' . $baseDomain) : $subdomain;
    }

    public static function assertDomainAvailable(string $fullDomain, int $excludeSubstationId = 0, int $excludeAuditId = 0): void
    {
        $profileQuery = SubstationProfile::where('full_domain', $fullDomain);
        if ($excludeSubstationId > 0) {
            $profileQuery->where('substation_id', '<>', $excludeSubstationId);
        }
        if ($profileQuery->find()) {
            throw new Exception('该域名前缀已被占用');
        }

        $pendingQuery = SubstationProfileAudit::where('full_domain', $fullDomain)->where('status', 0);
        if ($excludeSubstationId > 0) {
            $pendingQuery->where('substation_id', '<>', $excludeSubstationId);
        }
        if ($excludeAuditId > 0) {
            $pendingQuery->where('id', '<>', $excludeAuditId);
        }
        if ($pendingQuery->find()) {
            throw new Exception('该域名前缀已有待审核申请');
        }
    }

    public static function getOrCreateByUid(int $uid)
    {
        $row = Substation::where('uid', $uid)->find();
        if ($row) {
            return $row;
        }
        return Substation::create([
            'uid' => $uid,
            'status' => 0,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function submitAudit(int $uid, array $payload, int $auditType = 1)
    {
        return Db::transaction(function () use ($uid, $payload, $auditType) {
            $substation = self::getOrCreateByUid($uid);
            $substation = Substation::where('id', $substation['id'])->lock(true)->find();
            $pending = SubstationProfileAudit::where('substation_id', $substation['id'])->where('status', 0)->lock(true)->find();
            if ($pending) {
                throw new Exception('当前已有待审核申请，请勿重复提交');
            }

            $subdomain = self::normalizeSubdomain((string)($payload['subdomain'] ?? ''));
            $siteName = trim((string)($payload['site_name'] ?? ''));
            if ($siteName === '') {
                throw new Exception('网站名不能为空');
            }
            $fullDomain = self::buildFullDomain($subdomain, (string)($payload['base_domain'] ?? ''));
            self::assertDomainAvailable($fullDomain, (int)$substation['id']);

            $audit = SubstationProfileAudit::create([
                'substation_id' => (int)$substation['id'],
                'uid' => $uid,
                'audit_type' => $auditType,
                'subdomain' => $subdomain,
                'full_domain' => $fullDomain,
                'site_name' => $siteName,
                'notice' => (string)($payload['notice'] ?? ''),
                'logo' => (string)($payload['logo'] ?? ''),
                'status' => 0,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            if (!$audit) {
                throw new Exception('提交审核失败');
            }
            if ($auditType === 1) {
                $substation->status = 1;
                $substation->update_time = date('Y-m-d H:i:s');
                $substation->save();
            }
            return $audit;
        });
    }
}
