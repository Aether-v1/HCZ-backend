<?php
declare (strict_types=1);

namespace app\controller;

use app\common\service\SubstationPriceService;
use app\common\service\SubstationService;
use think\App;
use think\Request;

class SiteSubstationApi
{
    protected Request $request;
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->request;
    }

    public function context()
    {
        $context = SubstationService::resolveByRequest($this->request);
        if (!$context) {
            $context = [
                'is_substation' => 0,
                'substation_id' => 0,
                'substation_uid' => 0,
                'site_name' => '',
                'notice' => '',
                'logo' => '',
            ];
        }
        return show(200, 'success', '查询成功', $context);
    }

    public function profile()
    {
        return $this->context();
    }

    public function productPrice()
    {
        try {
            $productId = (int)$this->request->get('product_id', 0);
            $amountMoney = (float)$this->request->get('amount', 0);
            $context = SubstationService::resolveByRequest($this->request);
            $substationId = (int)($context['substation_id'] ?? 0);
            $data = SubstationPriceService::resolveFinalPrice($productId, $amountMoney, $substationId);
            $data['substation_id'] = $substationId;
            return show(200, 'success', '查询成功', $data);
        } catch (\Throwable $e) {
            return show(500, 'error', $e->getMessage());
        }
    }
}
