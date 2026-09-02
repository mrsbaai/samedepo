<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title')</title>

        <style>
            html, body {
                background-color: #18181b;
                color: #ffb900;
                font-family: 'Geist', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
                height: 100%;
                margin: 0;
            }

            .container {
                align-items: center;
                display: flex;
                justify-content: center;
                min-height: 100%;
            }

            .content {
                align-items: center;
                display: flex;
                gap: 1rem;
                padding: 1.5rem;
            }

            .code {
                border-right: 1px solid rgba(255, 185, 0, 0.4);
                font-size: 1.5rem;
                font-weight: 600;
                padding-right: 1rem;
            }

            .message {
                font-size: 1.125rem;
                font-weight: 500;
                letter-spacing: 0.05em;
                text-transform: uppercase;
            }

            @media (max-width: 640px) {
                .content {
                    flex-direction: column;
                    text-align: center;
                }

                .code {
                    border-right: none;
                    border-bottom: 1px solid rgba(255, 185, 0, 0.4);
                    padding-right: 0;
                    padding-bottom: 0.75rem;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="content">
                <div class="code">
                    @yield('code')
                </div>

                <div class="message">
                    @yield('message')
                </div>
            </div>
        </div>
    </body>
</html>
