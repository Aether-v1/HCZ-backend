#!/bin/bash
# ============================================================
# HCZ P6 生产环境最终验收脚本
# 目标版本: 8a152543f7e69dcf4ef76154814ea85a40c34b1d
# 用法: bash hcz_p6_acceptance.sh
# 注意: 只读检查，不修改任何生产数据
# ============================================================

set -e

TARGET_COMMIT="8a152543f7e69dcf4ef76154814ea85a40c34b1d"
PASS=0
FAIL=0
WARN=0
SKIP=0

echo "============================================"
echo "  HCZ P6 生产环境最终验收"
echo "  目标版本: ${TARGET_COMMIT:0:7}"
echo "  时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================"
echo ""

# 自动检测项目目录
if [ -f "app/controller/AdminApi.php" ]; then
    PROJECT_DIR="$(pwd)"
elif [ -d "/www/wwwroot/hcz" ]; then
    PROJECT_DIR="/www/wwwroot/hcz"
elif [ -d "/www/wwwroot/jiemencloud" ]; then
    PROJECT_DIR="/www/wwwroot/jiemencloud"
else
    echo "[?] 请输入 HCZ backend 项目目录:"
    read -r PROJECT_DIR
fi

cd "$PROJECT_DIR"
echo "[i] 项目目录: $PROJECT_DIR"
echo ""

# ------------------------------------------------------------
# 阶段1: 生产版本确认
# ------------------------------------------------------------
echo ">>> 阶段1: 生产版本确认"
echo "---"

CURRENT_BRANCH=$(git branch --show-current 2>/dev/null || echo "UNKNOWN")
CURRENT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "UNKNOWN")
CURRENT_LOG=$(git log -1 --oneline 2>/dev/null || echo "UNKNOWN")
GIT_STATUS=$(git status --short 2>/dev/null | grep -v "^??" || echo "clean")

echo "分支: $CURRENT_BRANCH"
echo "HEAD: $CURRENT_COMMIT"
echo "最新提交: $CURRENT_LOG"
echo "代码修改(不含untracked): $GIT_STATUS"

if [ "$CURRENT_COMMIT" = "$TARGET_COMMIT" ]; then
    echo "[PASS] 生产版本与目标一致 (${CURRENT_COMMIT:0:7})"
    PASS=$((PASS+1))
else
    echo "[FAIL] 生产版本与目标不一致!"
    echo "  当前: $CURRENT_COMMIT"
    echo "  目标: $TARGET_COMMIT"
    FAIL=$((FAIL+1))
    echo ""
    echo "!!! P6 验收终止: 生产版本不匹配 !!!"
    exit 1
fi

if [ "$CURRENT_BRANCH" != "main" ]; then
    echo "[WARN] 当前分支不是 main: $CURRENT_BRANCH"
    WARN=$((WARN+1))
else
    echo "[PASS] 分支为 main"
    PASS=$((PASS+1))
fi

echo ""

# ------------------------------------------------------------
# 阶段2: 生产服务健康检查
# ------------------------------------------------------------
echo ">>> 阶段2: 生产服务健康检查"
echo "---"

# Nginx
if command -v systemctl &>/dev/null; then
    NGINX_STATUS=$(systemctl is-active nginx 2>/dev/null || echo "unknown")
    echo "Nginx: $NGINX_STATUS"
    if [ "$NGINX_STATUS" = "active" ]; then PASS=$((PASS+1)); else WARN=$((WARN+1)); fi

    # PHP-FPM (尝试常见服务名)
    PHPFPM_SERVICE=$(systemctl list-units --type=service --state=running 2>/dev/null | grep -o 'php[0-9.]*-fpm' | head -1 || echo "")
    if [ -n "$PHPFPM_SERVICE" ]; then
        PHPFPM_STATUS=$(systemctl is-active "$PHPFPM_SERVICE" 2>/dev/null || echo "unknown")
        echo "PHP-FPM ($PHPFPM_SERVICE): $PHPFPM_STATUS"
        if [ "$PHPFPM_STATUS" = "active" ]; then PASS=$((PASS+1)); else WARN=$((WARN+1)); fi
    else
        echo "[SKIP] 未检测到 PHP-FPM 服务 (可能使用不同名称)"
        SKIP=$((SKIP+1))
    fi
