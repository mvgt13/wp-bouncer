<?php
$language    = get_bloginfo('language');
$charset     = get_bloginfo('charset');
$name        = get_bloginfo('name');
$heading     = get_option('bouncer_heading', 'COMING SOON');
$text_en     = get_option('bouncer_text_en', 'This site is still in the works and will be live soon.');
$text_de     = get_option('bouncer_text_de', 'Diese Seite befindet sich noch im Aufbau und geht bald online.');
$email       = get_option('bouncer_email', '');
$mode        = get_option('bouncer_mode', 'maintenance');
$retry_after = (int) get_option('bouncer_retry_after', 3600);

$bilingual        = (bool) get_option('bouncer_bilingual', false);
$show_dark_mode   = (bool) get_option('bouncer_show_dark_mode', false);
$show_website_btn = (bool) get_option('bouncer_show_website_btn', false);
$website_url      = get_option('bouncer_website_url', '');
$website_domain   = $website_url ? parse_url($website_url, PHP_URL_HOST) : '';

// Contact email is never emitted verbatim. Spam harvesters scrape `mailto:`
// links and bare `user@host` strings straight out of the HTML source, so the
// address is reversed + base64'd into a data attribute and rehydrated into a
// real `mailto:` by the inline script at the end of <body>. No-JS visitors get
// a readable "user [at] host" hint via <noscript>.
$email_payload = $email ? base64_encode(strrev($email)) : '';
list($email_user, $email_host) = $email
    ? array_pad(explode('@', $email, 2), 2, '')
    : ['', ''];

$render_email_link = static function (string $label) use ($email_payload, $email_user, $email_host) {
    if ($email_payload === '') {
        return '';
    }
    return sprintf(
        '<a href="#" class="link js-bouncer-email" data-e="%s" rel="nofollow noopener">%s</a><noscript> (%s [at] %s)</noscript>',
        esc_attr($email_payload),
        esc_html($label),
        esc_html($email_user),
        esc_html($email_host)
    );
};

$retry_hours   = floor($retry_after / 3600);
$retry_minutes = floor(($retry_after % 3600) / 60);
$retry_display = $retry_hours > 0
    ? $retry_hours . 'h' . ($retry_minutes > 0 ? ' ' . $retry_minutes . 'm' : '')
    : $retry_minutes . 'm';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr($language); ?>">
