<?php
namespace app\service;

use app\model\User as UserModel;
use think\facade\Log;

class UserService
{
    /**
     * 获取用户余额信息
     * @param int $userId 用户ID
     * @return array|null 余额信息数组
     */
    public function getBalance($userId)
    {
        try {
            // 根据实际业务逻辑查询用户余额
            $user = UserModel::where('id', $userId)->find();
            
            if (!$user) {
                Log::error('用户不存在', ['user_id' => $userId]);
                return null;
            }
            
            // 假设用户表中有这些字段，根据实际表结构修改
            return [
                'available' => $user->balance ?? 0,        // 可用余额
                'frozen_amount' => $user->frozen_amount ?? 0,    // 冻结余额
                'points' => $user->points_balance ?? 0             // 积分
            ];
        } catch (\Exception $e) {
            Log::error('获取用户余额失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * 根据用户ID获取用户信息
     * @param int $userId 用户ID
     * @return array|null 用户信息
     */
    public function getUserInfo($userId)
    {
        try {
            $user = UserModel::where('id', $userId)->find();
            
            if (!$user) {
                Log::error('用户不存在', ['user_id' => $userId]);
                return null;
            }
            
            return $user->toArray();
        } catch (\Exception $e) {
            Log::error('获取用户信息失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
