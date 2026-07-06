{{--
    Platform SMTP test email. Static developer-authored template — no dynamic/merchant
    text at all. Inline CSS is the sanctioned exception for email HTML (clients strip
    <style>). All copy goes through __() (en/he 1:1).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('platform.settings.smtp.test_mail_dir') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('platform.settings.smtp.test_mail_subject') }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;padding:32px;max-width:480px;">
                    <tr>
                        <td style="font-size:18px;font-weight:bold;padding-bottom:12px;">
                            {{ __('platform.settings.smtp.test_mail_heading') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:14px;line-height:22px;color:#3f3f46;">
                            {{ __('platform.settings.smtp.test_mail_body') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