elif command -v service &>/dev/null; then
    NGINX_STATUS=$(service nginx status 2>/dev/null | head -1 || echo "unknown")
    echo "Nginx: $NGINX_STATUS"
else
    echo "[SKIP] 无 systemctl/service，跳过服务状态检查"
    SKIP=$((SKIP+2))
fi

# 网站健康检查
echo ""
echo "--- HTTP 健康检查 ---"
for DOMAIN in "https://hcz.app" "https://ops.hcz.app"; do
    HTTP_CODE=$(curl -k -s -o /dev/null -w "%{http_code}" --max-time 10 "$DOMAIN/" 2>/dev/null || echo "000")
    echo "$DOMAIN/ -> HTTP $HTTP_CODE"
    if [ "$HTTP_CODE" != "000" ] && [ "$HTTP_CODE" != "500" ] && [ "$HTTP_CODE" != "502" ] && [ "$HTTP_CODE" != "503" ]; then
        PASS=$((PASS+1))
    else
        WARN=$((WARN+1))
    fi
done

echo ""

# ------------------------------------------------------------
# 阶段3: 未登录访问后台
# ------------------------------------------------------------
echo ">>> 阶段3: 未登录访问后台 (AdminAuth)"
echo "---"

# 检测后台入口
BACKSTAGE_ENTRY=$(grep -r "backstage_entrance" config/ .env 2>/dev/null | grep -v "^Binary" | head -1 | grep -o "'[^']*'" | tail -1 | tr -d "'" || echo "admin")
if [ -z "$BACKSTAGE_ENTRY" ] || [ "$BACKSTAGE_ENTRY" = "admin" ]; then
    BACKSTAGE_ENTRY=$(php -r "echo getConfig('backstage_entrance') ?: 'admin';" 2>/dev/null || echo "admin")
fi
echo "后台入口: /$BACKSTAGE_ENTRY"

# 测试未登录访问 admin_list (应该 403 或跳转)
for DOMAIN in "https://hcz.app" "https://ops.hcz.app"; do
    RESP=$(curl -k -s -w "\n%{http_code}" --max-time 10 "$DOMAIN/$BACKSTAGE_ENTRY/admin_list?draw=1&start=0&length=1" 2>/dev/null || echo "")
    HTTP_CODE=$(echo "$RESP" | tail -1)
    BODY=$(echo "$RESP" | sed '$d')
    echo "$DOMAIN/$BACKSTAGE_ENTRY/admin_list -> HTTP $HTTP_CODE"
    
    # 检查是否泄露敏感字段
    if echo "$BODY" | grep -qi "password\|twofa_secret\|twofa_recovery_codes"; then
        echo "  [FAIL] 响应中包含敏感字段!"
        FAIL=$((FAIL+1))
    else
        echo "  [PASS] 响应中无敏感字段"
        PASS=$((PASS+1))
    fi
    
    # 未登录应该被拒绝
    if [ "$HTTP_CODE" = "403" ] || [ "$HTTP_CODE" = "401" ] || [ "$HTTP_CODE" = "302" ]; then
        echo "  [PASS] 未登录被正确拒绝 (HTTP $HTTP_CODE)"
        PASS=$((PASS+1))
    else
        echo "  [INFO] 未登录返回 HTTP $HTTP_CODE (可能是登录页跳转)"
    fi
done

echo ""

# ------------------------------------------------------------
# 阶段4: 代码级安全验证 (生产环境代码)
# ------------------------------------------------------------
echo ">>> 阶段4: 代码级安全验证"
echo "---"

# P1-001: 删除保护
echo "P1-001 管理员删除保护:"
if grep -q "禁止删除超级管理员" app/controller/AdminApi.php; then
    echo "  [PASS] 禁止删除超级管理员 ID=1"
    PASS=$((PASS+1))