<head>
    <meta charset="<?php echo esc_attr($charset); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html($name); ?> — <?php echo esc_html($heading); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Azeret+Mono:wght@400;500;600;700&family=Libre+Franklin:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Color tokens — light defaults, dark via media query, explicit class overrides both */
        :root { --bg: #f5f5f0; --fg: #1a1a1a; --link: #4169e1; }

        @media (prefers-color-scheme: dark) {
            :root { --bg: #1a1a1a; --fg: #f5f5f0; --link: #6495ed; }
        }

        body.dark-mode  { --bg: #1a1a1a; --fg: #f5f5f0; --link: #6495ed; }
        body.light-mode { --bg: #f5f5f0; --fg: #1a1a1a; --link: #4169e1; }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Azeret Mono', monospace;
            background-color: var(--bg);
            color: var(--fg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            overflow: hidden;
            transition: background-color .3s ease, color .3s ease;
        }

        .container {
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding-right: 1rem;
        }

        .header { text-align: center; margin-bottom: 2rem; }

        .logo {
            font-family: 'Libre Franklin', sans-serif;
            font-weight: 700;
            font-size: 4rem;
            letter-spacing: -0.05em;
            margin-bottom: 1.5rem;
            line-height: 0.9;
        }

        .content {
            font-size: .95rem;
            line-height: 1.7;
            text-align: <?php echo $bilingual ? 'left' : 'center'; ?>;
        }

        .link { color: var(--link); text-decoration: underline; cursor: pointer; }

        <?php if ($show_dark_mode): ?>
        .toggle-container { position: fixed; top: 2rem; right: 2rem; z-index: 100; }
        .toggle-btn {
            background-color: var(--fg);
            color: var(--bg);
            border: none;
            padding: .75rem 1.5rem;
            font-family: 'Azeret Mono', monospace;
            font-size: .9rem;
            cursor: pointer;
            border-radius: 2rem;
            transition: transform .3s ease;
        }
        .toggle-btn:hover { transform: scale(1.05); }
        <?php endif; ?>

        <?php if ($show_website_btn): ?>
        .website-btn-container { position: fixed; bottom: 2rem; right: 2rem; z-index: 100; }
        .website-btn {
            background-color: transparent;
            color: var(--fg);
            border: 2px solid var(--fg);
            padding: .75rem 1.5rem;
            font-family: 'Azeret Mono', monospace;
            font-size: .9rem;
            cursor: pointer;
            border-radius: 2rem;
            transition: all .3s ease;
            text-decoration: none;
            display: inline-block;
        }
        .website-btn:hover { transform: scale(1.05); background-color: var(--fg); color: var(--bg); }
        <?php endif; ?>

        .footer {
            position: fixed; bottom: 0; left: 0; right: 0;
            padding: 1rem 2rem; text-align: center;
            font-size: .75rem; opacity: .5; z-index: 50;
        }
        .footer .status-code { font-weight: 600; }

        @media (max-width: 768px) {
            body { overflow: auto; }
            .container { margin-top: <?php echo $show_dark_mode ? '5rem' : '2rem'; ?>; max-height: none; overflow-y: visible; }
            .logo { font-size: 3.5rem; }
            .content { font-size: 1.1rem; }
            <?php if ($show_dark_mode): ?>
            .toggle-container { top: 1rem; right: 1rem; }
            .toggle-btn { padding: .6rem 1.2rem; font-size: .85rem; }
            <?php endif; ?>
            <?php if ($show_website_btn): ?>
            .website-btn-container { bottom: 4rem; right: 1rem; }
            .website-btn { padding: .6rem 1.2rem; font-size: .85rem; }
            <?php endif; ?>
        }

        @media (max-width: 480px) {
            body { padding: 1rem; }
            .container { margin-top: <?php echo $show_dark_mode ? '4.5rem' : '1.5rem'; ?>; }
            .logo { font-size: 2.5rem; }
            .content { font-size: 1rem; }
            .footer { font-size: .65rem; padding: .75rem 1rem; }
        }
    </style>
</head>
<body>
    <?php if ($show_dark_mode): ?>
    <div class="toggle-container">
        <button class="toggle-btn" onclick="toggleDarkMode()" id="toggle-btn" aria-label="Toggle dark mode">
            <span id="mode-text">Dark Mode</span>
        </button>
    </div>
    <?php endif; ?>

    <?php if ($show_website_btn && $website_url): ?>
    <div class="website-btn-container">
        <a href="<?php echo esc_url($website_url); ?>" class="website-btn" target="_blank" rel="noopener noreferrer">
            <?php echo esc_html($website_domain); ?>
        </a>
    </div>
    <?php endif; ?>

    <div class="container">
        <div class="header">
            <div class="logo"><?php echo esc_html($heading); ?></div>
        </div>

        <div class="content">
            <?php if ($bilingual): ?>
                <p>
                    <?php echo wp_kses_post($text_en); ?>
                    <?php if ($email): ?> <?php _e('If you want to know more, feel free to', 'bouncer'); ?> <?php echo $render_email_link(__('reach out', 'bouncer')); ?>.<?php endif; ?>
                </p>
                <br><br>
                <p>
                    <?php echo wp_kses_post($text_de); ?>
                    <?php if ($email): ?> <?php _e('If you want to know more, feel free to', 'bouncer'); ?> <?php echo $render_email_link(__('reach out', 'bouncer')); ?>.<?php endif; ?>
                </p>
            <?php else: ?>
                <p>
                    <?php echo wp_kses_post($text_en); ?>
                    <?php if ($email): ?> <?php echo $render_email_link(__('Get in touch', 'bouncer')); ?>.<?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        <?php if ($mode === 'coming_soon'): ?>
            <span class="status-code">HTTP 200</span> — Coming Soon
        <?php else: ?>
            <span class="status-code">HTTP 503</span> — Maintenance · Retry After: <?php echo esc_html($retry_display); ?>
        <?php endif; ?>
    </div>

    <?php if ($show_dark_mode): ?>
    <script>
        function isDark() {
            return document.body.classList.contains('dark-mode') ||
                (!document.body.classList.contains('light-mode') && window.matchMedia('(prefers-color-scheme: dark)').matches);
        }

        function toggleDarkMode() {
            if (isDark()) {
                document.body.classList.replace('dark-mode', 'light-mode') || document.body.classList.add('light-mode');
                document.body.classList.remove('dark-mode');
                document.getElementById('mode-text').textContent = 'Dark Mode';
                localStorage.setItem('darkMode', 'disabled');
            } else {
                document.body.classList.replace('light-mode', 'dark-mode') || document.body.classList.add('dark-mode');
                document.body.classList.remove('light-mode');
                document.getElementById('mode-text').textContent = 'Light Mode';
                localStorage.setItem('darkMode', 'enabled');
            }
        }

        // Apply saved preference before first paint
        (function () {
            var saved = localStorage.getItem('darkMode');
            if (saved === 'enabled') {
                document.body.classList.add('dark-mode');
            } else if (saved === 'disabled') {
                document.body.classList.add('light-mode');
            }
            // No class needed if null — CSS media query handles it
            document.getElementById('mode-text').textContent = isDark() ? 'Light Mode' : 'Dark Mode';
        })();
    </script>
    <?php endif; ?>

    <?php if ($email): ?>
    <script>
        // Rehydrate the obfuscated contact address into a working mailto: link.
        (function () {
            var links = document.querySelectorAll('.js-bouncer-email');
            for (var i = 0; i < links.length; i++) {
                (function (el) {
                    var raw = el.getAttribute('data-e');
                    if (!raw) return;
                    var addr;
                    try {
                        addr = atob(raw).split('').reverse().join('');
                    } catch (e) {
                        return;
                    }
                    if (addr.indexOf('@') === -1) return;
                    el.setAttribute('href', 'mailto:' + addr);
                    el.removeAttribute('data-e');
                })(links[i]);
            }
        })();
    </script>
    <?php endif; ?>
</body>
</html>
