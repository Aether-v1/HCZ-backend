<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\common\service\SubstationService;
use app\model\Order;
use app\model\Phone;
use app\model\Product;
use app\model\Slide;
use think\facade\Db;

trait SiteActions
{
    private function normalizeFrontendConfigText(string $value): string
    {
        $text = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], ["\n", "\n", "\n", "\n", "\n", "\n"], $value);
        $text = preg_replace('/<[^>]*>/u', '', (string)$text);
        $text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\r\n?/", "\n", (string)$text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);

        return trim((string)$text);
    }

    private function normalizeFrontendConfigRowValue(string $key, $value)
    {
        if (!is_string($value)) {
            return $value;
        }

        if (in_array($key, ['notice', 'agent_jieshao', 'substation_open_intro', 'agreement', 'privacy_policy'], true)) {
            return $this->normalizeFrontendConfigText($value);
        }

        return $value;
    }

    private function isHomeFirstScreenMode(): bool
    {
        $scene = strtolower(trim((string)($this->request->get('scene', ''))));
        $summary = (string)($this->request->get('summary', ''));
        $lite = (string)($this->request->get('lite', ''));

        return in_array($scene, ['home', 'home_first_screen', 'home-first-screen'], true)
            || in_array($summary, ['1', 'true', 'yes'], true)
            || in_array($lite, ['1', 'true', 'yes'], true);
    }

    private function summarizeNotice(string $value): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $value)));
        if ($text === '') {
            return '';
        }

        return mb_substr($text, 0, 88);
    }

    protected function handleFooterPost(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'out_order':
                $confirm_order_count = Order::userVisibleQuery((int)$this->user_info['id'])->where('status', 'in', '2')->where('confirm_status', 'in', '1')->where('type', 1)->count();
                if(!empty($confirm_order_count)){
                    return show(200, 'success', '有待确认订单', $confirm_order_count);
                }
                return show(500, 'error', '无待确认订单');

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handlePhoneMeta()
    {
        $mobile = trim((string)($this->request->post('mobile', $this->request->get('mobile', ''))));
        if ($mobile === '') {
            return show(200, 'success', '查询成功', [
                'mobile' => '',
                'is_valid' => false,
                'province' => '',
                'city' => '',
                'carrier' => '',
            ]);
        }

        $isValid = preg_match('/^1[3-9]\d{9}$/', $mobile) === 1;
        if (!$isValid) {
            return show(200, 'success', '查询成功', [
                'mobile' => $mobile,
                'is_valid' => false,
                'province' => '',
                'city' => '',
                'carrier' => '',
            ]);
        }

        $info = Phone::find(substr($mobile, 0, 7));
        return show(200, 'success', '查询成功', [
            'mobile' => $mobile,
            'is_valid' => true,
            'province' => (string)($info['province'] ?? ''),
            'city' => (string)($info['city'] ?? ''),
            'carrier' => (string)($info['isp'] ?? phone_info($mobile) ?? ''),
        ]);
    }

    protected function handleApiProductDetail($id)
    {
        $product = Product::find((int)$id);
        if (!$product || (int)($product['status'] ?? 0) !== 1) {
            return show(404, 'error', '商品不存在', null, 404);
        }
        $substationContext = SubstationService::resolveByRequest($this->request);
        $substationId = (int)($substationContext['substation_id'] ?? 0);
        return show(200, 'success', '查询成功', $this->normalizeSiteProduct($product, '', $substationId));
    }

    protected function handleApiSiteConfig()
    {
        $configMap = $this->buildSiteConfigMap();
        $summaryOnly = $this->isHomeFirstScreenMode();
        $noticeText = $this->normalizeFrontendConfigText((string)($configMap['notice'] ?? ''));
        $agentIntroText = $this->normalizeFrontendConfigText((string)($configMap['agent_jieshao'] ?? ''));
        $slides = [];
        foreach (Slide::order('id', 'asc')->select() as $slide) {
            $slides[] = [
                'id' => (int)($slide['id'] ?? 0),
                'name' => (string)($slide['name'] ?? $slide['title'] ?? ''),
                'title' => (string)($slide['title'] ?? $slide['name'] ?? ''),
                'image' => (string)($slide['image'] ?? ''),
            ];
        }

        $allProducts = [];
        $substationContext = SubstationService::resolveByRequest($this->request);
        $substationId = (int)($substationContext['substation_id'] ?? 0);
        $products = Product::where('status', 1)->where('type', 1)->order('sort', 'desc')->select();
        foreach ($products as $product) {
            $allProducts[] = $this->normalizeSiteProduct($product, '', $substationId, $summaryOnly);
        }

        $featured = [];
        $aProduct = Product::find((int)($configMap['a_recommend_id'] ?? 0));
        $bProduct = Product::find((int)($configMap['b_recommend_id'] ?? 0));
        if ($aProduct) {
            $featured[] = $this->normalizeSiteProduct($aProduct, (string)($configMap['a_recommend_image'] ?? ''), $substationId, $summaryOnly);
        }
        if ($bProduct) {
            $featured[] = $this->normalizeSiteProduct($bProduct, (string)($configMap['b_recommend_image'] ?? ''), $substationId, $summaryOnly);
        }

        return show(200, 'success', '查询成功', [
            'name' => (string)($configMap['name'] ?? ''),
            'notice' => $summaryOnly ? $this->summarizeNotice($noticeText) : $noticeText,
            'payment_address' => (string)($configMap['payment_address'] ?? ''),
            'rate' => (string)($configMap['rate'] ?? ''),
            'agent_jieshao' => $agentIntroText,
            'agent_money' => (string)($configMap['agent_money'] ?? ''),
            'a_recommend_id' => (string)($configMap['a_recommend_id'] ?? ''),
            'a_recommend_image' => (string)($configMap['a_recommend_image'] ?? ''),
            'b_recommend_id' => (string)($configMap['b_recommend_id'] ?? ''),
            'b_recommend_image' => (string)($configMap['b_recommend_image'] ?? ''),
            'contact_service_url' => (string)($configMap['contact_service_url'] ?? ''),
            'contact_service_image' => (string)($configMap['contact_service_image'] ?? ''),
            'chatwoot_enabled' => (string)($configMap['chatwoot_enabled'] ?? '0'),
            'chatwoot_base_url' => (string)($configMap['chatwoot_base_url'] ?? ''),
            'chatwoot_token' => (string)($configMap['chatwoot_token'] ?? ''),
            'epay_alipay_enabled' => (string)($configMap['epay_alipay_enabled'] ?? '1') !== '0' ? '1' : '0',
            'epay_wechat_enabled' => (string)($configMap['epay_wechat_enabled'] ?? '1') !== '0' ? '1' : '0',
            'scene' => $summaryOnly ? 'home_first_screen' : 'full',
            'slides' => $slides,
            'featuredProducts' => $featured,
            'allProducts' => $allProducts,
        ]);
    }

    protected function handleApiHomeBootstrap()
    {
        return $this->handleApiSiteConfig();
    }
}