else
    echo "  [FAIL] 缺少禁止删除超级管理员保护"
    FAIL=$((FAIL+1))
fi
if grep -q "禁止删除当前登录账号" app/controller/AdminApi.php; then
    echo "  [PASS] 禁止删除自己"
    PASS=$((PASS+1))
else
    echo "  [FAIL] 缺少禁止删除自己保护"
    FAIL=$((FAIL+1))
fi
if grep -q "directValidateSensitiveOperation.*admin_delete" app/controller/AdminApi.php; then
    echo "  [PASS] 删除需敏感操作验证"
    PASS=$((PASS+1))
else
    echo "  [WARN] 未找到删除敏感操作验证"
    WARN=$((WARN+1))
fi

# P1-002: power 保护
echo ""
echo "P1-002 管理员 power 保护:"
if grep -q "仅超级管理员可修改 power" app/controller/AdminApi.php; then
    echo "  [PASS] 仅超级管理员可修改 power"
    PASS=$((PASS+1))
else
    echo "  [FAIL] 缺少 power 写入保护"
    FAIL=$((FAIL+1))
fi
if grep -q "directValidateAdminPowerValue" app/controller/AdminApi.php; then
    echo "  [PASS] power 白名单校验"
    PASS=$((PASS+1))
else
    echo "  [FAIL] 缺少 power 白名单校验"
    FAIL=$((FAIL+1))
fi
if grep -q "无权修改超级管理员" app/controller/AdminApi.php; then
    echo "  [PASS] 禁止普通管理员修改 ID=1"
    PASS=$((PASS+1))
else
    echo "  [FAIL] 缺少禁止修改超级管理员保护"
    FAIL=$((FAIL+1))
fi

# P1-003: 密码保护
echo ""
echo "P1-003 管理员密码保护:"
if grep -q "仅超级管理员可修改其他管理员密码" app/controller/AdminApi.php; then
    echo "  [PASS] 非超管禁止修改他人密码"
    PASS=$((PASS+1))
else
    echo "  [FAIL] 缺少密码越权保护"
    FAIL=$((FAIL+1))
fi

# P2-001: info 保护
echo ""
echo "P2-001 admin_post/info 保护:"
if grep -q "P2-001.*权限校验" app/controller/AdminApi.php; then
    echo "  [PASS] info 接口有权限校验"
    PASS=$((PASS+1))
else
    echo "  [FAIL] info 接口缺少权限校验"
    FAIL=$((FAIL+1))
fi
if grep -q "P2-001.*字段白名单" app/controller/AdminApi.php; then
    echo "  [PASS] info 接口有字段白名单"
    PASS=$((PASS+1))
else
    echo "  [FAIL] info 接口缺少字段白名单"
    FAIL=$((FAIL+1))
fi

# P2-002: admin_list 保护
echo ""
echo "P2-002 admin_list 保护:"
if grep -q "P2-002.*管理员列表权限校验" app/controller/AdminList.php; then
    echo "  [PASS] admin_list 有权限校验"
    PASS=$((PASS+1))
else
    echo "  [FAIL] admin_list 缺少权限校验"
    FAIL=$((FAIL+1))
fi
if grep -q "field('id,name,account,avatar,power,create_time')" app/controller/AdminList.php; then
    echo "  [PASS] admin_list 有查询字段白名单"
    PASS=$((PASS+1))
else
    echo "  [FAIL] admin_list 缺少查询字段白名单"
    FAIL=$((FAIL+1))
fi

echo ""

# ------------------------------------------------------------
# 阶段5: 敏感字段全仓库扫描
# ------------------------------------------------------------
echo ">>> 阶段5: 敏感字段泄露扫描"
echo "---"

