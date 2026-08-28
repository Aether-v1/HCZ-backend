/* ============================================================
 * Admin UI Common JS — 后台管理系统统一 UI 交互
 * 作用：统一 DataTables 初始化、确认框、Toast、DatePicker、
 *       空状态、分页、金额格式化等
 * 引入位置：admin_footer.html 中 scripts.bundle.js 之后
 * 依赖：jQuery, DataTables, SweetAlert2, Toastr, Flatpickr
 * ============================================================ */

(function (window, $) {
    'use strict';

    var AdminUI = {
        version: '1.0.0',

        /* ──────────────────────────────────────────────
         * 1. DataTables 统一初始化
         * 用法：AdminUI.initDataTable('#tableId', { columns: [...], ajax: {...}, ... })
         * ────────────────────────────────────────────── */
        initDataTable: function (selector, options) {
            var defaults = {
                searchDelay: 500,
                processing: true,
                serverSide: true,
                stateSave: false,
                pagingType: 'simple_numbers',
                pageLength: 25,
                lengthMenu: [25, 50, 100],
                info: true,
                order: [[0, 'desc']],
                language: {
                    sProcessing: '加载中...',
                    sLengthMenu: '每页 _MENU_ 条',
                    sZeroRecords: '没有匹配的数据',
                    sInfo: '显示第 _START_ 至 _END_ 条，共 _TOTAL_ 条',
                    sInfoEmpty: '暂无数据',
                    sInfoFiltered: '(由 _MAX_ 项结果过滤)',
                    sSearch: '搜索:',
                    sUrl: '',
                    oPaginate: {
                        sFirst: '首页',
                        sPrevious: '上一页',
                        sNext: '下一页',
                        sLast: '末页'
                    },
                    oAria: {
                        sSortAscending: ': 升序排列',
                        sSortDescending: ': 降序排列'
                    }
                },
                drawCallback: function () {
                    if (typeof KTMenu !== 'undefined') {
                        KTMenu.createInstances();
                    }
                    // 空状态处理
                    var api = this.api();
                    var $tbody = $(this).find('tbody');
                    if (api.page.info().recordsTotal === 0) {
                        var colCount = api.columns().count();
                        $tbody.html(
                            '<tr><td colspan="' + colCount + '">' +
                            AdminUI.renderEmptyState('暂无数据', '调整筛选条件或添加新记录') +
                            '</td></tr>'
                        );
                    }
                }
            };

            // 合并用户配置（用户配置优先）
            var config = $.extend(true, {}, defaults, options || {});

            // 保留用户自定义的 drawCallback
            if (options && options.drawCallback) {
                var userDrawCallback = options.drawCallback;
                config.drawCallback = function (settings) {
                    defaults.drawCallback.call(this, settings);
                    userDrawCallback.call(this, settings);
                };
            }

            var dt = $(selector).DataTable(config);

            // 给表格 wrapper 添加统一类名
            $(selector).closest('.dataTables_wrapper').addClass('admin-datatable_wrapper');
            $(selector).addClass('admin-datatable');

            return dt;
        },

        /* ──────────────────────────────────────────────
         * 2. 确认框统一
         * 用法：AdminUI.confirm({ title: '确认删除？', text: '此操作不可撤销', type: 'danger', onConfirm: function() {...} })
         * ────────────────────────────────────────────── */
        confirm: function (opts) {
            var options = $.extend({
                title: '确认操作？',
                html: '',
                text: '',
                type: 'warning', // warning, danger, info, success
                confirmText: '确认',
                cancelText: '取消',
                showCancel: true,
                onConfirm: null,
                onCancel: null
            }, opts || {});

            var confirmColor = '#3699FF';
            var icon = 'warning';
            if (options.type === 'danger') {
                confirmColor = '#F1416C';
                icon = 'error';
            } else if (options.type === 'success') {
                confirmColor = '#50CD89';
                icon = 'success';
            } else if (options.type === 'info') {
                confirmColor = '#7239EA';
                icon = 'info';
            }

            var htmlContent = options.html || '';
            if (options.text && !htmlContent) {
                htmlContent = '<div class="text-muted fs-6 mt-2">' + options.text + '</div>';
            }

            Swal.fire({
                title: '<h2 style="margin:0;font-size:1.1rem;">' + options.title + '</h2>',
                html: htmlContent,
                icon: icon,
                showCancelButton: options.showCancel,
                confirmButtonText: options.confirmText,
                cancelButtonText: options.cancelText,
                confirmButtonColor: confirmColor,
                reverseButtons: true,
                customClass: {
                    confirmButton: 'btn btn-sm fw-bold',
                    cancelButton: 'btn btn-sm btn-light fw-bold me-3'
                },
                buttonsStyling: false
            }).then(function (result) {
                if (result.isConfirmed && typeof options.onConfirm === 'function') {
                    options.onConfirm();
                } else if (!result.isConfirmed && typeof options.onCancel === 'function') {
                    options.onCancel();
                }
            });
        },

        /* 危险操作确认框（带上下文信息） */
        confirmDanger: function (opts) {
            var options = $.extend({
                title: '确认执行危险操作？',
                actionName: '',
                targetName: '',
                warning: '此操作不可撤销，请谨慎操作。',
                extraInfo: '',
                confirmText: '确认执行',
                onConfirm: null
            }, opts || {});

            var html = '<div class="text-start" style="font-size:0.85rem;">';
            if (options.actionName) {
                html += '<p style="margin:0 0 0.5rem 0;">操作类型：<strong>' + options.actionName + '</strong></p>';
            }
            if (options.targetName) {
                html += '<p style="margin:0 0 0.5rem 0;">操作对象：<strong>' + options.targetName + '</strong></p>';
            }
            if (options.extraInfo) {
                html += '<p style="margin:0 0 0.5rem 0;">' + options.extraInfo + '</p>';
            }
            html += '<p style="margin:0;color:#F1416C;font-weight:600;">⚠️ ' + options.warning + '</p>';
            html += '</div>';

            AdminUI.confirm({
                title: options.title,
                html: html,
                type: 'danger',
                confirmText: options.confirmText,
                onConfirm: options.onConfirm
            });
        },

        /* ──────────────────────────────────────────────
         * 3. Toast 统一
         * 用法：AdminUI.toast('success', '操作成功')
         * ────────────────────────────────────────────── */
        toast: function (type, message, title) {
            var titles = {
                success: '操作成功',
                error: '操作失败',
                warning: '提示',
                info: '信息'
            };
            var config = {
                closeButton: true,
                debug: false,
                newestOnTop: true,
                progressBar: true,
                positionClass: 'toast-top-right',
                preventDuplicates: false,
                onclick: null,
                showDuration: 300,
                hideDuration: 1000,
                timeOut: type === 'error' ? 5000 : 3000,
                extendedTimeOut: 1000,
                showEasing: 'swing',
                hideEasing: 'linear',
                showMethod: 'fadeIn',
                hideMethod: 'fadeOut'
            };
            toastr[type](message, title || titles[type] || '提示', config);
        },

        toastSuccess: function (msg) { AdminUI.toast('success', msg); },
        toastError: function (msg) { AdminUI.toast('error', msg); },
        toastWarning: function (msg) { AdminUI.toast('warning', msg); },
        toastInfo: function (msg) { AdminUI.toast('info', msg); },

        /* ──────────────────────────────────────────────
         * 4. DatePicker 统一初始化
         * 用法：AdminUI.initDatePicker('#dateInput', { mode: 'range' })
         * ────────────────────────────────────────────── */
        initDatePicker: function (selector, opts) {
            if (typeof flatpickr === 'undefined') {
                console.warn('AdminUI: flatpickr not loaded');
                return null;
            }

            var defaults = {
                dateFormat: 'Y-m-d',
                allowInput: true,
                clickOpens: true,
                locale: {
                    firstDayOfWeek: 1,
                    weekdays: {
                        shorthand: ['日', '一', '二', '三', '四', '五', '六'],
                        longhand: ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六']
                    },
                    months: {
                        shorthand: ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'],
                        longhand: ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月']
                    }
                }
            };

            var config = $.extend({}, defaults, opts || {});
            return flatpickr(selector, config);
        },

        /* 日期范围选择器（带快捷选项） */
        initDateRangePicker: function (selector, opts) {
            var defaults = {
                mode: 'range',
                dateFormat: 'Y-m-d',
                allowInput: true
            };
            return AdminUI.initDatePicker(selector, $.extend({}, defaults, opts || {}));
        },

        /* ──────────────────────────────────────────────
         * 5. 空状态渲染
         * ────────────────────────────────────────────── */
        renderEmptyState: function (title, desc, actionHtml) {
            var html = '<div class="admin-empty-state">' +
                '<div class="admin-empty-icon">📋</div>' +
                '<div class="admin-empty-title">' + (title || '暂无数据') + '</div>' +
                '<div class="admin-empty-desc">' + (desc || '调整筛选条件或添加新记录') + '</div>';
            if (actionHtml) {
                html += '<div class="admin-empty-action">' + actionHtml + '</div>';
            }
            html += '</div>';
            return html;
        },

        /* ──────────────────────────────────────────────
         * 6. 金额格式化
         * ────────────────────────────────────────────── */
        formatAmount: function (value, decimals, currency) {
            var n = Number(String(value || '0').replace(/,/g, ''));
            if (!Number.isFinite(n)) n = 0;
            var d = (typeof decimals === 'number') ? decimals : 2;
            var formatted = n.toLocaleString('en-US', {
                minimumFractionDigits: d,
                maximumFractionDigits: d
            });
            if (currency) {
                formatted = currency + ' ' + formatted;
            }
            return formatted;
        },

        /* 人民币金额 */
        formatCNY: function (value) {
            return AdminUI.formatAmount(value, 2, '¥');
        },

        /* USDT 金额 */
        formatUSDT: function (value, decimals) {
            return AdminUI.formatAmount(value, decimals || 2, '₮');
        },

        /* ──────────────────────────────────────────────
         * 7. Badge 渲染
         * 用法：AdminUI.renderBadge('success', '已完成')
         * ────────────────────────────────────────────── */
        renderBadge: function (type, text, size) {
            var validTypes = ['primary', 'success', 'warning', 'danger', 'info', 'secondary', 'dark'];
            var t = validTypes.indexOf(type) >= 0 ? type : 'secondary';
            var sizeClass = size === 'sm' ? ' admin-badge-sm' : '';
            return '<span class="admin-badge ' + t + sizeClass + '">' + text + '</span>';
        },

        /* ──────────────────────────────────────────────
         * 8. 页面头部渲染
         * 用法：AdminUI.renderPageHeader('用户管理', '管理平台所有注册用户', '<button class="btn btn-primary">+ 添加用户</button>')
         * ────────────────────────────────────────────── */
        renderPageHeader: function (title, subtitle, actionsHtml) {
            var html = '<div class="admin-page-header">' +
                '<div class="admin-page-title">' +
                '<h1>' + title + '</h1>';
            if (subtitle) {
                html += '<p>' + subtitle + '</p>';
            }
            html += '</div>';
            if (actionsHtml) {
                html += '<div class="admin-page-actions">' + actionsHtml + '</div>';
            }
            html += '</div>';
            return html;
        },

        /* ──────────────────────────────────────────────
         * 9. 表单提交 Loading 状态
         * ────────────────────────────────────────────── */
        setButtonLoading: function (btnSelector, loadingText) {
            var $btn = $(btnSelector);
            if (!$btn.length) return;
            $btn.data('original-text', $btn.html());
            $btn.prop('disabled', true);
            $btn.html('<span class="spinner-border spinner-border-sm align-middle me-2"></span>' + (loadingText || '处理中...'));
        },

        resetButtonLoading: function (btnSelector) {
            var $btn = $(btnSelector);
            if (!$btn.length) return;
            $btn.prop('disabled', false);
            $btn.html($btn.data('original-text') || $btn.html());
        },

        /* ──────────────────────────────────────────────
         * 10. 通用 AJAX 请求封装（带统一错误处理）
         * ────────────────────────────────────────────── */
        ajax: function (opts) {
            var defaults = {
                method: 'POST',
                dataType: 'json',
                beforeSend: function () {},
                success: function () {},
                error: function (xhr) {
                    AdminUI.toastError('请求失败：' + (xhr.statusText || '网络错误'));
                },
                complete: function () {}
            };
            var config = $.extend({}, defaults, opts || {});
            $.ajax(config);
        },

        /* ──────────────────────────────────────────────
         * 11. 刷新当前 DataTable
         * ────────────────────────────────────────────── */
        reloadTable: function (dt) {
            if (dt && typeof dt.ajax === 'function') {
                dt.ajax.reload(null, false);
            }
        },

        /* ──────────────────────────────────────────────
         * 12. HTML 转义
         * ────────────────────────────────────────────── */
        escapeHtml: function (str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        /* ──────────────────────────────────────────────
         * 13. 自动页面头部注入（Phase 2 核心）
         * 从侧边栏激活菜单或 <title> 读取页面名称，
         * 自动在内容区顶部注入统一 .admin-page-header
         * ────────────────────────────────────────────── */
        getPageTitle: function () {
            // 优先从激活的侧边栏菜单项读取
            var $activeMenu = $('.menu-link.active .menu-title, .menu-link.active .menu-text');
            if ($activeMenu.length && $activeMenu.text().trim()) {
                return $activeMenu.text().trim();
            }
            // 其次从 <title> 解析（格式：页面名 - 【站点名】后台管理）
            var rawTitle = (document.title || '').trim();
            var dashIndex = rawTitle.indexOf(' - ');
            if (dashIndex > 0) {
                return rawTitle.substring(0, dashIndex).trim();
            }
            if (rawTitle) return rawTitle;
            return '';
        },

        injectPageHeader: function () {
            // 已注入则跳过
            if ($('.admin-page-header.auto-injected').length) return;

            var title = AdminUI.getPageTitle();
            if (!title) return;

            // 登录页、2FA设置页不注入
            if (title === '登录' || title.indexOf('2FA') >= 0 || title.indexOf('二步') >= 0) return;

            var headerHtml = '<div class="admin-page-header auto-injected">' +
                '<div class="admin-page-title"><h1>' + AdminUI.escapeHtml(title) + '</h1></div>' +
                '</div>';

            // 查找内容注入点：优先 app-main 内第一个容器，其次 .card 之前，其次 body
            var $main = $('#kt_app_main .d-flex.flex-column.flex-column-fluid, #kt_app_main, .app-main');
            if ($main.length) {
                // 在第一个子元素前注入
                var $firstChild = $main.first().children().first();
                if ($firstChild.length) {
                    $firstChild.before(headerHtml);
                } else {
                    $main.first().prepend(headerHtml);
                }
            } else {
                var $firstCard = $('.card').first();
                if ($firstCard.length) {
                    $firstCard.before(headerHtml);
                }
            }
        },

        /* ──────────────────────────────────────────────
         * 14. 全局 Toastr 默认值统一（Phase 2 核心）
         * 覆盖 toastr.options，使所有现有 toastr 调用自动统一
         * ────────────────────────────────────────────── */
        initToastrDefaults: function () {
            if (typeof toastr === 'undefined') return;
            toastr.options = {
                closeButton: true,
                debug: false,
                newestOnTop: true,
                progressBar: true,
                positionClass: 'toast-top-right',
                preventDuplicates: false,
                onclick: null,
                showDuration: 300,
                hideDuration: 1000,
                timeOut: 3000,
                extendedTimeOut: 1000,
                showEasing: 'swing',
                hideEasing: 'linear',
                showMethod: 'fadeIn',
                hideMethod: 'fadeOut'
            };
        },

        /* ──────────────────────────────────────────────
         * 15. 订单状态 Badge 统一渲染（Phase 3 核心）
         * 支付状态 + 充值状态联合判断，全后台统一视觉
         * ────────────────────────────────────────────── */
        renderOrderStatusBadge: function (payStatus, rechargeStatus) {
            var pay = String(payStatus || '');
            var recharge = String(rechargeStatus || '');
            var text = '';
            var type = 'secondary';

            // 支付失败
            if (pay === '0' || pay === 'failed' || pay === '未支付' || pay === '支付失败') {
                text = '未支付';
                type = 'secondary';
            }
            // 支付中
            else if (pay === '1' || pay === 'pending' || pay === '支付中' || pay === '待支付') {
                text = '支付中';
                type = 'warning';
            }
            // 已支付
            else if (pay === '2' || pay === 'success' || pay === '已支付' || pay === '支付成功') {
                // 充值成功
                if (recharge === '2' || recharge === 'success' || recharge === '充值成功' || recharge === '成功') {
                    text = '充值成功';
                    type = 'success';
                }
                // 充值失败
                else if (recharge === '3' || recharge === 'failed' || recharge === '充值失败' || recharge === '失败') {
                    text = '充值失败';
                    type = 'danger';
                }
                // 充值处理中
                else if (recharge === '1' || recharge === 'processing' || recharge === '充值中' || recharge === '处理中') {
                    text = '充值中';
                    type = 'info';
                }
                // 已退款
                else if (recharge === '4' || recharge === 'refunded' || recharge === '已退款' || recharge === '退款') {
                    text = '已退款';
                    type = 'secondary';
                }
                // 异常
                else if (recharge === '5' || recharge === 'error' || recharge === '异常' || recharge === 'timeout' || recharge === '超时') {
                    text = '充值异常';
                    type = 'danger';
                }
                else {
                    text = '已支付';
                    type = 'primary';
                }
            }
            else {
                text = '未知';
                type = 'secondary';
            }

            return AdminUI.renderBadge(type, text);
        },

        /* 商品状态 Badge */
        renderProductStatusBadge: function (status) {
            var s = String(status || '');
            if (s === '1' || s === 'on' || s === '上架' || s === '启用' || s === '正常') {
                return AdminUI.renderBadge('success', '上架');
            }
            if (s === '0' || s === 'off' || s === '下架' || s === '禁用' || s === '关闭') {
                return AdminUI.renderBadge('secondary', '下架');
            }
            return AdminUI.renderBadge('secondary', '未知');
        },

        /* 用户状态 Badge */
        renderUserStatusBadge: function (status) {
            var s = String(status || '');
            if (s === '1' || s === 'normal' || s === '正常' || s === '启用') {
                return AdminUI.renderBadge('success', '正常');
            }
            if (s === '0' || s === 'disabled' || s === '禁用' || s === '冻结') {
                return AdminUI.renderBadge('danger', '禁用');
            }
            return AdminUI.renderBadge('secondary', '未知');
        },

        /* ──────────────────────────────────────────────
         * 16. 筛选栏标准化（Phase 2）
         * 给现有筛选表单添加统一类名和结构
         * ────────────────────────────────────────────── */
        standardizeFilterBar: function () {
            // 查找包含搜索/筛选的表单区域，添加统一类名
            $('.card:has(.dataTables_filter), .card:has(form)').each(function () {
                var $card = $(this);
                if (!$card.find('.admin-filter-bar').length) {
                    // 查找筛选表单
                    var $filter = $card.find('form').first();
                    if ($filter.length && $filter.find('input, select').length) {
                        $filter.addClass('admin-filter-bar');
                    }
                }
            });
        },

        /* ──────────────────────────────────────────────
         * 17. Modal 标准化（Phase 2）
         * 给现有 Modal 添加统一类名
         * ────────────────────────────────────────────── */
        standardizeModals: function () {
            $('.modal').each(function () {
                var $modal = $(this);
                if (!$modal.hasClass('admin-modal')) {
                    $modal.addClass('admin-modal');
                }
                // 统一 modal-dialog 大小
                var $dialog = $modal.find('.modal-dialog');
                if ($dialog.length && !$dialog.attr('class').match(/modal-(sm|md|lg|xl)/)) {
                    $dialog.addClass('modal-md');
                }
            });
        },

        /* ──────────────────────────────────────────────
         * 18. 金额显示统一（Phase 3 财务）
         * 自动给金额元素添加统一格式
         * ────────────────────────────────────────────── */
        standardizeAmounts: function () {
            // 给包含金额的 td 添加统一类名（基于列标题判断）
            $('table').each(function () {
                var $table = $(this);
                $table.find('thead th').each(function (colIndex) {
                    var headerText = $(this).text().trim();
                    if (headerText.match(/金额|收入|支出|成本|利润|售价|面值|到账|支付|充值|余额|提现|返佣/)) {
                        $table.find('tbody tr').each(function () {
                            $(this).find('td').eq(colIndex).addClass('admin-amount-cell');
                        });
                    }
                });
            });
        }
    };

    // 暴露到全局
    window.AdminUI = AdminUI;

    /* ──────────────────────────────────────────────
     * 自动初始化（DOM ready 时执行）
     * ────────────────────────────────────────────── */
    $(function () {
        // 1. 统一 Toastr 默认值（所有现有 toastr 调用自动生效）
        AdminUI.initToastrDefaults();

        // 2. 自动注入页面头部（所有页面自动获得统一 H1）
        AdminUI.injectPageHeader();

        // 3. 标准化 Modal
        AdminUI.standardizeModals();

        // 4. 标准化金额显示
        AdminUI.standardizeAmounts();

        // 5. DataTables 绘制完成后重新标准化
        $(document).on('draw.dt', function () {
            AdminUI.standardizeAmounts();
            if (typeof KTMenu !== 'undefined') {
                try { KTMenu.createInstances(); } catch (e) {}
            }
        });
    });

})(window, jQuery);
