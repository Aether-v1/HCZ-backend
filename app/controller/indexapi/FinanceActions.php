<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\model\BankCard;
use app\model\Order;
use app\model\Product;
use app\model\Recharge;
use app\model\RebateRecord;
use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\User as UserModel;
use app\model\UserBalanceLog;
use app\model\Withdrawal;
use app\model\PointsRecord;
use app\model\Substation;
use app\service\ActionRateLimiter;
use app\service\BepusdtService;
use app\service\UserFundLedgerService;
use app\service\UserFundLogLabelService;
use app\service\telegram\OrderTelegramNotifier;
use Exception;
use think\facade\Db;
use think\facade\Log;

/**
 * Finance actions are mixed into {@see \app\controller\IndexApi}.
 *
 * This PHPDoc is for static analyzers/IDEs: the trait uses controller
 * properties and helper methods provided by IndexApi at runtime.
 *
 * @mixin \app\controller\IndexApi
 * @property \think\Request $request
 * @property mixed $user_info
 * @method mixed apiFromLegacyResult(mixed $result, string $successMessage = 'success')
 * @method string directMoney(mixed $value, int $scale = 2)
 * @method string directQrUrl(string $text = '')
 * @method float directSumOrderActualPayUsdt(mixed $query)
 * @method float directSumRefundLogAmount(int $uid, ?array $between = null)
 * @method mixed directLockUser(int $uid)
 * @method void directWriteBalanceLog(array $data)
 * @method void logApiException(string $scene, \Throwable $e, array $context = [])
 * @method string buildRechargeProofViewUrl(string $orderNumber, string $storedPath = '')
 * @method bool requestHasProofUploadInput(array $fieldNames)
 * @method object|string|null extractProofUploadSource(array $fieldNames)
 * @method string persistPrivateProofUpload(object|string $source, string $scene, string $orderNumber, string $existingPath = '')
 */
trait FinanceActions
{
	protected function directBepusdtNotifyUrl(): string
	{
		$configUrl = trim((string)config('bepusdt.notify_url'));
		if ($configUrl !== '') {
			return $configUrl;
		}

		return (string)url('/api/callback/bepusdt')->suffix('')->domain(true);
	}
	protected function directEpayReturnUrl(string $orderNumber): string
	{
		$baseUrl = trim((string)config('telegram.website_url'));
		if ($baseUrl === '') {
			$baseUrl = (string)$this->request->domain();
		}

		$baseUrl = rtrim($baseUrl, '/');
		if ($baseUrl === '') {
			return (string)url('/finance-center')->suffix('')->domain(true) . '?tab=recharge';
		}

		return $baseUrl . '/finance-center?tab=recharge';
	}

	protected function directBepusdtRedirectUrl(string $orderNumber): string
	{
		$configUrl = trim((string)config('bepusdt.redirect_url'));
		if ($configUrl !== '') {
			return $configUrl;
		}

		return $this->directEpayReturnUrl($orderNumber);
	}