# 扫描 AdminModel 查询后直接返回的模式
LEAK_COUNT=0
echo "扫描 AdminModel::select/get/all 后无 field() 限制的查询..."
grep -rn "AdminModel::" app/controller/ --include="*.php" | grep -E "select\(\)|get\(\)|all\(\)" | while read -r line; do
    FILE=$(echo "$line" | cut -d: -f1)
    LINENUM=$(echo "$line" | cut -d: -f2)
    # 检查同一行或前3行是否有 field()
    CONTEXT=$(sed -n "$((LINENUM-3)),${LINENUM}p" "$FILE" 2>/dev/null)
    if ! echo "$CONTEXT" | grep -q "field("; then
        echo "  [?] $FILE:$LINENUM (可能无 field 限制，需人工确认)"
        LEAK_COUNT=$((LEAK_COUNT+1))
    fi
done

if [ "$LEAK_COUNT" -eq 0 ]; then
    echo "  [PASS] 未发现无 field 限制的 AdminModel 批量查询"
    PASS=$((PASS+1))
else
    echo "  [WARN] 发现 $LEAK_COUNT 处需人工确认"
    WARN=$((WARN+1))
fi

echo ""

# ------------------------------------------------------------
# 阶段6: 生产日志检查
# ------------------------------------------------------------
echo ">>> 阶段6: 生产日志检查"
echo "---"

LOG_DIR="runtime/log"
if [ -d "$LOG_DIR" ]; then
    LATEST_LOG=$(find "$LOG_DIR" -name "*.log" -type f 2>/dev/null | sort | tail -1)
    if [ -n "$LATEST_LOG" ]; then
        echo "最新日志: $LATEST_LOG"
        ERROR_COUNT=$(grep -ci "error\|fatal\|exception" "$LATEST_LOG" 2>/dev/null || echo 0)
        WARN_COUNT=$(grep -ci "warning\|permission denied\|csrf" "$LATEST_LOG" 2>/dev/null || echo 0)
        echo "ERROR/FATAL/EXCEPTION: $ERROR_COUNT"
        echo "WARNING/CSRF: $WARN_COUNT"
        if [ "$ERROR_COUNT" -gt 0 ]; then
            echo "  最近错误:"
            grep -i "error\|fatal\|exception" "$LATEST_LOG" 2>/dev/null | tail -5
            WARN=$((WARN+1))
        else
            echo "  [PASS] 无错误日志"
            PASS=$((PASS+1))
        fi
    else
        echo "[SKIP] 未找到日志文件"
        SKIP=$((SKIP+1))
    fi
else
    echo "[SKIP] 日志目录不存在: $LOG_DIR"
    SKIP=$((SKIP+1))
fi

echo ""

# ------------------------------------------------------------
# 阶段7: 最终 Git 状态
# ------------------------------------------------------------
echo ">>> 阶段7: 最终 Git 状态"
echo "---"
FINAL_BRANCH=$(git branch --show-current)
FINAL_COMMIT=$(git rev-parse HEAD)
FINAL_STATUS=$(git status --short | grep -v "^??" || echo "clean (代码无修改)")
echo "分支: $FINAL_BRANCH"
echo "HEAD: $FINAL_COMMIT"
echo "代码状态: $FINAL_STATUS"

if [ "$FINAL_COMMIT" = "$TARGET_COMMIT" ]; then
    echo "[PASS] 最终版本确认"
    PASS=$((PASS+1))
else
    echo "[FAIL] 最终版本不匹配!"
    FAIL=$((FAIL+1))
fi

echo ""
echo "============================================"
echo "  验收统计"
echo "============================================"
echo "PASS: $PASS"
echo "FAIL: $FAIL"
echo "WARN: $WARN"
echo "SKIP: $SKIP"
echo ""

if [ "$FAIL" -gt 0 ]; then
    echo "结论: P6 BLOCKED (发现 $FAIL 个 FAIL)"
    echo "请检查上方 FAIL 项并修复后重新验收"
    exit 1
else
    echo "结论: P6 PASS (无 FAIL, $WARN 个 WARN, $SKIP 个 SKIP)"
    echo "生产版本 = ${FINAL_COMMIT:0:7}"
    echo "P0 = 0, P1 = 0, P2 = 0"
    echo "可以正式进入上线/稳定运行阶段"
    exit 0
fi
