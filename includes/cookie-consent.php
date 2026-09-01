<?php
if (!defined('ABSPATH')) { exit; }

function tk_cookie_consent_init() {
    if (!tk_cookie_consent_enabled()) {
        return;
    }

    add_action('wp_footer', 'tk_cookie_consent_render_banner', 100);
}

function tk_cookie_consent_enabled(): bool {
    return (int) tk_get_option('cookie_consent_enabled', 0) === 1;
}

function tk_cookie_consent_render_banner() {
    $cookie_text = (string) tk_get_option('cookie_consent_text', '');

    $privacy_url = (string) tk_get_option('cookie_consent_privacy_url', '');
    $expiry_days = (int) tk_get_option('cookie_consent_expiry', 365);
    ?>
    <div class="tk-cookie-consent" id="tk-cookie-consent-banner">
        <div class="tk-cookie-consent-inner">
            <p class="tk-cookie-consent-text">
                <?php if ($cookie_text !== '') : ?>
                    <?php echo wp_kses_post($cookie_text); ?>
                <?php else : ?>
                    By clicking <strong>"Accept"</strong>, you agree to the storing of cookies on your device to enhance site navigation, analyze site usage, and assist in our marketing efforts. View our <a href="<?php echo esc_url($privacy_url ? $privacy_url : '#'); ?>" class="tk-cookie-consent-link">Privacy Policy</a> for more information.
                <?php endif; ?>
            </p>
            <div class="tk-cookie-consent-actions">
                <button class="tk-cookie-btn tk-cookie-btn-pref" type="button" id="tk-cookie-btn-pref">Preferences</button>
                <button class="tk-cookie-btn tk-cookie-btn-reject" type="button" id="tk-cookie-btn-reject">Reject</button>
                <button class="tk-cookie-btn tk-cookie-btn-accept" type="button" id="tk-cookie-btn-accept">Accept</button>
            </div>
        </div>
    </div>

    <div class="tk-cookie-modal-overlay" id="tk-cookie-modal-overlay"></div>
    <div class="tk-cookie-modal" id="tk-cookie-modal">
        <div class="tk-cookie-modal-header">
            <h3>Cookie Preferences</h3>
            <button type="button" class="tk-cookie-modal-close" id="tk-cookie-modal-close">&times;</button>
        </div>
        <div class="tk-cookie-modal-body">
            <div class="tk-cookie-item">
                <div class="tk-cookie-item-info">
                    <h4>Strictly Necessary Cookies</h4>
                    <p>These cookies are necessary for the website to function and cannot be switched off.</p>
                </div>
                <div class="tk-cookie-item-toggle">
                    <span style="color: #64748b; font-size: 13px; font-weight: bold;">Always Active</span>
                </div>
            </div>
            <div class="tk-cookie-item">
                <div class="tk-cookie-item-info">
                    <h4>Analytics Cookies</h4>
                    <p>These cookies allow us to count visits and traffic sources so we can measure and improve the performance of our site.</p>
                </div>
                <div class="tk-cookie-item-toggle">
                    <label class="tk-cookie-switch">
                        <input type="checkbox" id="tk-cookie-pref-analytics" checked>
                        <span class="tk-cookie-slider"></span>
                    </label>
                </div>
            </div>
            <div class="tk-cookie-item">
                <div class="tk-cookie-item-info">
                    <h4>Marketing Cookies</h4>
                    <p>These cookies may be set through our site by our advertising partners to build a profile of your interests.</p>
                </div>
                <div class="tk-cookie-item-toggle">
                    <label class="tk-cookie-switch">
                        <input type="checkbox" id="tk-cookie-pref-marketing" checked>
                        <span class="tk-cookie-slider"></span>
                    </label>
                </div>
            </div>
        </div>
        <div class="tk-cookie-modal-footer">
            <button class="tk-cookie-btn tk-cookie-btn-accept" type="button" id="tk-cookie-btn-save-pref" style="width: 100%;">Save Preferences</button>
        </div>
    </div>

    <style>
    .tk-cookie-consent, .tk-cookie-modal {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        box-sizing: border-box;
    }

    .tk-cookie-consent *, .tk-cookie-consent *::before, .tk-cookie-consent *::after,
    .tk-cookie-modal *, .tk-cookie-modal *::before, .tk-cookie-modal *::after {
        box-sizing: inherit;
    }

    .tk-cookie-consent {
        display: none;
        position: fixed;
        bottom: 24px;
        left: 24px;
        z-index: 999999;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        border-radius: 24px;
        padding: 24px;
        width: calc(100% - 48px);
        max-width: 460px;
    }

    .tk-cookie-consent-text {
        font-size: 14px;
        line-height: 1.5;
        margin: 0 0 24px 0;
        color: #475569;
    }

    .tk-cookie-consent-text strong {
        color: #1e293b;
        font-weight: 700;
    }

    .tk-cookie-consent-link {
        color: #1e293b;
        font-weight: 700;
        text-decoration: underline;
        text-decoration-thickness: 1px;
        text-underline-offset: 2px;
    }

    .tk-cookie-consent-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 12px;
    }

    .tk-cookie-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: none;
        cursor: pointer;
        font-size: 14px;
        line-height: 20px;
        height: 36px;
        padding: 8px 12px;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .tk-cookie-btn-pref {
        background: transparent;
        border-bottom: 1px solid #1e293b;
        border-radius: 0;
        padding: 0 4px;
        height: auto;
        color: #1e293b;
        font-weight: 700;
    }
    
    .tk-cookie-btn-pref:hover {
        opacity: 0.7;
    }

    .tk-cookie-btn-reject {
        background: #f1f5f9;
        color: #1e293b;
        font-weight: 700;
    }
    
    .tk-cookie-btn-reject:hover {
        background: #e2e8f0;
    }

    .tk-cookie-btn-accept {
        background: #1e293b;
        color: #ffffff;
        font-weight: 700;
    }
    
    .tk-cookie-btn-accept:hover {
        background: #0f172a;
    }

    /* Modal Styles */
    .tk-cookie-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.5);
        z-index: 999999;
        backdrop-filter: blur(4px);
    }

    .tk-cookie-modal {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: calc(100% - 48px);
        max-width: 500px;
        background: #ffffff;
        border-radius: 16px;
        z-index: 1000000;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        display: none;
        flex-direction: column;
        max-height: calc(100vh - 48px);
    }
    .tk-cookie-modal.tk-show {
        display: flex;
    }
    .tk-cookie-modal-overlay.tk-show {
        display: block;
    }
    .tk-cookie-consent.tk-show {
        display: block;
    }
    .tk-cookie-floating-btn.tk-show {
        display: flex;
    }

    .tk-cookie-modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .tk-cookie-modal-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
        color: #1e293b;
    }

    .tk-cookie-modal-close {
        background: transparent;
        border: none;
        font-size: 24px;
        line-height: 1;
        color: #64748b;
        cursor: pointer;
        padding: 0;
    }
    
    .tk-cookie-modal-close:hover {
        color: #0f172a;
    }

    .tk-cookie-modal-body {
        padding: 20px 24px;
        overflow-y: auto;
    }

    .tk-cookie-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        padding-bottom: 20px;
        margin-bottom: 20px;
        border-bottom: 1px solid #e2e8f0;
    }

    .tk-cookie-item:last-child {
        padding-bottom: 0;
        margin-bottom: 0;
        border-bottom: none;
    }

    .tk-cookie-item-info h4 {
        margin: 0 0 4px 0;
        font-size: 15px;
        font-weight: 600;
        color: #1e293b;
    }

    .tk-cookie-item-info p {
        margin: 0;
        font-size: 13px;
        color: #64748b;
        line-height: 1.5;
    }

    .tk-cookie-item-toggle {
        flex: 0 0 auto;
        padding-top: 2px;
    }

    /* Switch CSS */
    .tk-cookie-switch {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
    }

    .tk-cookie-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .tk-cookie-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #cbd5e1;
        transition: .4s;
        border-radius: 24px;
    }

    .tk-cookie-slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }

    .tk-cookie-switch input:checked + .tk-cookie-slider {
        background-color: #1e293b;
    }

    .tk-cookie-switch input:focus + .tk-cookie-slider {
        box-shadow: 0 0 1px #1e293b;
    }

    .tk-cookie-switch input:checked + .tk-cookie-slider:before {
        transform: translateX(20px);
    }

    .tk-cookie-modal-footer {
        padding: 20px 24px;
        border-top: 1px solid #e2e8f0;
        background: #f8fafc;
        border-bottom-left-radius: 16px;
        border-bottom-right-radius: 16px;
    }

    @media (max-width: 480px) {
        .tk-cookie-consent {
            bottom: 16px;
            left: 16px;
            width: calc(100% - 32px);
            padding: 20px;
        }
        
        .tk-cookie-consent-actions {
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .tk-cookie-btn {
            flex: 1 1 auto;
            justify-content: center;
        }
        
        .tk-cookie-btn-pref {
            flex: 0 0 100%;
            margin-bottom: 8px;
            padding: 8px;
            text-align: center;
            border: none;
            text-decoration: underline;
        }

        .tk-cookie-modal {
            width: calc(100% - 32px);
        }
    }

    .tk-cookie-floating-btn {
        display: none;
        position: fixed;
        bottom: 24px;
        left: 24px;
        z-index: 999998;
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #1e293b;
        transition: all 0.2s;
    }
    .tk-cookie-floating-btn:hover {
        background: #f8fafc;
        transform: scale(1.05);
    }
    </style>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var banner = document.getElementById('tk-cookie-consent-banner');
        var modal = document.getElementById('tk-cookie-modal');
        var overlay = document.getElementById('tk-cookie-modal-overlay');
        
        if (!banner) return;

        var expiryDays = <?php echo absint($expiry_days); ?>;
        var cookieName = 'tk_cookie_consent';

        function setCookie(name, value, days) {
            var expires = "";
            if (days) {
                var date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            document.cookie = name + "=" + (value || "")  + expires + "; path=/; SameSite=Lax";
        }

        function getCookie(name) {
            var nameEQ = name + "=";
            var ca = document.cookie.split(';');
            for(var i=0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0)==' ') c = c.substring(1,c.length);
                if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
            }
            return null;
        }

        // Persistent floating button
        var floatingBtn = document.createElement('button');
        floatingBtn.id = 'tk-cookie-floating-btn';
        floatingBtn.className = 'tk-cookie-floating-btn';
        floatingBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01"/><path d="M16 12.5v.01"/><path d="M12 16v.01"/><path d="M11 12.5v.01"/></svg>';
        floatingBtn.setAttribute('aria-label', 'Cookie Preferences');
        document.body.appendChild(floatingBtn);
        floatingBtn.style.display = 'none';

        function openModal(e) {
            if (e) e.preventDefault();
            modal.classList.add('tk-show');
            overlay.classList.add('tk-show');
        }

        // Event listeners for opening modal
        document.getElementById('tk-cookie-btn-pref').addEventListener('click', openModal);
        floatingBtn.addEventListener('click', openModal);

        if (getCookie(cookieName)) {
            // Already acted, show floating button instead of banner
            floatingBtn.classList.add('tk-show');
            return;
        }

        // Show banner, hide floating button initially
        banner.classList.add('tk-show');

        // Banner Actions
        document.getElementById('tk-cookie-btn-accept').addEventListener('click', function(e) {
            e.preventDefault();
            setCookie(cookieName, JSON.stringify({analytics: true, marketing: true}), expiryDays);
            banner.classList.remove('tk-show');
            floatingBtn.classList.add('tk-show');
        });

        document.getElementById('tk-cookie-btn-reject').addEventListener('click', function(e) {
            e.preventDefault();
            setCookie(cookieName, JSON.stringify({analytics: false, marketing: false}), expiryDays);
            banner.classList.remove('tk-show');
            floatingBtn.classList.add('tk-show');
        });

        // Modal Actions
        document.getElementById('tk-cookie-modal-close').addEventListener('click', function() {
            modal.classList.remove('tk-show');
            overlay.classList.remove('tk-show');
        });
        
        overlay.addEventListener('click', function() {
            modal.classList.remove('tk-show');
            overlay.classList.remove('tk-show');
        });

        document.getElementById('tk-cookie-btn-save-pref').addEventListener('click', function() {
            var allowAnalytics = document.getElementById('tk-cookie-pref-analytics').checked;
            var allowMarketing = document.getElementById('tk-cookie-pref-marketing').checked;
            
            setCookie(cookieName, JSON.stringify({analytics: allowAnalytics, marketing: allowMarketing}), expiryDays);
            
            modal.classList.remove('tk-show');
            overlay.classList.remove('tk-show');
            banner.classList.remove('tk-show');
            floatingBtn.classList.add('tk-show');
        });
    });
    </script>
    <?php
}
