/* 线索表单公开页交互（单 8）。命名对齐 geo-visibility.*。
 *
 * 红线（执行书 §五-5 前端接后端-防走偏规程）：
 * - 严格照 API 契约（字段名 / 校验结果）写，不照原型脑补后端；
 * - 接口失败要显式报错，绝不信静默回落到假数据。
 */
(function () {
    'use strict';

    var cfg = window.LF_CONFIG || {};
    var form = document.getElementById('lf-form');
    var alertBox = document.getElementById('lf-alert');

    if (!form || !cfg.submitUrl) {
        return;
    }

    function showAlert(message, ok) {
        if (!alertBox) {
            return;
        }
        // 用 textContent，绝不 innerHTML —— 表单文案里的 <script> 不会被执行（L8）。
        alertBox.textContent = message;
        alertBox.hidden = false;
        alertBox.className = 'lf-alert' + (ok ? ' lf-alert--ok' : ' lf-alert--err');
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var data = {};
        // 收集表单字段（含 honeypot website）。
        new FormData(form).forEach(function (value, key) {
            data[key] = value;
        });

        showAlert('提交中…', true);
        form.querySelector('.lf-submit').disabled = true;

        fetch(cfg.submitUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': cfg.csrfToken || ''
            },
            // 后端按 Accept: application/json 返回 JSON；字段仍按表单名提交。
            body: JSON.stringify(data)
        }).then(function (response) {
            return response.json().then(function (body) {
                return { ok: response.ok, status: response.status, body: body };
            });
        }).then(function (result) {
            if (result.ok && result.body && result.body.ok) {
                showAlert(result.body.message || '提交成功。', true);
                form.reset();
                return;
            }
            // 失败：显式报错，不静默回落。
            var msg = (result.body && result.body.message) ? result.body.message : '提交失败，请稍后重试。';
            showAlert(msg, false);
        }).catch(function () {
            // 网络 / 解析错误也要显式提示，绝不假装成功。
            showAlert('网络异常，提交未成功，请稍后重试。', false);
        }).then(function () {
            var btn = form.querySelector('.lf-submit');
            if (btn) {
                btn.disabled = false;
            }
        });
    });
})();