	private function directGatewayPayUrl($recharge): string
	{
		$raw = $recharge['gateway_raw'] ?? '';
		$payload = is_array($raw) ? $raw : json_decode((string)$raw, true);
		if (!is_array($payload)) {
			return '';
		}

		foreach ([$payload, (array)($payload['data'] ?? [])] as $candidate) {
			foreach (['payment_url', 'pay_url', 'payurl', 'urlscheme', 'qrcode', 'url', 'checkout_url'] as $key) {
				$value = trim((string)($candidate[$key] ?? ''));
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}
	protected function directEpayNotifyUrl(): string
	{
		$explicitUrl = trim((string)env('EPAY_NOTIFY_URL', ''));
		if ($explicitUrl !== '') {
			return $explicitUrl;
		}

		$appHost = trim((string)config('app.app_host'));
		if ($appHost !== '') {
			if (preg_match('#^https?://#i', $appHost)) {
				return rtrim($appHost, '/') . '/epay_notify_url';
			}

			$scheme = $this->request->isSsl() ? 'https://' : 'http://';
			return rtrim($scheme . ltrim($appHost, '/'), '/') . '/epay_notify_url';
		}

		$domainBindRaw = trim((string)env('APP_DOMAIN_BIND', ''));
		if ($domainBindRaw !== '') {
			foreach (array_filter(array_map('trim', explode(',', $domainBindRaw))) as $pair) {
				[$domain] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
				if ($domain === '') {
					continue;
				}

				$scheme = $this->request->isSsl() ? 'https://' : 'http://';
				if (str_starts_with($domain, 'api.')) {
					return rtrim($scheme . $domain, '/') . '/epay_notify_url';
				}
			}
		}

		return (string)url('/epay_notify_url')->suffix('')->domain(true);
	}
	protected function financeSystemErrorMessage(): string
	{
		return 'System busy, please try again later';
	}

	protected function handleWalletDetailsPost(string $action)
	{
		$post_info = $this->request->post();
		$user_info = UserModel::where('id', $this->user_info['id'])->find();
		switch ($action) {
			case 'wallet_details':
				$data = [];
				$Recharge_amount = Recharge::where('uid', $user_info['id'])->where('operate_type', 0)->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('amount');

				$data['recharge_amount'] = $Recharge_amount;
				$data['total_income'] = $Recharge_amount;

				$RebateRecord_amount = RebateRecord::where('tid', $user_info['id'])->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('amount');
				$data['rebate_record_amount'] = $RebateRecord_amount;

				$TransactionOrder_u_amount = TransactionOrder::where('uid', $user_info['id'])->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('usdt_amount');
				$data['transaction_order_u_amount'] = $TransactionOrder_u_amount;

				$TransactionOrder_t_amount = TransactionOrder::where('sell_uid', $user_info['id'])->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('pay_amount');
				$data['transaction_order_t_amount'] = $TransactionOrder_t_amount;

				$Product_data = Product::select();
				foreach ($Product_data as $key => $vo) {
					if ($vo['type'] == 1) {
						$data['product_' . $vo['id']] = $this->directSumOrderActualPayUsdt(Order::where('uid', $user_info['id'])->where('product_id', $vo['id'])->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']]));
					}
				}

				$data['query_business'] = Order::where('uid', $user_info['id'])->where('type', 2)->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('cny_amount');
				$cny_amount = Order::where('uid', $user_info['id'])->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('cny_amount');
				$data['query_business'] = $this->directSumOrderActualPayUsdt(Order::where('uid', $user_info['id'])->where('type', 2)->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']]));
				$cny_amount = $this->directSumOrderActualPayUsdt(Order::where('uid', $user_info['id'])->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']]));
				$withdrawal_amount = Withdrawal::where('uid', $user_info['id'])->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('amount');
				$data['withdrawal_amount'] = number_format($withdrawal_amount, 2);
				$data['total_expenditure'] = number_format($cny_amount + $withdrawal_amount, 2);

				$order_1 = Order::where('uid', $user_info['id'])->where('status', 'in', '0,1,2')->where('confirm_status', 'in', '0,1,3')->where('type', 1)->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('cny_amount');
				$order_2 = Order::where('uid', $user_info['id'])->where('status', 'in', '0,1')->where('type', 2)->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('cny_amount');
				$order_1 = $this->directSumOrderActualPayUsdt(Order::where('uid', $user_info['id'])->where('status', 'in', '0,1,2')->where('confirm_status', 'in', '0,1,3')->where('type', 1)->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']]));
				$order_2 = $this->directSumOrderActualPayUsdt(Order::where('uid', $user_info['id'])->where('status', 'in', '0,1')->where('type', 2)->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']]));
				$T_product = TransactionProduct::where('uid', $this->user_info['id'])->where('status', 'in', '1,2')->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('sell_account');

				$data['freeze_amount'] = number_format($order_1 + $order_2 + $T_product, 2);

				$refund_amount = Order::where('uid', $user_info['id'])->where('status', 3)->whereTime('create_time', 'between', [$post_info['start_time'], $post_info['end_time']])->sum('cny_amount');
				$refund_amount = $this->directSumRefundLogAmount((int) $user_info['id'], [$post_info['start_time'], $post_info['end_time']]);
				$data['refund_amount'] = number_format($refund_amount, 2);
				return show(200, 'success', 'Query success', $data);

			default:
				return show(500, 'error', 'Request error');
		}
	}

	protected function handleApiFinanceWalletDetails()
	{
		$input = array_merge((array) $this->request->get(), (array) $this->request->post());
		$startTime = trim((string) ($input['start_time'] ?? $input['startDate'] ?? ''));
		$endTime = trim((string) ($input['end_time'] ?? $input['endDate'] ?? ''));

		if ($startTime === '') {
			$startTime = date('Y-m-01 00:00:00');
		}
		if ($endTime === '') {
			$endTime = date('Y-m-d 23:59:59');
		}

		$this->request->withPost(array_merge($input, [
			'start_time' => $startTime,
			'end_time' => $endTime,
		]));

		return $this->apiFromLegacyResult($this->handleWalletDetailsPost('wallet_details'), 'Query success');
	}

	protected function handleApiFinanceDetailSummary()
	{
		$uid = (int) ($this->user_info['id'] ?? 0);

		$freezeAmount = $this->directFinanceLedgerWalletNetAmount($uid, UserFundLedgerService::WALLET_FROZEN);
		$refundAmount = $this->directFinanceLedgerWalletTypeTotalAmount($uid, UserFundLedgerService::WALLET_BALANCE, '2');
		$queryBusiness = $this->directFinanceLedgerWalletTypeTotalAmount($uid, UserFundLedgerService::WALLET_BALANCE, '3');
		$withdrawalAmount = $this->directFinanceLedgerWalletTypeTotalAmount($uid, UserFundLedgerService::WALLET_BALANCE, '4');
		$rechargeAmount = $this->directFinanceLedgerWalletTypeTotalAmount($uid, UserFundLedgerService::WALLET_BALANCE, '5');
		$agentIncomeAmount = $this->directFinanceLedgerWalletTypeTotalAmount($uid, UserFundLedgerService::WALLET_BALANCE, '6');
		$buyAmount = $this->directFinanceLedgerWalletTypeTotalAmount($uid, UserFundLedgerService::WALLET_BALANCE, '7');
		$sellAmount = (float) TransactionOrder::where('sell_uid', $uid)->sum('pay_amount');

		return show(200, 'success', 'Query success', [
			'freeze_amount' => $this->directMoney($freezeAmount),
			'frozen_amount' => $this->directMoney($freezeAmount),
			'refund_amount' => $this->directMoney($refundAmount),
			'query_business' => $this->directMoney($queryBusiness),
			'withdrawal_amount' => $this->directMoney($withdrawalAmount, 6),
			'recharge_amount' => $this->directMoney($rechargeAmount),
			'agent_income_amount' => $this->directMoney($agentIncomeAmount),
			'rebate_record_amount' => $this->directMoney($agentIncomeAmount),
			'transaction_order_u_amount' => $this->directMoney($buyAmount, 6),
			'transaction_order_t_amount' => $this->directMoney($sellAmount, 6),
			'updated_at' => date('Y-m-d H:i:s'),
		]);
	}

	private function directFinanceLedgerWalletTypeTotalAmount(int $uid, string $walletType, string $type): float
	{
		$changeTypes = $walletType === UserFundLedgerService::WALLET_BALANCE
			? $this->directFinanceBalanceChangeTypesByType($type)
			: [];

		$query = \app\model\UserFundLog::where('uid', $uid)
			->where('wallet_type', $walletType);

		if ($changeTypes !== []) {
			$query->whereIn('change_type', $changeTypes);
		}

		return round((float) $query->sum('amount'), 2);
	}

	private function directFinanceLedgerWalletNetAmount(int $uid, string $walletType): float
	{
		$rows = \app\model\UserFundLog::where('uid', $uid)
			->where('wallet_type', $walletType)
			->field(['direction', 'amount'])
			->select();

		$total = 0.0;
		foreach ($rows as $row) {
			$amount = round((float) ($row['amount'] ?? 0), 2);
			$total += (string) ($row['direction'] ?? '') === UserFundLedgerService::DIRECTION_OUT
				? -1 * $amount
				: $amount;
		}

		return round(max(0, $total), 2);
	}

protected function handleApiFinanceDetailRecords()
{
	$type = (string) $this->request->get('type', '');
	$page = max(1, (int) $this->request->get('page', 1));
	$pageSize = max(1, min(100, (int) $this->request->get('pageSize', 20)));
	$uid = (int) ($this->user_info['id'] ?? 0);
	$records = [];

	switch ($type) {
		case '1':
			return $this->directBuildFrozenDetailRecordsResponse($uid, $page, $pageSize);
		case '2':
		case '3':
		case '4':
		case '5':
		case '6':
		case '7':
			return $this->directBuildBalanceDetailRecordsResponse($uid, $page, $pageSize, $type);
		case '8':
			$query = TransactionOrder::where('sell_uid', $uid);
			$total = (int) (clone $query)->count();
			$totalAmount = (float) (clone $query)->sum('pay_amount');
			$rows = $query->order('id', 'desc')
				->page($page, $pageSize)
				->select();
			foreach ($rows as $vo) {
				$records[] = [
					'id' => (int) ($vo['id'] ?? 0),
					'title' => '交易卖出',
					'amount' => $this->directMoney($vo['pay_amount'] ?? 0, 2),
					'text' => '交易卖出相关记录 #' . (string) ($vo['order_number'] ?? ''),
					'order_number' => (string) ($vo['order_number'] ?? ''),
					'direction' => UserFundLedgerService::DIRECTION_OUT,
					'unit' => 'USDT',
					'create_time' => (string) ($vo['create_time'] ?? ''),
					'date' => (string) ($vo['create_time'] ?? ''),
				];
			}

			return show(200, 'success', 'Query success', [
				'records' => $records,
				'list' => $records,
				'page' => $page,
				'pageSize' => $pageSize,
				'total' => $total,
				'totalPages' => max(1, (int) ceil($total / $pageSize)),
				'totalAmount' => $this->directMoney($totalAmount, 6),
				'type' => $type,
			]);
		default:
			return show(400, 'error', 'Invalid detail type', [
				'page' => 1,
				'pageSize' => $pageSize,
				'total' => 0,
				'totalPages' => 1,
				'totalAmount' => '0.00',
			], 400);
	}

	usort($records, function ($a, $b) {
		return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
	});
	return show(200, 'success', 'Query success', [
		'records' => [],
		'list' => [],
		'page' => 1,
		'pageSize' => $pageSize,
		'total' => 0,
		'totalPages' => 1,
		'totalAmount' => $this->directMoney(0, 6),
		'type' => $type,
	]);
}

private function directBuildBalanceDetailRecordsResponse(int $uid, int $page, int $pageSize, string $type)
{
	$changeTypes = $this->directFinanceBalanceChangeTypesByType($type);
	$startTime = trim((string) $this->request->get('start_time', $this->request->get('startDate', '')));
	$endTime = trim((string) $this->request->get('end_time', $this->request->get('endDate', '')));

	$query = \app\model\UserFundLog::where('uid', $uid)
		->where('wallet_type', UserFundLedgerService::WALLET_BALANCE);

	if ($changeTypes !== []) {
		$query->whereIn('change_type', $changeTypes);
	}
	if ($startTime !== '') {
		$query->where('create_time', '>=', $startTime);
	}
	if ($endTime !== '') {
		$query->where('create_time', '<=', $endTime);
	}

	$total = (int) (clone $query)->count();
	$totalAmount = (float) (clone $query)->sum('amount');
	$list = $query->order('id', 'desc')
		->page($page, $pageSize)
		->select()
		->toArray();

	$records = [];
	foreach ($list as $row) {
		$records[] = $this->directFormatBalanceDetailRecord($row, $type);
	}

	return show(200, 'success', 'Query success', [
		'records' => $records,
		'list' => $records,
		'page' => $page,
		'pageSize' => $pageSize,
		'total' => $total,
		'totalPages' => max(1, (int) ceil($total / $pageSize)),
		'totalAmount' => $this->directMoney($totalAmount, 6),
		'type' => $type,
	]);
}

private function directBuildFrozenDetailRecordsResponse(int $uid, int $page, int $pageSize)
{
	$startTime = trim((string) $this->request->get('start_time', $this->request->get('startDate', '')));
	$endTime = trim((string) $this->request->get('end_time', $this->request->get('endDate', '')));

	$query = \app\model\UserFundLog::where('uid', $uid)
		->where('wallet_type', UserFundLedgerService::WALLET_FROZEN);

	if ($startTime !== '') {
		$query->where('create_time', '>=', $startTime);
	}
	if ($endTime !== '') {
		$query->where('create_time', '<=', $endTime);
	}

	$total = (int) (clone $query)->count();
	$totalAmount = (float) (clone $query)->sum('amount');
	$list = $query->order('id', 'desc')
		->page($page, $pageSize)
		->select()
		->toArray();

	$records = [];
	foreach ($list as $row) {
		$records[] = $this->directFormatFrozenDetailRecord($row);
	}

	return show(200, 'success', 'Query success', [
		'records' => $records,
		'list' => $records,
		'page' => $page,
		'pageSize' => $pageSize,
		'total' => $total,
		'totalPages' => max(1, (int) ceil($total / $pageSize)),
		'totalAmount' => $this->directMoney($totalAmount, 6),
		'type' => '1',
	]);
}

private function directFormatFrozenDetailRecord(array $row): array
{
	$changeType = trim((string) ($row['change_type'] ?? ''));
	$title = $this->directFinanceFrozenChangeTypeLabel($changeType);
	$createdAt = (string) ($row['create_time'] ?? $row['created_at'] ?? '');
	$orderNumber = trim((string) ($row['order_number'] ?? $row['biz_no'] ?? ''));
	$remark = trim((string) ($row['remark'] ?? ''));
	$text = $remark !== '' ? $remark : $title;

	if ($text === $title && $orderNumber !== '') {
		$text .= ' #' . $orderNumber;
	}

	return [
		'id' => (int) ($row['id'] ?? 0),
		'biz_id' => (int) ($row['biz_id'] ?? 0),
		'request_no' => (string) ($row['request_no'] ?? ''),
		'amount' => $this->directMoney($row['amount'] ?? 0),
		'change_type' => $changeType,
		'direction' => (string) ($row['direction'] ?? ''),
		'order_number' => $orderNumber,
		'created_at' => $createdAt,
		'create_time' => $createdAt,
		'title' => $title,
		'text' => $text,
		'remark' => $remark,
		'unit' => 'USDT',
		'date' => $createdAt,
	];
}

private function directFinanceFrozenChangeTypeLabel(string $changeType): string
{
	return (new UserFundLogLabelService())->displayLabel($changeType, UserFundLedgerService::WALLET_FROZEN);
}

private function directFinanceBalanceChangeTypesByType(string $type): array
{
	$map = [
		'2' => [
			'product_order_cancel_refund',
			'product_order_partial_refund',
		],
		'3' => [
			'substation_wallet_to_balance',
		],
		'4' => [
			'withdraw_freeze_out',
			'withdraw_reject_refund',
		],
		'5' => [
			'recharge_paid',
			'recharge_manual_paid',
		],
		'6' => [
			'agent_wallet_transfer_in',
		],
		'7' => [
			'transaction_buyer_income',
		],
	];

	return $map[$type] ?? [];
}

private function directFinanceBalanceTypeTitle(string $type): string
{
	$map = [
		'2' => "\u{8BA2}\u{5355}\u{9000}\u{6B3E}",
		'3' => "\u{5206}\u{7AD9}\u{6536}\u{76CA}",
		'4' => "\u{4F59}\u{989D}\u{63D0}\u{73B0}",
		'5' => "\u{4F59}\u{989D}\u{5145}\u{503C}",
		'6' => "\u{4EE3}\u{7406}\u{6536}\u{76CA}",
		'7' => "\u{4EA4}\u{6613}\u{4E70}\u{5165}",
	];

	return $map[$type] ?? "\u{4F59}\u{989D}\u{6D41}\u{6C34}";
}

private function directFormatBalanceDetailRecord(array $row, string $type): array
{
	$changeType = trim((string) ($row['change_type'] ?? ''));
	$createdAt = (string) ($row['create_time'] ?? $row['created_at'] ?? '');
	$orderNumber = trim((string) ($row['order_number'] ?? $row['biz_no'] ?? ''));
	$title = $this->directFinanceBalanceTypeTitle($type);
	$text = $this->directFinanceBalanceRecordText($row, $changeType);

	return [
		'id' => (int) ($row['id'] ?? 0),
		'biz_id' => (int) ($row['biz_id'] ?? 0),
		'request_no' => (string) ($row['request_no'] ?? ''),
		'amount' => $this->directMoney($row['amount'] ?? 0),
		'change_type' => $changeType,
		'direction' => (string) ($row['direction'] ?? ''),
		'order_number' => $orderNumber,
		'created_at' => $createdAt,
		'create_time' => $createdAt,
		'title' => $title,
		'text' => $text,
		'remark' => trim((string) ($row['remark'] ?? '')),
		'unit' => 'USDT',
		'date' => $createdAt,
	];
}

private function directFinanceBalanceRecordText(array $row, string $changeType): string
{
	$orderNumber = trim((string) ($row['order_number'] ?? $row['biz_no'] ?? ''));
	if ($changeType === 'agent_wallet_transfer_in') {
		$agentTransferText = "\u{4EE3}\u{7406}\u{94B1}\u{5305}\u{8F6C}\u{5165}\u{4F59}\u{989D}";
		return $orderNumber !== '' ? $agentTransferText . ' #' . $orderNumber : $agentTransferText;
	}

	$label = $this->directFinanceBalanceChangeTypeLabel($changeType);
	if ($orderNumber !== '') {
		return $label . ' #' . $orderNumber;
	}

	return $label;
}

private function directFinanceBalanceChangeTypeLabel(string $changeType): string
{
	$label = (new UserFundLogLabelService())->displayLabel($changeType, UserFundLedgerService::WALLET_BALANCE);
	if ($label !== '') {
		return $label;
	}

	$fallbackMap = [
		'product_order_cancel_refund' => "\u{8BA2}\u{5355}\u{9000}\u{6B3E}",
		'product_order_partial_refund' => "\u{8BA2}\u{5355}\u{9000}\u{6B3E}",
		'substation_wallet_to_balance' => "\u{5206}\u{7AD9}\u{6536}\u{76CA}",
		'withdraw_freeze_out' => "\u{4F59}\u{989D}\u{63D0}\u{73B0}",
		'withdraw_reject_refund' => "\u{63D0}\u{73B0}\u{9000}\u{56DE}",
		'recharge_paid' => "\u{4F59}\u{989D}\u{5145}\u{503C}",
		'recharge_manual_paid' => "\u{4F59}\u{989D}\u{5145}\u{503C}",
		'transaction_buyer_income' => "\u{4EA4}\u{6613}\u{4E70}\u{5165}",
		'agent_wallet_transfer_in' => "\u{4EE3}\u{7406}\u{6536}\u{76CA}",
	];

	return $fallbackMap[$changeType] ?? "\u{4F59}\u{989D}\u{6D41}\u{6C34}";
}
	/**
	 * @deprecated Legacy withdrawal endpoint (stub).
	 *
	 * The current withdrawal flow uses the preview/submit/detail APIs:
	 *   - GET  /api/finance/withdrawal-preview
	 *   - POST /api/finance/withdrawal-submit
	 *   - GET  /api/finance/withdrawal-detail
	 *
	 * This endpoint only validates amount > 0 and returns success without
	 * performing any real withdrawal logic. It is retained temporarily for
	 * backward compatibility with potential external callers.
	 * Do NOT use this in new code.
	 */
	protected function handleApiFinanceWithdrawal($unused = null)
	{
		$amount = (float) ($this->request->post('amount', 0));
		if ($amount <= 0) {
			return show(500, 'error', 'Invalid withdrawal amount');
		}

		return show(200, 'success', 'Withdrawal request submitted', [
			'amount' => $this->directMoney($amount),
		]);
	}

	protected function handleApiFinanceWithdrawalPreview()
	{
		$amount = (float) ($this->request->get('amount', 0));
		$usePointsDeduct = (int) $this->request->get('use_points_deduct', 0) === 1;
		$user = UserModel::where('id', $this->user_info['id'])->find();
		if (!$user) {
			return show(500, 'error', 'Account not found');
		}
		if ($amount <= 0) {
			return show(500, 'error', 'Invalid withdrawal amount');
		}
		if ($amount < (float) (getConfig('mini_withdrawal_amount') ?? 0)) {
			return show(500, 'error', 'Withdrawal amount is below the minimum');
		}
		if ($amount > (float) ($user['balance'] ?? 0)) {
			return show(500, 'error', 'Insufficient balance');
		}
		if (empty($user['trc20'])) {
			return show(500, 'error', 'TRC20 wallet address not configured');
		}

		$baseFee = (float) (getConfig('withdrawal_fee') ?? 0);
		$deductMeta = $this->directComputeWithdrawalPointsDeductMeta($user, $amount, $baseFee);
		$appliedDeductFee = ($usePointsDeduct && !empty($deductMeta['can_apply']))
			? (float) ($deductMeta['available_deduct_fee'] ?? 0)
			: 0.0;
		$fee = round(max(0, $baseFee - $appliedDeductFee), 2);
		return show(200, 'success', 'Withdrawal preview loaded', [
			'amount' => $this->directMoney($amount),
			'withdrawal_fee_base' => $this->directMoney($baseFee),
			'withdrawal_fee' => $this->directMoney($fee),
			'actual_amount' => $this->directMoney(max(0, $amount - $fee), 6),
			'wallet_address' => (string) ($user['trc20'] ?? ''),
			'verify_mode' => !empty($user['twofa_enabled']) ? 'twofa' : 'password',
			'twofa_enabled' => !empty($user['twofa_enabled']) ? 1 : 0,
			'points_balance' => (int) ($user['points_balance'] ?? 0),
			'points_deduct_visible' => (int) ($deductMeta['visible'] ?? 0),
			'points_deduct_enabled' => (int) ($deductMeta['can_apply'] ?? 0),
			'points_deduct_label' => '10积分抵扣0.1',
			'points_deduct_points_per_unit' => (int) ($deductMeta['points_per_unit'] ?? 10),
			'points_deduct_fee_per_unit' => $this->directMoney((float) ($deductMeta['fee_per_unit'] ?? 0), 1),
			'points_deduct_max_fee' => $this->directMoney((float) ($deductMeta['max_deduct_fee'] ?? 0)),
			'points_deduct_available_fee' => $this->directMoney((float) ($deductMeta['available_deduct_fee'] ?? 0)),
			'points_deduct_available_points' => (int) ($deductMeta['deduct_points'] ?? 0),
			'points_deduct_applied_fee' => $this->directMoney($appliedDeductFee),
			'points_deduct_applied_points' => ($usePointsDeduct && !empty($deductMeta['can_apply'])) ? (int) ($deductMeta['deduct_points'] ?? 0) : 0,
			'points_deduct_min_withdraw_amount' => $this->directMoney((float) ($deductMeta['min_amount'] ?? 50)),
			'user_tier' => (string) ($deductMeta['user_tier'] ?? 'normal'),
		]);
	}

	private function directFindRecentPendingWithdrawal(int $uid, float $amount, string $walletAddress, float $fee)
	{
		return Withdrawal::where('uid', $uid)
			->where('status', 0)
			->where('wallet_address', $walletAddress)
			->where('amount', $amount)
			->where('withdrawal_fee', $fee)
			->where('create_time', '>=', date('Y-m-d H:i:s', time() - 15))
			->order('id', 'desc')
			->find();
	}

	private function directBuildWithdrawalSubmitPayload($withdrawal, float $amount, float $fee, float $baseFee = 0.0): array
	{
		$withdrawalAmount = (float) ($withdrawal['amount'] ?? $amount);
		$withdrawalFee = (float) ($withdrawal['withdrawal_fee'] ?? $fee);
		$rawBaseFee = $baseFee > 0 ? $baseFee : (float) (getConfig('withdrawal_fee') ?? 0);
		$pointsDeductFee = round(max(0, $rawBaseFee - $withdrawalFee), 2);
		$pointsDeductPoints = (int) floor(($pointsDeductFee + 0.000001) / 0.1) * 10;
		$status = (int) ($withdrawal['status'] ?? 0);

		return [
			'id' => (int) ($withdrawal['id'] ?? 0),
			'order_number' => (string) ($withdrawal['order_number'] ?? $withdrawal['id']),
			'amount' => $this->directMoney($withdrawalAmount),
			'withdrawal_fee_base' => $this->directMoney($rawBaseFee),
			'withdrawal_fee' => $this->directMoney($withdrawalFee),
			'actual_amount' => $this->directMoney(max(0, $withdrawalAmount - $withdrawalFee), 6),
			'points_deduct_fee' => $this->directMoney($pointsDeductFee),
			'points_deduct_points' => $pointsDeductPoints,
			'status' => $status,
			'status_text' => $this->directWithdrawalStatusText($status),
		];
	}

	private function directResolveWithdrawalUserTier($user): string
	{
		$uid = (int) ($user['id'] ?? 0);
		if ($uid <= 0) {
			return 'normal';
		}

		$substation = Substation::where('uid', $uid)->find();
		if ($substation && (int) ($substation['status'] ?? 0) === 2) {
			return 'svip';
		}

		if ((int) ($user['agent_status'] ?? 0) === 1) {
			return 'vip';
		}

		return 'normal';
	}

	private function directComputeWithdrawalPointsDeductMeta($user, float $amount, float $baseFee): array
	{
		$pointsPerUnit = 10;
		$feePerUnit = 0.1;
		$minAmount = 0.0;
		$pointsBalance = max(0, (int) ($user['points_balance'] ?? 0));
		$userTier = $this->directResolveWithdrawalUserTier($user);
		$maxDeductFee = in_array($userTier, ['vip', 'svip'], true) ? 1.0 : 0.5;

		$visible = $pointsBalance >= $pointsPerUnit;
		$amountEligible = true;

		$maxByPoints = floor($pointsBalance / $pointsPerUnit) * $feePerUnit;
		$availableDeductFee = round(max(0, min($maxDeductFee, $baseFee, $maxByPoints)), 2);
		$deductUnits = (int) floor(($availableDeductFee + 0.000001) / $feePerUnit);
		$deductPoints = $deductUnits * $pointsPerUnit;
		$availableDeductFee = round($deductUnits * $feePerUnit, 2);

		$canApply = $visible && $baseFee > 0 && $availableDeductFee > 0;

		return [
			'points_per_unit' => $pointsPerUnit,
			'fee_per_unit' => $feePerUnit,
			'min_amount' => $minAmount,
			'points_balance' => $pointsBalance,
			'user_tier' => $userTier,
			'max_deduct_fee' => $maxDeductFee,
			'visible' => $visible ? 1 : 0,
			'amount_eligible' => $amountEligible ? 1 : 0,
			'can_apply' => $canApply ? 1 : 0,
			'available_deduct_fee' => $availableDeductFee,
			'deduct_points' => $deductPoints,
		];
	}

	private function directApplyWithdrawalPointsDeduction($lockedUser, int $points, string $reason): void
	{
		if ($points <= 0) {
			return;
		}

		$currentPoints = (int) ($lockedUser['points_balance'] ?? 0);
		if ($currentPoints < $points) {
			throw new Exception('积分不足，无法抵扣手续费');
		}

		$lockedUser->points_balance = $currentPoints - $points;
		$lockedUser->month_used = (int) ($lockedUser['month_used'] ?? 0) + $points;
		if ($lockedUser->save() === false) {
			throw new Exception('积分抵扣失败');
		}

		$record = new PointsRecord();
		$record->uid = (int) ($lockedUser['id'] ?? 0);
		$record->points = -1 * $points;
		$record->reason = $reason;
		$record->type = 'used';
		$record->create_time = date('Y-m-d H:i:s');
		if ($record->save() === false) {
			throw new Exception('积分抵扣记录失败');
		}
	}
	protected function handleApiFinanceWithdrawalSubmit()
	{
		$postInfo = $this->request->post();
		$userInfo = UserModel::where('id', $this->user_info['id'])->find();
		if (!$userInfo) {
			return show(500, 'error', 'Account not found');
		}

		// SEC-004: 提现提交限流 — 用户维度，60 秒内最多 3 次
		// 仅为外围防护，不替代 user 行锁、数据库事务和幂等流水
		$withdrawUid = (int)($userInfo['id'] ?? 0);
		if ($withdrawUid > 0 && !ActionRateLimiter::check('withdrawal:uid:' . $withdrawUid, 3, 60)) {
			return show(429, 'error', '提现操作过于频繁，请稍后再试', [], 429);
		}

		$amount = (float) ($postInfo['amount'] ?? 0);
		if ($amount <= 0) {
			return show(500, 'error', 'Invalid withdrawal amount');
		}
		if ($amount < (float) (getConfig('mini_withdrawal_amount') ?? 0)) {
			return show(500, 'error', 'Withdrawal amount is below the minimum');
		}
		if ($amount > (float) ($userInfo['balance'] ?? 0)) {
			return show(500, 'error', 'Insufficient balance');
		}
		if (empty($userInfo['trc20'])) {
			return show(500, 'error', 'TRC20 wallet address not configured');
		}

		// RC-A: 复用统一敏感操作验证方法（2FA window=2，与项目其他敏感操作一致）
		// 有2FA则校验2FA动态码，无2FA则校验登录密码；包含2FA尝试限流与具体错误信息
		$credentialError = $this->verifySensitiveActionCredential($userInfo, $postInfo);
		if ($credentialError !== null) {
			return $credentialError;
		}

		$baseFee = (float) (getConfig('withdrawal_fee') ?? 0);
		$usePointsDeduct = !empty($postInfo['use_points_deduct']);

		try {
			$result = Db::transaction(function () use ($amount, $baseFee, $usePointsDeduct) {
				$lockedUser = $this->directLockUser((int) ($this->user_info['id'] ?? 0));
				if (!$lockedUser) {
					throw new Exception('User not found');
				}
				if ($amount > (float) ($lockedUser['balance'] ?? 0)) {
					throw new Exception('Insufficient balance');
				}

				$fee = round($baseFee, 2);
				$pointsDeductFee = 0.0;
				$pointsDeductPoints = 0;
				if ($usePointsDeduct) {
					$deductMeta = $this->directComputeWithdrawalPointsDeductMeta($lockedUser, $amount, $baseFee);
					if (empty($deductMeta['visible'])) {
						throw new Exception('积分不足，无法抵扣手续费');
					}
					if (empty($deductMeta['can_apply'])) {
						throw new Exception('当前不可使用积分抵扣');
					}

					$pointsDeductFee = round((float) ($deductMeta['available_deduct_fee'] ?? 0), 2);
					$pointsDeductPoints = (int) ($deductMeta['deduct_points'] ?? 0);
					if ($pointsDeductFee <= 0 || $pointsDeductPoints <= 0) {
						throw new Exception('当前不可使用积分抵扣');
					}
					$fee = round(max(0, $baseFee - $pointsDeductFee), 2);
				}

				$walletAddress = (string) ($lockedUser['trc20'] ?? '');
				$duplicate = $this->directFindRecentPendingWithdrawal((int) $lockedUser['id'], $amount, $walletAddress, $fee);
				if ($duplicate) {
					return [
						'duplicate' => true,
						'withdrawal' => $duplicate,
					];
				}

				$orderNumber = date('Ymd') . randomkeys(6, 'number');
				$withdrawal = Withdrawal::create([
					'uid' => (int) ($lockedUser['id'] ?? 0),
					'amount' => $amount,
					'wallet_address' => $walletAddress,
					'withdrawal_fee' => $fee,
					'order_number' => $orderNumber,
					'status' => 0,
					'create_time' => date('Y-m-d H:i:s'),
				]);

				if ($pointsDeductPoints > 0) {
					$this->directApplyWithdrawalPointsDeduction(
						$lockedUser,
						$pointsDeductPoints,
						'提现手续费抵扣 #' . $orderNumber
					);
				}

				$balanceBefore = round((float) ($lockedUser['balance'] ?? 0), 2);
				$ledgerResult = (new UserFundLedgerService())->transferLockedUserWallet(
					$lockedUser,
					UserFundLedgerService::WALLET_BALANCE,
					UserFundLedgerService::WALLET_FROZEN,
					$amount,
					[
						'biz_type' => 'withdrawal',
						'biz_id' => (int) ($withdrawal['id'] ?? 0),
						'biz_no' => $orderNumber,
						'order_number' => $orderNumber,
						'out_change_type' => 'withdraw_apply',
						'in_change_type' => 'withdraw_apply',
						'operator_type' => 'user',
						'operator_id' => (int) ($lockedUser['id'] ?? 0),
						'status' => 'done',
						'request_no' => 'withdraw_apply:' . $orderNumber,
						'remark' => 'withdraw apply freeze',
						'idempotent' => true,
						'extra' => [
							'source' => 'handleApiFinanceWithdrawalSubmit',
						],
					]
				);
				$walletSnapshot = (array) ($ledgerResult['wallet_snapshot'] ?? []);
				$balanceAfter = array_key_exists('balance', $walletSnapshot)
					? round((float) ($walletSnapshot['balance'] ?? 0), 2)
					: round(max(0, $balanceBefore - $amount), 2);

				$this->directWriteBalanceLog([
					'uid' => (int) ($lockedUser['id'] ?? 0),
					'scene' => 'withdraw_apply',
					'amount' => $amount,
					'balance_before' => $balanceBefore,
					'balance_after' => $balanceAfter,
					'biz_id' => (int) ($withdrawal['id'] ?? 0),
					'order_number' => $orderNumber,
					'remark' => 'withdraw apply freeze',
					'operator_id' => (int) ($lockedUser['id'] ?? 0),
				]);

				return [
					'duplicate' => false,
					'withdrawal' => $withdrawal,
					'base_fee' => $baseFee,
				];
			});
		} catch (\Throwable $e) {
			$this->logApiException('finance_withdrawal_submit', $e, [
				'amount' => $amount,
			]);
			return show(500, 'error', 'System error, please try again later');
		}

		$message = !empty($result['duplicate']) ? 'Success' : 'Success';
		return show(200, 'success', $message, $this->directBuildWithdrawalSubmitPayload(
			$result['withdrawal'],
			$amount,
			(float)($result['withdrawal']['withdrawal_fee'] ?? $baseFee),
			(float)($result['base_fee'] ?? $baseFee)
		));
	}

	protected function handleApiFinanceWithdrawalDetail()
	{
		$id = (int) $this->request->get('id', 0);
		if ($id <= 0) {
			return show(500, 'error', 'Invalid withdrawal record ID');
		}

		$item = Withdrawal::where('uid', $this->user_info['id'])->find($id);
		if (!$item) {
			return show(404, 'error', 'Withdrawal record not found');
		}

		$status = (int) ($item['status'] ?? 0);
		$baseFee = (float) (getConfig('withdrawal_fee') ?? 0);
		$fee = (float) ($item['withdrawal_fee'] ?? getConfig('withdrawal_fee') ?? 0);
		$amount = (float) ($item['amount'] ?? 0);
		$pointsDeductFee = round(max(0, $baseFee - $fee), 2);
		$pointsDeductPoints = (int) floor(($pointsDeductFee + 0.000001) / 0.1) * 10;

		return show(200, 'success', 'Success', [
			'id' => (int) ($item['id'] ?? 0),
			'order_number' => (string) ($item['order_number'] ?? $item['id']),
			'status' => $status,
			'status_text' => $this->directFinanceWithdrawalStatusText($status),
			'amount' => $this->directMoney($amount),
			'withdrawal_fee_base' => $this->directMoney($baseFee),
			'withdrawal_fee' => $this->directMoney($fee),
			'actual_amount' => $this->directMoney(max(0, $amount - $fee), 6),
			'points_deduct_fee' => $this->directMoney($pointsDeductFee),
			'points_deduct_points' => $pointsDeductPoints,
			'wallet_address' => (string) ($item['wallet_address'] ?? ''),
			'create_time' => (string) ($item['create_time'] ?? ''),
		]);
	}

	private function directFinanceRechargeStatusText(int $status, $payType = '', $gateway = ''): string
	{
		$payType = (string) ($payType ?? '');
		$gateway = (string) ($gateway ?? '');
		if ($status === 0 && ($payType === '2' || in_array($gateway, ['epay', 'bepusdt'], true))) {
			return "\u{5F85}\u{652F}\u{4ED8}";
		}

		$map = [
			0 => "\u{5F85}\u{6C47}\u{6B3E}",
			1 => "\u{5DF2}\u{63D0}\u{4EA4}",
			2 => "\u{5DF2}\u{53D6}\u{6D88}",
			3 => "\u{5DF2}\u{5B8C}\u{6210}",
		];

		return $map[$status] ?? "\u{72B6}\u{6001}\u{672A}\u{77E5}";
	}

	private function directFinanceWithdrawalStatusText(int $status): string
	{
		$map = [
			0 => "\u{63D0}\u{73B0}\u{5904}\u{7406}\u{4E2D}",
			1 => "\u{63D0}\u{73B0}\u{6210}\u{529F}",
			2 => "\u{63D0}\u{73B0}\u{5931}\u{8D25}",
			3 => "\u{5DF2}\u{53D6}\u{6D88}",
		];

		return $map[$status] ?? "\u{72B6}\u{6001}\u{672A}\u{77E5}";
	}

	private function directFinancePayTypeText($payType, $epayType = ''): string
	{
		$payType = (string) ($payType ?? '');
		$epayType = (string) ($epayType ?? '');
		if ($payType === '1') {
			return 'TRC20';
		}
		if ($payType === '2') {
			if ($epayType === '1') {
				return "\u{652F}\u{4ED8}\u{5B9D}";
			}
			if ($epayType === '2') {
				return "\u{5FAE}\u{4FE1}\u{652F}\u{4ED8}";
			}
		}

		return "\u{672A}\u{77E5}\u{65B9}\u{5F0F}";
	}

	private function directFinanceCreateEpayPayment(string $orderNumber, float $amount, string $epayType): array
	{
		$epayUrl = rtrim((string) getConfig('epay_url'), '/');
		$epayId = trim((string) getConfig('epay_id'));
		$epayKey = trim((string) getConfig('epay_key'));
		$rate = (float) (getConfig('rate') ?? 0);

		if ($epayUrl === '' || $epayId === '' || $epayKey === '' || $rate <= 0) {
			throw new Exception('epay config missing');
		}

		$type = $epayType === '2' ? 'wxpay' : 'alipay';
		$gatewayAmount = round($amount * $rate, 2);
		if ($gatewayAmount <= 0) {
			throw new Exception('invalid gateway amount');
		}

		$params = [
			'pid' => $epayId,
			'type' => $type,
			'out_trade_no' => $orderNumber,
			'notify_url' => $this->directEpayNotifyUrl(),
			'return_url' => $this->directEpayReturnUrl($orderNumber),
			'name' => "\u{4F59}\u{989D}\u{5145}\u{503C}",
			'money' => number_format($gatewayAmount, 2, '.', ''),
			'clientip' => (string) ($this->request->ip() ?: '127.0.0.1'),
			'device' => 'jump',
			'sign_type' => 'MD5',
		];
		ksort($params);

		$pairs = [];
		foreach ($params as $key => $value) {
			if ($key === 'sign' || $key === 'sign_type' || $value === '' || $value === null) {
				continue;
			}
			$pairs[] = $key . '=' . $value;
		}
		$params['sign'] = md5(implode('&', $pairs) . $epayKey);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $epayUrl . '/mapi.php');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		$response = curl_exec($ch);
		$error = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			throw new Exception($error !== '' ? $error : 'epay request failed');
		}

		$payload = json_decode((string) $response, true);
		if (!is_array($payload)) {
			Log::warning('epay create invalid json', [
				'order_number' => $orderNumber,
				'response' => mb_substr((string) $response, 0, 500),
			]);
			throw new Exception('epay response invalid');
		}

		if ((int) ($payload['code'] ?? 0) !== 1) {
			Log::warning('epay create failed', [
				'order_number' => $orderNumber,
				'code' => $payload['code'] ?? null,
				'msg' => (string) ($payload['msg'] ?? ''),
			]);
			throw new Exception((string) ($payload['msg'] ?? 'epay create failed'));
		}

		foreach (['payurl', 'urlscheme', 'qrcode'] as $key) {
			$value = trim((string) ($payload[$key] ?? ''));
			if ($value !== '') {
				$payload['pay_url'] = $value;
				return $payload;
			}
		}

		throw new Exception('epay pay url missing');
	}

	private function directFinanceBuildEpayPayUrl(string $orderNumber, float $amount, string $epayType): string
	{
		$payload = $this->directFinanceCreateEpayPayment($orderNumber, $amount, $epayType);

		return (string) ($payload['pay_url'] ?? '');
	}

	protected function handleEpayNotifyUrl()
	{
		$params = array_merge((array) $this->request->get(), (array) $this->request->post());
		$sign = strtolower(trim((string) ($params['sign'] ?? '')));
		$epayKey = trim((string) getConfig('epay_key'));
		$orderNumber = trim((string) ($params['out_trade_no'] ?? ''));
		$tradeStatusRaw = (string) ($params['trade_status'] ?? $params['status'] ?? '');

		Log::info('epay notify received', [
			'order_number' => $orderNumber,
			'trade_status' => $tradeStatusRaw,
			'has_sign' => $sign !== '',
			'param_keys' => implode(',', array_keys($params)),
		]);

		if ($sign === '' || $epayKey === '') {
			Log::warning('epay notify missing sign');
			return response('fail', 500);
		}

		unset($params['sign']);
		ksort($params, SORT_STRING);
		$pairs = [];
		foreach ($params as $key => $value) {
			if ($key === 'sign_type' || $value === '' || $value === null) {
				continue;
			}
			$pairs[] = $key . '=' . $value;
		}
		$expectedSign = strtolower(md5(implode('&', $pairs) . $epayKey));
		if (!hash_equals($expectedSign, $sign)) {
			Log::warning('epay notify sign mismatch', [
				'order_number' => (string) ($params['out_trade_no'] ?? ''),
			]);
			return response('fail', 500);
		}

		if ($orderNumber === '') {
			Log::warning('epay notify missing out_trade_no');
			return response('success', 200);
		}

		$tradeStatus = strtoupper(trim((string) ($params['trade_status'] ?? $params['status'] ?? 'TRADE_SUCCESS')));
		if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED', 'SUCCESS', 'PAID', 'FINISHED', 'PAY_SUCCESS', '1'], true)) {
			Log::warning('epay notify ignored non-success status', [
				'order_number' => $orderNumber,
				'trade_status' => $tradeStatus,
			]);
			return response('success', 200);
		}

		try {
			Db::transaction(function () use ($orderNumber, $params) {
				$recharge = Recharge::where('order_number', $orderNumber)->lock(true)->find();
				if (!$recharge) {
					Log::warning('epay notify recharge not found', [
						'order_number' => $orderNumber,
					]);
					throw new Exception('Recharge not found');
				}
				if ((int) ($recharge['status'] ?? 0) === 3) {
					return;
				}
				$payType = (string) ($recharge['pay_type'] ?? '');
				$gateway = (string) ($recharge['gateway'] ?? '');
				if ($payType !== '2' && $gateway !== 'epay') {
					Log::warning('epay notify ignored non-epay recharge', [
						'order_number' => $orderNumber,
						'recharge_id' => (int) ($recharge['id'] ?? 0),
						'pay_type' => $payType,
						'gateway' => $gateway,
					]);
					return;
				}

				$user = $this->directLockUser((int) ($recharge['uid'] ?? 0));
				if (!$user) {
					throw new Exception('User not found');
				}

				$amount = round((float) ($recharge['amount'] ?? 0), 2);
				if ($amount <= 0) {
					throw new Exception('Invalid recharge amount');
				}

				$callbackMoney = round((float) (
					$params['money']
					?? $params['actual_amount']
					?? $params['total_amount']
					?? $params['total_fee']
					?? $params['price']
					?? $recharge['gateway_actual_amount']
					?? 0
				), 2);
				$gatewayAmount = round((float) ($recharge['gateway_actual_amount'] ?? 0), 2);
				if ($gatewayAmount <= 0) {
					$gatewayAmount = round($amount * (float) (getConfig('rate') ?? 0), 2);
				}
				$amountTolerance = 0.01;
				if ($gatewayAmount <= 0 || ($callbackMoney + $amountTolerance) < $gatewayAmount) {
					Log::error('epay notify amount mismatch', [
						'order_number' => $orderNumber,
						'recharge_id' => (int) ($recharge['id'] ?? 0),
						'order_amount' => $amount,
						'gateway_amount' => $gatewayAmount,
						'callback_money' => $callbackMoney,
						'status' => (int) ($recharge['status'] ?? 0),
					]);
					throw new Exception('Invalid callback amount');
				}
				$balanceBefore = round((float) ($user['balance'] ?? 0), 2);

				$recharge->gateway = 'epay';
				$recharge->status = 3;
				if (empty($recharge['submit_time'])) {
					$recharge->submit_time = date('Y-m-d H:i:s');
				}
				$recharge->paid_time = date('Y-m-d H:i:s');
				$recharge->complete_time = date('Y-m-d H:i:s');
				$recharge->gateway_trade_id = (string) ($params['trade_no'] ?? '');
				$recharge->gateway_status = (string) ($params['trade_status'] ?? $params['status'] ?? '');
				$recharge->gateway_actual_amount = $callbackMoney > 0 ? $callbackMoney : (float) ($recharge['gateway_actual_amount'] ?? 0);
				$recharge->gateway_notify_payload = json_encode($params, JSON_UNESCAPED_UNICODE);
				$recharge->save();

				$ledgerResult = (new UserFundLedgerService())->changeLockedUserWallet(
					$user,
					UserFundLedgerService::WALLET_BALANCE,
					$amount,
					[
						'biz_type' => 'recharge',
						'biz_id' => (int) ($recharge['id'] ?? 0),
						'biz_no' => (string) ($recharge['order_number'] ?? ''),
						'order_number' => (string) ($recharge['order_number'] ?? ''),
						'change_type' => 'recharge_paid',
						'operator_type' => 'system',
						'operator_id' => 0,
						'status' => 'done',
						'request_no' => 'recharge_paid:' . (string) ($recharge['order_number'] ?? ''),
						'remark' => 'epay recharge paid',
						'idempotent' => true,
						'extra' => [
							'source' => 'handleEpayNotifyUrl',
							'gateway' => 'epay',
						],
					]
				);

				$walletSnapshot = (array) ($ledgerResult['wallet_snapshot'] ?? []);
				$balanceAfter = array_key_exists('balance', $walletSnapshot)
					? round((float) ($walletSnapshot['balance'] ?? 0), 2)
					: round($balanceBefore + $amount, 2);

				$this->directWriteBalanceLog([
					'uid' => (int) ($user['id'] ?? 0),
					'scene' => 'recharge_paid',
					'amount' => $amount,
					'balance_before' => $balanceBefore,
					'balance_after' => $balanceAfter,
					'biz_id' => (int) ($recharge['id'] ?? 0),
					'order_number' => (string) ($recharge['order_number'] ?? ''),
					'remark' => 'epay recharge paid',
					'operator_id' => 0,
				]);
			});
		} catch (\Throwable $e) {
			$this->logApiException('epay_notify', $e, [
				'order_number' => $orderNumber,
			]);
			return response('fail', 500);
		}

		Log::info('epay notify processed', [
			'order_number' => $orderNumber,
			'trade_status' => $tradeStatus,
		]);

		return response('success', 200);
	}

	protected function handleApiFinanceOrders()
	{
		$tab = (string) $this->request->get('tab', 'recharge');
		$page = max(1, (int) $this->request->get('page', 1));
		$pageSize = max(1, min(20, (int) $this->request->get('pageSize', 5)));
		$uid = (int) ($this->user_info['id'] ?? 0);

		if ($tab === 'withdraw') {
			$query = Withdrawal::where('uid', $uid)->order('id', 'desc');
			$total = (int) $query->count();
			$list = $query->page($page, $pageSize)->select();
			$records = [];

			foreach ($list as $item) {
				$status = (int) ($item['status'] ?? 0);
				$fee = (float) ($item['withdrawal_fee'] ?? getConfig('withdrawal_fee') ?? 0);
				$amount = (float) ($item['amount'] ?? 0);
				$records[] = [
					'tab' => 'withdraw',
					'id' => (int) ($item['id'] ?? 0),
					'order_number' => (string) ($item['order_number'] ?? $item['id']),
					'title' => $this->directFinanceWithdrawalStatusText($status),
					'amount' => $this->directMoney($amount),
					'text' => "\u{5B9E}\u{9645}\u{5230}\u{8D26} " . $this->directMoney(max(0, $amount - $fee), 6),
					'unit' => 'USDT',
					'date' => (string) ($item['create_time'] ?? ''),
					'status' => $status,
					'status_text' => $this->directFinanceWithdrawalStatusText($status),
					'withdrawal_fee' => $this->directMoney($fee),
					'actual_amount' => $this->directMoney(max(0, $amount - $fee), 6),
				];
			}

			return show(200, 'success', 'Success', [
				'records' => $records,
				'page' => $page,
				'pageSize' => $pageSize,
				'total' => $total,
				'totalPages' => max(1, (int) ceil($total / $pageSize)),
			]);
		}

		$query = Recharge::where('uid', $uid)->order('id', 'desc');
		$total = (int) $query->count();
		$list = $query->page($page, $pageSize)->select();
		$records = [];

		foreach ($list as $item) {
			$status = (int) ($item['status'] ?? 0);
			$payType = (string) ($item['pay_type'] ?? '1');
			$epayType = (string) ($item['epay_type'] ?? '');
			$records[] = [
				'tab' => 'recharge',
				'id' => (int) ($item['id'] ?? 0),
				'order_number' => (string) ($item['order_number'] ?? ''),
				'title' => $this->directFinanceRechargeStatusText($status, $payType, (string) ($item['gateway'] ?? '')),
				'amount' => $this->directMoney($item['amount'] ?? 0),
				'text' => $this->directFinancePayTypeText($payType, $epayType),
				'unit' => 'USDT',
				'date' => (string) ($item['create_time'] ?? ''),
				'status' => $status,
				'status_text' => $this->directFinanceRechargeStatusText($status, $payType, (string) ($item['gateway'] ?? '')),
				'pay_type' => $payType,
				'epay_type' => $epayType,
			];
		}

		return show(200, 'success', 'Success', [
			'records' => $records,
			'page' => $page,
			'pageSize' => $pageSize,
			'total' => $total,
			'totalPages' => max(1, (int) ceil($total / $pageSize)),
		]);
	}

	protected function handleApiFinanceRecharge()
	{
		$postInfo = $this->request->post();
		$userInfo = UserModel::where('id', $this->user_info['id'])->find();
		if (!$userInfo) {
			return show(500, 'error', 'Request error');
		}

		$amount = (float) ($postInfo['amount'] ?? 0);
		if ($amount <= 0 || $amount < (float) (getConfig('mini_recharge_amount') ?? 0)) {
			return show(500, 'error', 'Request error');
		}

		$payType = (string) ($postInfo['pay_type'] ?? '1');
		$epayType = (string) ($postInfo['epay_type'] ?? '1');

		if ($payType === '1') {
			$orderNumber = date('Ymd') . randomkeys(8);
			$bepusdt = new BepusdtService();
			if ($bepusdt->isEnabled()) {
				try {
					$recharge = Recharge::create([
						'uid' => (int) ($this->user_info['id'] ?? 0),
						'amount' => $amount,
						'pay_type' => 1,
						'wallet_address' => (string) ($userInfo['trc20'] ?? ''),
						'order_number' => $orderNumber,
						'status' => 0,
						'gateway' => 'bepusdt',
						'create_time' => date('Y-m-d H:i:s'),
					]);

					$gateway = $bepusdt->createTransaction([
						'order_id' => $orderNumber,
						'title' => 'USDT recharge',
						'amount' => $amount,
						'notify_url' => $this->directBepusdtNotifyUrl(),
						'redirect_url' => $this->directBepusdtRedirectUrl($orderNumber),
						'trade_type' => (string)config('bepusdt.trade_type'),
						'fiat' => (string)config('bepusdt.fiat'),
					]);

					$recharge->gateway_trade_id = (string)($gateway['trade_id'] ?? ($gateway['id'] ?? ''));
					$recharge->gateway_token = (string)($gateway['token'] ?? '');
					$recharge->gateway_actual_amount = (float)($gateway['actual_amount'] ?? 0);
					$recharge->gateway_status = (string)($gateway['status'] ?? '');
					$recharge->gateway_txid = (string)($gateway['block_transaction_id'] ?? '');
					$recharge->gateway_raw = json_encode($gateway, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					$recharge->save();

					return show(200, 'success', 'Success', [
						'order_number' => (string) ($recharge['order_number'] ?? ''),
						'pay_type' => '1',
						'gateway' => 'bepusdt',
						'mode' => 'bepusdt',
						'pay_url' => $this->directGatewayPayUrl($recharge),
						'status' => 0,
						'status_text' => $this->directFinanceRechargeStatusText(0, '1', 'bepusdt'),
					]);
				} catch (\Throwable $e) {
					if (isset($recharge) && $recharge) {
						$recharge->status = 2;
						$recharge->cancel_time = date('Y-m-d H:i:s');
						$recharge->gateway_status = 'create_failed';
						$recharge->save();
					}
					$this->logApiException('finance_recharge_bepusdt_create', $e, [
						'amount' => $amount,
						'pay_type' => $payType,
					]);
					return show(500, 'error', 'Request error');
				}
			}

			$recharge = Recharge::create([
				'uid' => (int) ($this->user_info['id'] ?? 0),
				'amount' => $amount,
				'pay_type' => 1,
				'wallet_address' => (string) ($userInfo['trc20'] ?? ''),
				'order_number' => $orderNumber,
				'status' => 0,
				'gateway' => 'manual',
				'create_time' => date('Y-m-d H:i:s'),
			]);

			return show(200, 'success', 'Success', [
				'order_number' => (string) ($recharge['order_number'] ?? ''),
				'pay_type' => '1',
				'status' => 0,
				'status_text' => $this->directFinanceRechargeStatusText(0),
				'mode' => 'manual',
			]);
		}

		if ($payType !== '2') {
			return show(500, 'error', 'Request error');
		}
		if ($epayType === '1' && (string)(getConfig('epay_alipay_enabled') ?? '1') === '0') {
			return show(500, 'error', 'Alipay recharge disabled');
		}
		if ($epayType === '2' && (string)(getConfig('epay_wechat_enabled') ?? '1') === '0') {
			return show(500, 'error', 'Wechat recharge disabled');
		}

		try {
			$orderNumber = date('Ymd') . randomkeys(8);
			$gatewayAmount = round($amount * (float) (getConfig('rate') ?? 0), 2);

			$recharge = Recharge::create([
				'uid' => (int) ($this->user_info['id'] ?? 0),
				'amount' => $amount,
				'pay_type' => 2,
				'epay_type' => $epayType,
				'wallet_address' => (string) ($userInfo['trc20'] ?? ''),
				'order_number' => $orderNumber,
				'status' => 0,
				'gateway' => 'epay',
				'gateway_actual_amount' => $gatewayAmount,
				'create_time' => date('Y-m-d H:i:s'),
			]);
			$gateway = $this->directFinanceCreateEpayPayment($orderNumber, $amount, $epayType);
			$payUrl = (string) ($gateway['pay_url'] ?? '');
			$recharge->gateway_trade_id = (string) ($gateway['trade_no'] ?? '');
			$recharge->gateway_status = (string) ($gateway['code'] ?? '');
			$recharge->gateway_raw = json_encode($gateway, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$recharge->save();
		} catch (\Throwable $e) {
			if (isset($recharge) && $recharge) {
				$recharge->status = 2;
				$recharge->cancel_time = date('Y-m-d H:i:s');
				$recharge->gateway_status = 'create_failed';
				$recharge->save();
			}
			$this->logApiException('finance_recharge_epay_create', $e, [
				'amount' => $amount,
				'pay_type' => $payType,
				'epay_type' => $epayType,
			]);
			return show(500, 'error', 'Request error');
		}

		return show(200, 'success', 'Success', [
			'order_number' => (string) ($recharge['order_number'] ?? ''),
			'pay_type' => '2',
			'epay_type' => $epayType,
			'pay_url' => $payUrl,
			'status' => 0,
			'status_text' => $this->directFinanceRechargeStatusText(0, '2', 'epay'),
		]);
	}

	protected function handleApiFinanceRechargeDetail()
	{
		$orderNumber = trim((string) $this->request->get('order_number', ''));
		if ($orderNumber === '') {
			return show(500, 'error', 'Request error');
		}

		$recharge = Recharge::where('uid', $this->user_info['id'])
			->where('order_number', $orderNumber)
			->find();
		if (!$recharge) {
			return show(404, 'error', 'Request error');
		}

		$status = (int) ($recharge['status'] ?? 0);
		$payType = (string) ($recharge['pay_type'] ?? '1');
		$epayType = (string) ($recharge['epay_type'] ?? '');
		$paymentAddress = (string) (getConfig('payment_address') ?? '');
		$storedProofPath = trim((string) ($recharge['image'] ?? ''));
		$proofViewUrl = $this->buildRechargeProofViewUrl((string) ($recharge['order_number'] ?? ''), $storedProofPath);
		$proofValue = $proofViewUrl !== '' ? $proofViewUrl : $storedProofPath;

		return show(200, 'success', 'Success', [
			'id' => (int) ($recharge['id'] ?? 0),
			'order_number' => (string) ($recharge['order_number'] ?? ''),
			'status' => $status,
			'status_text' => $this->directFinanceRechargeStatusText($status, $payType, (string) ($recharge['gateway'] ?? '')),
			'create_time' => (string) ($recharge['create_time'] ?? ''),
			'submit_time' => (string) ($recharge['submit_time'] ?? ''),
			'cancel_time' => (string) ($recharge['cancel_time'] ?? ''),
			'paid_time' => (string) ($recharge['paid_time'] ?? ''),
			'amount' => $this->directMoney($recharge['amount'] ?? 0),
			'pay_type' => $payType,
			'epay_type' => $epayType,
			'pay_type_text' => $this->directFinancePayTypeText($payType, $epayType),
			'payment_address' => $paymentAddress,
			'payment_qr_url' => $this->directQrUrl($paymentAddress),
			'wallet_address' => (string) ($recharge['wallet_address'] ?? ''),
			'image' => $proofValue,
			'proof_view_url' => $proofValue,
			'gateway' => (string) ($recharge['gateway'] ?? ''),
			'gateway_trade_id' => (string) ($recharge['gateway_trade_id'] ?? ''),
			'gateway_status' => (string) ($recharge['gateway_status'] ?? ''),
			'gateway_txid' => (string) ($recharge['gateway_txid'] ?? ''),
			'gateway_actual_amount' => $this->directMoney($recharge['gateway_actual_amount'] ?? 0, 8),
			'pay_url' => $this->directGatewayPayUrl($recharge),
		]);
	}

	protected function handleApiFinanceRechargeSubmit()
	{
		$postInfo = $this->request->post();
		$orderNumber = trim((string) ($postInfo['order_number'] ?? ''));
		$action = trim((string) ($postInfo['action'] ?? 'submit'));
		if ($orderNumber === '') {
			return show(500, 'error', 'Request error');
		}

		$recharge = Recharge::where('uid', $this->user_info['id'])
			->where('order_number', $orderNumber)
			->find();
		if (!$recharge) {
			return show(404, 'error', 'Request error');
		}

		if ($action === 'image') {
			$storedPath = '';
			if ($this->requestHasProofUploadInput(['image', 'file'])) {
				$source = $this->extractProofUploadSource(['image', 'file']);
				if ($source === null) {
					return show(500, 'error', 'Request error');
				}
				$storedPath = $this->persistPrivateProofUpload(
					$source,
					'recharge',
					$orderNumber,
					(string) ($recharge['image'] ?? '')
				);
			} else {
				$storedPath = trim((string) ($postInfo['image'] ?? ''));
				if ($storedPath === '') {
					return show(500, 'error', 'Request error');
				}
			}

			$recharge->image = $storedPath;
			$recharge->save();
			$proofViewUrl = $this->buildRechargeProofViewUrl((string) ($recharge['order_number'] ?? ''), (string) ($recharge['image'] ?? ''));
			$proofValue = $proofViewUrl !== '' ? $proofViewUrl : (string) ($recharge['image'] ?? '');

			return show(200, 'success', 'Success', [
				'order_number' => (string) ($recharge['order_number'] ?? ''),
				'image' => $proofValue,
				'proof_view_url' => $proofValue,
			]);
		}

		if ((int) ($recharge['status'] ?? 0) !== 0) {
			return show(500, 'error', 'Request error');
		}

		if ($action === 'cancel') {
			$recharge->status = 2;
			$recharge->cancel_time = date('Y-m-d H:i:s');
			$recharge->save();

			return show(200, 'success', 'Success', [
				'order_number' => (string) ($recharge['order_number'] ?? ''),
				'status' => 2,
				'status_text' => $this->directFinanceRechargeStatusText(2),
			]);
		}

		if ((string) ($recharge['pay_type'] ?? '') !== '1') {
			return show(500, 'error', 'Request error');
		}

		if ($this->requestHasProofUploadInput(['image', 'file'])) {
			$source = $this->extractProofUploadSource(['image', 'file']);
			if ($source !== null) {
				$recharge->image = $this->persistPrivateProofUpload(
					$source,
					'recharge',
					$orderNumber,
					(string) ($recharge['image'] ?? '')
				);
			}
		} elseif (!empty($postInfo['image'])) {
			$recharge->image = trim((string) $postInfo['image']);
		}

		$recharge->status = 1;
		$recharge->submit_time = date('Y-m-d H:i:s');
		$recharge->save();

		return show(200, 'success', 'Success', [
			'order_number' => (string) ($recharge['order_number'] ?? ''),
			'status' => 1,
			'status_text' => $this->directFinanceRechargeStatusText(1),
			'proof_view_url' => $this->buildRechargeProofViewUrl((string) ($recharge['order_number'] ?? ''), (string) ($recharge['image'] ?? '')),
		]);
	}
}
